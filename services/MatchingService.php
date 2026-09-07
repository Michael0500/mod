<?php

namespace app\services;

use Yii;
use app\models\NostroEntry;
use app\models\MatchingRule;
use app\models\Account;
use app\models\AccountPool;

/**
 * Сервис квитования записей Ностро.
 *
 * Сервис инкапсулирует ручное квитование, расквитование, пакетное
 * автоквитование и пошаговое автоквитование для UI. Все операции должны
 * выполняться в рамках `company_id`, чтобы не смешивать данные разных компаний.
 *
 * Автоквитование строит SQL по активным правилам из `matching_rules`, ищет
 * уникальные пары в пределах ностро-банка и присваивает общий `match_id`.
 * Ручное квитование проверяет баланс набора записей перед сменой статуса.
 */
class MatchingService
{
    // ── Ручное квитование ─────────────────────────────────────────────

    /**
     * Сквитовать набор записей вручную.
     *
     * Для NRE (L+S):
     *   - сумма Ledger = сумма Statement (если разница != 0 — предупреждение)
     *   - минимум 2 записи
     *
     * Для INV (только Ledger):
     *   - балансировка по Debit/Credit (сумма D = сумма C)
     *   - если разница D/C = 0 — разрешена даже 1 запись (например, сумма = 0)
     *
     * Побочный эффект: в одной транзакции присваивает общий `match_id`,
     * переводит записи в статус `M`, заполняет `matched_at/updated_at/updated_by`.
     *
     * @param int[] $ids Массив ID незаквитованных `NostroEntry`.
     * @param string|null $section Раздел `NRE` или `INV`; `null` использует NRE-логику.
     * @param int|null $companyId Если задан, ограничивает квитование одной компанией.
     * @return array Результат операции: `success`, `match_id`, `count`, `message` и возможное `warning/diff`.
     */
    public function matchManual(array $ids, ?string $section = null, ?int $companyId = null): array
    {
        $isInv = ($section === 'INV');
        $ids = array_values(array_unique(array_map('intval', $ids)));

        $query = NostroEntry::find()
            ->where(['id' => $ids, 'match_status' => NostroEntry::STATUS_UNMATCHED]);
        if ($companyId !== null) {
            $query->andWhere(['company_id' => $companyId]);
        }
        $entries = $query->all();

        if (empty($entries)) {
            return ['success' => false, 'message' => 'Незаквитованные записи не найдены'];
        }
        if (count($entries) !== count($ids)) {
            return ['success' => false, 'message' => 'Часть записей недоступна или уже сквитована'];
        }

        // Проверка единства валюты — нельзя квитовать записи в разных валютах
        if (count($entries) > 1) {
            $currencies = [];
            foreach ($entries as $e) {
                $currencies[strtoupper((string)$e->currency)] = true;
            }
            if (count($currencies) > 1) {
                return [
                    'success' => false,
                    'message' => 'Нельзя квитовать записи в разных валютах: ' . implode(', ', array_keys($currencies)),
                ];
            }
        }

        // Проверка единства ностро-банка — нельзя квитовать записи из разных банков.
        // Берём pool_id всех счетов, по которым относятся выбранные записи.
        if (count($entries) > 1) {
            $accountIds = [];
            foreach ($entries as $e) {
                $accountIds[(int)$e->account_id] = true;
            }
            $poolIds = Account::find()
                ->select(['pool_id'])
                ->where(['id' => array_keys($accountIds)])
                ->distinct()
                ->column();
            $poolIds = array_values(array_unique(array_map(function ($v) {
                return $v === null ? null : (int)$v;
            }, $poolIds)));
            if (count($poolIds) > 1 || in_array(null, $poolIds, true)) {
                return [
                    'success' => false,
                    'message' => 'Нельзя квитовать записи из разных ностро-банков',
                ];
            }
        }

        // Для 1 записи: разрешаем только если сумма = 0
        if (count($entries) === 1) {
            if (round((float) $entries[0]->amount, 2) !== 0.0) {
                return ['success' => false, 'message' => 'Выберите минимум 2 записи (одиночная запись должна иметь сумму 0)'];
            }
            // amount = 0 → квитуем без дополнительных проверок
        } elseif ($isInv) {
            // INV (2+ записей): проверяем баланс по Debit/Credit
            $sumDebit  = 0.0;
            $sumCredit = 0.0;
            foreach ($entries as $e) {
                if ($e->dc === NostroEntry::DC_DEBIT) {
                    $sumDebit  += (float) $e->amount;
                } else {
                    $sumCredit += (float) $e->amount;
                }
            }
            $diffDC = round(abs($sumDebit - $sumCredit), 2);
            if ($diffDC > 0) {
                return [
                    'success' => false,
                    'warning' => true,
                    'diff'    => $diffDC,
                    'message' => 'Дисбаланс Debit/Credit. Разница: ' . number_format($diffDC, 2, '.', ',')
                ];
            }
        } else {
            // NRE (2+ записей): проверяем баланс по L/S
            $sumLedger    = 0.0;
            $sumStatement = 0.0;
            $hasLedger    = false;
            $hasStatement = false;

            foreach ($entries as $e) {
                $signed = ($e->dc === NostroEntry::DC_DEBIT) ? $e->amount : -$e->amount;
                if ($e->ls === NostroEntry::LS_LEDGER) {
                    $sumLedger += $signed;
                    $hasLedger  = true;
                } else {
                    $sumStatement += $signed;
                    $hasStatement = true;
                }
            }

            if ($hasLedger && $hasStatement) {
                $diff = round(abs($sumLedger + $sumStatement), 2);
                if ($diff > 0) {
                    return [
                        'success' => false,
                        'warning' => true,
                        'diff'    => $diff,
                        'message' => 'Суммы не сбалансированы. Разница: ' . number_format($diff, 2, '.', ',')
                    ];
                }
            }
        }

        $matchId = $this->generateMatchId();
        $now     = date('Y-m-d H:i:s');
        $userId  = Yii::$app->user->id;

        $transaction = Yii::$app->db->beginTransaction();
        try {
            foreach ($entries as $e) {
                $e->match_id     = $matchId;
                $e->match_status = NostroEntry::STATUS_MATCHED;
                $e->matched_at   = $now;
                $e->updated_at   = $now;
                $e->updated_by   = $userId;
                $e->save(false);
            }
            $transaction->commit();
        } catch (\Exception $ex) {
            $transaction->rollBack();
            return ['success' => false, 'message' => 'Ошибка БД: ' . $ex->getMessage()];
        }

        return [
            'success'  => true,
            'match_id' => $matchId,
            'count'    => count($entries),
            'message'  => 'Сквитовано записей: ' . count($entries) . '. Match ID: ' . $matchId,
        ];
    }

    /**
     * Расквитовывает все активные записи с указанным `match_id`.
     *
     * Побочный эффект: массовым SQL-обновлением очищает `match_id/matched_at`,
     * возвращает статус `U` и обновляет служебные поля изменения. Если передан
     * `companyId`, операция ограничена одной компанией.
     *
     * @param string $matchId Идентификатор группы квитования.
     * @param int|null $companyId ID компании для tenant-ограничения.
     * @return array Результат операции с количеством изменённых записей.
     */
    public function unmatch(string $matchId, ?int $companyId = null): array
    {
        $condition = ['match_id' => $matchId];
        if ($companyId !== null) {
            $condition['company_id'] = $companyId;
        }

        $count = NostroEntry::updateAll(
            [
                'match_id'     => null,
                'match_status' => NostroEntry::STATUS_UNMATCHED,
                'matched_at'   => null,
                'updated_at'   => date('Y-m-d H:i:s'),
                'updated_by'   => Yii::$app->user->id,
            ],
            $condition
        );

        if ($count === 0) {
            return ['success' => false, 'message' => 'Записи с Match ID ' . $matchId . ' не найдены'];
        }

        return ['success' => true, 'count' => $count, 'message' => 'Расквитовано записей: ' . $count];
    }

    // ── Автоматическое квитование ─────────────────────────────────────

    /**
     * Запускает автоквитование по всем активным правилам.
     *
     * Используется консольной командой и синхронными API-вызовами. Правила
     * выполняются по приоритету; ошибка отдельного правила логируется и не
     * останавливает обработку следующих правил.
     *
     * @param int $companyId ID компании.
     * @param int|null $accountId ID конкретного счёта или `null` для всех счетов.
     * @param callable|null $onProgress Callback `(int $ruleIndex, string $ruleName, int $matchedInRule, int $totalMatched)`.
     * @param string|null $section Фильтр раздела `NRE` или `INV`.
     * @return array Итог автоквитования: `success`, `matched`, `rules_count`, `message`.
     */
    public function autoMatch(int $companyId, ?int $accountId = null, ?callable $onProgress = null, ?string $section = null): array
    {
        $where = ['company_id' => $companyId, 'is_active' => true];
        if ($section) {
            $where['section'] = $section;
        }
        $rules = MatchingRule::find()
            ->where($where)
            ->orderBy(['priority' => SORT_ASC])
            ->all();

        if (empty($rules)) {
            return ['success' => false, 'message' => 'Нет активных правил квитования'];
        }

        $totalMatched = 0;
        $errors       = [];

        foreach ($rules as $i => $rule) {
            try {
                $matched = $this->runRule($rule, $companyId, $accountId);
                $totalMatched += $matched;

                if ($onProgress) {
                    $onProgress($i + 1, $rule->name, $matched, $totalMatched);
                }
            } catch (\Exception $e) {
                $errors[] = 'Правило "' . $rule->name . '": ' . $e->getMessage();
                Yii::error('MatchingService rule #' . $rule->id . ': ' . $e->getMessage());
            }
        }

        $message = 'Автоквитование завершено. Сквитовано пар: ' . $totalMatched;
        if ($errors) {
            $message .= '. Ошибки: ' . implode('; ', $errors);
        }

        return [
            'success'     => true,
            'matched'     => $totalMatched,
            'rules_count' => count($rules),
            'message'     => $message,
        ];
    }

    // ── Пошаговое автоквитование (для UI с прогрессом) ──────────────

    /**
     * Разрешает область запуска автоквитования в список `account_id`.
     *
     * `scopeType` принимает `all`, `pool` или `category`. `null` означает,
     * что ограничения по счетам нет, а пустой массив означает выбранную область
     * без подходящих счетов.
     *
     * @param int $companyId ID компании.
     * @param string $scopeType Тип области: `all`, `pool` или `category`.
     * @param int|null $scopeId ID ностро-банка или категории для выбранной области.
     * @return int[]|null Список ID счетов, `null` без ограничения.
     */
    public function resolveScopeAccounts(int $companyId, string $scopeType, ?int $scopeId): ?array
    {
        if ($scopeType === 'all' || !$scopeId) {
            return null; // без ограничений
        }

        if ($scopeType === 'pool') {
            return Account::find()
                ->select('id')
                ->where(['pool_id' => $scopeId, 'company_id' => $companyId])
                ->column();
        }

        if ($scopeType === 'category') {
            $poolIds = AccountPool::find()
                ->select('id')
                ->where(['category_id' => $scopeId, 'company_id' => $companyId])
                ->column();
            if (empty($poolIds)) return [];

            return Account::find()
                ->select('id')
                ->where(['pool_id' => $poolIds, 'company_id' => $companyId])
                ->column();
        }

        return null;
    }

    /** Стоп-кран от протухших заданий: running без активности дольше — реклеймится. */
    private const JOB_STALE_SECONDS = 120;
    /** Сколько пар обновляется за один шаг (≈ один HTTP-вызов). */
    private const STEP_UPDATE_PAIRS = 8000;

    /**
     * Инициализирует пошаговое автоквитование с живым прогрессом (вариант B).
     *
     * Создаёт DB-задание `automatch_jobs` (состояние машины + счётчики прогресса)
     * и захватывает замок от двойного запуска: частичный уникальный индекс
     * `(company_id) WHERE status='running'` не даёт запустить второе квитование
     * по той же компании. Перед этим реклеймятся протухшие running-задания
     * (брошенный браузер). Следующие шаги выполняет `autoMatchStep()`.
     *
     * @param int $companyId ID компании.
     * @param int|null $accountId ID конкретного счёта или `null`.
     * @param string|null $section Фильтр раздела `NRE` или `INV`.
     * @param string $scopeType Область запуска: `all`, `pool`, `category`.
     * @param int|null $scopeId ID области запуска.
     * @return array Данные задания: `job_id`, список правил, число незаквитованных
     *               или отказ (нет правил / нет счетов / уже выполняется).
     * @throws \Exception Если не удалось сгенерировать случайный `job_id`.
     */
    public function autoMatchStart(int $companyId, ?int $accountId = null, ?string $section = null, string $scopeType = 'all', ?int $scopeId = null): array
    {
        $where = ['company_id' => $companyId, 'is_active' => true];
        if ($section) {
            $where['section'] = $section;
        }
        $rules = MatchingRule::find()
            ->where($where)
            ->orderBy(['priority' => SORT_ASC])
            ->all();

        if (empty($rules)) {
            return ['success' => false, 'message' => 'Нет активных правил квитования'];
        }

        // Разрешаем scope в список account_id
        $scopeAccountIds = $this->resolveScopeAccounts($companyId, $scopeType, $scopeId);

        // Считаем незаквитованные записи (знаменатель прогресса)
        $query = NostroEntry::find()
            ->where(['company_id' => $companyId, 'match_status' => NostroEntry::STATUS_UNMATCHED]);
        if ($scopeAccountIds !== null) {
            if (empty($scopeAccountIds)) return ['success' => false, 'message' => 'Нет счетов в выбранной области'];
            $query->andWhere(['account_id' => $scopeAccountIds]);
        } elseif ($accountId) {
            $query->andWhere(['account_id' => $accountId]);
        }
        $unmatchedCount = (int) $query->count();

        $db = Yii::$app->db;
        $this->reclaimStaleJobs($companyId);

        // Предчек замка: не пытаемся вставлять, если задание уже идёт — так в
        // обычном случае не бросаем IntegrityException (он испортил бы внешнюю
        // транзакцию в тестовой среде). Уникальный индекс остаётся жёстким
        // бэкстопом на случай гонки двух одновременных стартов.
        $active = $db->createCommand(
            "SELECT 1 FROM {{%automatch_jobs}} WHERE company_id = :c AND status = 'running' LIMIT 1",
            [':c' => $companyId]
        )->queryScalar();
        if ($active) {
            return [
                'success'         => false,
                'already_running' => true,
                'message'         => 'Автоквитование для этой компании уже выполняется. Дождитесь завершения или остановите текущий запуск.',
            ];
        }

        $jobId = 'job_' . bin2hex(random_bytes(8));
        $now   = date('Y-m-d H:i:s');

        $rulesInfo = array_map(fn(MatchingRule $r) => ['id' => $r->id, 'name' => $r->name], $rules);

        $state = [
            'company_id'        => $companyId,
            'account_id'        => $accountId,
            'section'           => $section,
            'scope_account_ids' => $scopeAccountIds,
            'rule_ids'          => array_map(fn($r) => (int) $r->id, $rules),
            'current_rule'      => 0,           // индекс текущего правила
            'rule_pools'        => null,        // пулы текущего правила (резолвятся лениво)
            'pool_index'        => 0,
            'phase'             => 'search',    // search | update
            'pool_pair_total'   => 0,
            'pool_offset'       => 0,
            'rule_matched'      => 0,
            'total_matched'     => 0,
            'step_results'      => [],
            'display_phase'     => 'searching',
            'current_rule_name' => $rules[0]->name,
        ];

        try {
            $db->createCommand()->insert('{{%automatch_jobs}}', [
                'job_id'          => $jobId,
                'company_id'      => $companyId,
                'status'          => 'running',
                'phase'           => 'searching',
                'current_rule_name' => $rules[0]->name,
                'matched_pairs'   => 0,
                'total_unmatched' => $unmatchedCount,
                'state'           => $state, // json-колонка: Yii сам кодирует массив
                'started_at'      => $now,
                'updated_at'      => $now,
            ])->execute();
        } catch (\yii\db\IntegrityException $e) {
            // Сработал частичный уникальный индекс — уже есть running-задание компании.
            return [
                'success'        => false,
                'already_running'=> true,
                'message'        => 'Автоквитование для этой компании уже выполняется. Дождитесь завершения или остановите текущий запуск.',
            ];
        }

        return [
            'success'         => true,
            'job_id'          => $jobId,
            'rules'           => $rulesInfo,
            'total_steps'     => count($rules),
            'unmatched_count' => $unmatchedCount,
            'total_matched'   => 0,
            'matched_pairs'   => 0,
            'percent'         => 0,
        ];
    }

    /**
     * Выполняет один ограниченный шаг пошагового автоквитования.
     *
     * За вызов делается ровно одна «тяжёлая» операция БД — либо материализация
     * пар текущего пула в рабочую таблицу `automatch_pairs`, либо обновление
     * очередной порции из {@see STEP_UPDATE_PAIRS} пар. Так каждый HTTP-вызов
     * короткий (~1–3 с), а UI обновляет прогресс и не выглядит зависшим.
     * Состояние машины и счётчики хранятся в строке `automatch_jobs`.
     *
     * @param string $jobId ID задания, полученный из `autoMatchStart()`.
     * @return array Прогресс: сквитовано/осталось/процент/фаза/итоги по правилам.
     */
    public function autoMatchStep(string $jobId): array
    {
        $db  = Yii::$app->db;
        $row = $db->createCommand('SELECT * FROM {{%automatch_jobs}} WHERE job_id = :j', [':j' => $jobId])->queryOne();

        if (!$row) {
            return ['success' => false, 'message' => 'Задание не найдено или истекло'];
        }

        // json-колонка может вернуться как массив (если Yii уже декодировал) или
        // как строка (raw-запрос) — обрабатываем оба случая.
        $state = is_array($row['state'])
            ? $row['state']
            : (json_decode((string) $row['state'], true) ?: []);

        // Уже завершено/остановлено — идемпотентный ответ.
        if ($row['status'] !== 'running') {
            return $this->progressResponse($row, $state, true);
        }

        $now = date('Y-m-d H:i:s');
        try {
            $this->advanceJob($state, $jobId);
        } catch (\Exception $e) {
            $db->createCommand()->update('{{%automatch_jobs}}', [
                'status'        => 'error',
                'error_message' => $e->getMessage(),
                'state'         => $state, // json-колонка: Yii сам кодирует массив
                'updated_at'    => $now,
                'finished_at'   => $now,
            ], ['job_id' => $jobId])->execute();
            $db->createCommand()->delete('{{%automatch_pairs}}', ['job_id' => $jobId])->execute();
            Yii::error("AutoMatch step {$jobId}: " . $e->getMessage());
            return ['success' => false, 'message' => 'Ошибка квитования: ' . $e->getMessage()];
        }

        $finished = $state['current_rule'] >= count($state['rule_ids']);
        $status   = $finished ? 'finished' : 'running';

        $update = [
            'status'            => $status,
            'phase'             => $finished ? 'finished' : ($state['display_phase'] ?? 'matching'),
            'current_rule_name' => $state['current_rule_name'] ?? null,
            'matched_pairs'     => (int) $state['total_matched'],
            'state'             => $state, // json-колонка: Yii сам кодирует массив
            'updated_at'        => $now,
        ];
        if ($finished) {
            $update['finished_at'] = $now;
        }
        $db->createCommand()->update('{{%automatch_jobs}}', $update, ['job_id' => $jobId])->execute();

        if ($finished) {
            $db->createCommand()->delete('{{%automatch_pairs}}', ['job_id' => $jobId])->execute();
        }

        $row = array_merge($row, $update);
        return $this->progressResponse($row, $state, $finished);
    }

    /**
     * Останавливает выполняемое задание автоквитования и освобождает замок.
     *
     * Уже сквитованные на момент остановки пары остаются сквитованными —
     * откатывается только незавершённый прогон (статус задания и рабочая
     * таблица пар).
     *
     * @param string $jobId ID задания.
     * @param int|null $companyId Ограничение по компании (tenant), если задано.
     * @return array Результат остановки.
     */
    public function autoMatchCancel(string $jobId, ?int $companyId = null): array
    {
        $db  = Yii::$app->db;
        $row = $db->createCommand('SELECT status, company_id FROM {{%automatch_jobs}} WHERE job_id = :j', [':j' => $jobId])->queryOne();
        if (!$row) {
            return ['success' => true, 'message' => 'Задание не найдено (возможно, уже завершено)'];
        }
        if ($companyId !== null && (int) $row['company_id'] !== $companyId) {
            return ['success' => false, 'message' => 'Нет доступа к заданию'];
        }

        $db->createCommand()->delete('{{%automatch_pairs}}', ['job_id' => $jobId])->execute();
        if ($row['status'] === 'running') {
            $now = date('Y-m-d H:i:s');
            $db->createCommand()->update('{{%automatch_jobs}}', [
                'status'      => 'canceled',
                'finished_at' => $now,
                'updated_at'  => $now,
            ], ['job_id' => $jobId])->execute();
        }
        return ['success' => true, 'message' => 'Автоквитование остановлено'];
    }

    /**
     * Выполняет одну ограниченную единицу работы задания (мутирует `$state`).
     *
     * Каждый вызов делает не более одной тяжёлой SQL-операции: материализацию
     * пар пула или обновление одной порции пар. Лёгкая «навигация» (старт
     * правила, завершение пула/правила) при необходимости выполняется и
     * заканчивается на ближайшей тяжёлой операции.
     *
     * @param array $state Состояние машины (по ссылке).
     * @param string $jobId ID задания (для рабочей таблицы пар).
     * @return void
     */
    private function advanceJob(array &$state, string $jobId): void
    {
        $db        = Yii::$app->db;
        $companyId = (int) $state['company_id'];
        $accountId = $state['account_id'] !== null ? (int) $state['account_id'] : null;
        $scopeIds  = $state['scope_account_ids'];

        if ($state['current_rule'] >= count($state['rule_ids'])) {
            return; // уже всё
        }

        $ruleId = (int) $state['rule_ids'][$state['current_rule']];
        $rule   = MatchingRule::findOne($ruleId);

        // Правило исчезло или без условий JOIN — закрываем как 0 пар.
        if (!$rule
            || (!$this->isGroupRule($rule) && empty($this->buildJoinConditions($rule)))
            || ($this->isGroupRule($rule) && !$rule->hasAnyMatchedIdField())
        ) {
            $state['step_results'][] = [
                'rule_id' => $ruleId, 'rule_name' => $rule ? $rule->name : '?', 'matched' => 0, 'error' => null,
            ];
            $this->advanceToNextRule($state);
            return;
        }

        $state['current_rule_name'] = $rule->name;

        // Лениво резолвим пулы правила (для правила конкретного банка — только его пул).
        if ($state['rule_pools'] === null) {
            $state['rule_pools']      = $this->resolvePoolIds($companyId, $accountId, $scopeIds, $rule->getEffectivePoolIds());
            $state['pool_index']      = 0;
            $state['phase']           = 'search';
            $state['rule_matched']    = 0;
            $state['pool_pair_total'] = 0;
            $state['pool_offset']     = 0;
            if (empty($state['rule_pools'])) {
                $state['step_results'][] = ['rule_id' => $ruleId, 'rule_name' => $rule->name, 'matched' => 0, 'error' => null];
                $this->advanceToNextRule($state);
                return;
            }
        }

        // Все пулы правила пройдены — закрываем правило.
        if ($state['pool_index'] >= count($state['rule_pools'])) {
            $state['step_results'][] = [
                'rule_id' => $ruleId, 'rule_name' => $rule->name, 'matched' => (int) $state['rule_matched'], 'error' => null,
            ];
            $this->advanceToNextRule($state);
            return;
        }

        $poolId  = (int) $state['rule_pools'][$state['pool_index']];
        $accIds  = $this->poolAccountIds($companyId, $poolId, $scopeIds);
        if (empty($accIds)) {
            $state['pool_index']++;
            $state['phase'] = 'search';
            return;
        }

        $accList = implode(',', array_map('intval', $accIds));
        $filterA = $accountId
            ? "AND a.account_id = {$accountId}"
            : "AND a.account_id IN ({$accList})";

        if ($state['phase'] === 'search') {
            // ── Материализация пар текущего пула в automatch_pairs ──
            $state['display_phase'] = 'searching';
            $db->createCommand()->delete('{{%automatch_pairs}}', ['job_id' => $jobId])->execute();
            if ($this->isGroupRule($rule)) {
                $pairsSelect = $this->groupMatchesSelect($rule, $companyId, $accList, $accountId);
                $db->createCommand("
                    INSERT INTO {{%automatch_pairs}} (job_id, rn, id_a, id_b, entry_ids)
                    SELECT :job, row_number() OVER (ORDER BY id_a), id_a, id_b, entry_ids
                    FROM ({$pairsSelect}) p
                ", [':job' => $jobId])->execute();
            } else {
                $pairsSelect = $this->poolPairsSelect($rule, $companyId, $accList, $filterA);
                $db->createCommand("
                    INSERT INTO {{%automatch_pairs}} (job_id, rn, id_a, id_b)
                    SELECT :job, row_number() OVER (ORDER BY id_a), id_a, id_b
                    FROM ({$pairsSelect}) p
                ", [':job' => $jobId])->execute();
            }

            $cnt = (int) $db->createCommand(
                'SELECT count(*) FROM {{%automatch_pairs}} WHERE job_id = :j', [':j' => $jobId]
            )->queryScalar();

            if ($cnt === 0) {
                // Пул исчерпан — переходим к следующему.
                $state['pool_index']++;
                $state['phase'] = 'search';
                return;
            }

            $state['pool_pair_total'] = $cnt;
            $state['pool_offset']     = 0;
            $state['phase']           = 'update';
            return;
        }

        // ── phase 'update': обновляем очередную порцию пар ──
        $state['display_phase'] = 'matching';
        $lo = (int) $state['pool_offset'];
        $hi = $lo + self::STEP_UPDATE_PAIRS;

        $now    = date('Y-m-d H:i:s');
        $userId = (Yii::$app instanceof \yii\web\Application && !Yii::$app->user->isGuest)
            ? (int) Yii::$app->user->id : null;
        $userIdSql = $userId !== null ? $userId : 'NULL';

        if ($this->isGroupRule($rule)) {
            $pairs = (int) $db->createCommand(
                "SELECT count(*) FROM {{%automatch_pairs}} WHERE job_id = :job AND rn > {$lo} AND rn <= {$hi}",
                [':job' => $jobId]
            )->queryScalar();

            $db->createCommand("
                WITH chunk AS (
                    SELECT entry_ids,
                           'MTCH' || lpad(nextval('match_id_seq')::text, 8, '0') AS mid
                    FROM {{%automatch_pairs}}
                    WHERE job_id = :job AND rn > {$lo} AND rn <= {$hi}
                ),
                to_update AS (
                    SELECT unnest(entry_ids) AS eid, mid FROM chunk
                )
                UPDATE nostro_entries ne
                SET match_id     = u.mid,
                    match_status = 'M',
                    matched_at   = '{$now}',
                    updated_at   = '{$now}',
                    updated_by   = {$userIdSql}
                FROM to_update u
                WHERE ne.id = u.eid
            ", [':job' => $jobId])->execute();
        } else {
            $affected = $db->createCommand("
                WITH chunk AS (
                    SELECT id_a, id_b,
                           'MTCH' || lpad(nextval('match_id_seq')::text, 8, '0') AS mid
                    FROM {{%automatch_pairs}}
                    WHERE job_id = :job AND rn > {$lo} AND rn <= {$hi}
                ),
                to_update AS (
                    SELECT id_a AS eid, mid FROM chunk
                    UNION ALL
                    SELECT id_b AS eid, mid FROM chunk
                )
                UPDATE nostro_entries ne
                SET match_id     = u.mid,
                    match_status = 'M',
                    matched_at   = '{$now}',
                    updated_at   = '{$now}',
                    updated_by   = {$userIdSql}
                FROM to_update u
                WHERE ne.id = u.eid
            ", [':job' => $jobId])->execute();

            $pairs = (int) ($affected / 2);
        }

        $state['rule_matched']  += $pairs;
        $state['total_matched'] += $pairs;
        $state['pool_offset']    = $hi;

        if ($state['pool_offset'] >= $state['pool_pair_total']) {
            // Порция пула закончилась — перематериализуем этот же пул, чтобы
            // добрать «проигравших» в конкуренции за общую b (обычно их нет).
            $state['phase'] = 'search';
        }
    }

    /**
     * Сбрасывает под-состояние пула и переводит машину к следующему правилу.
     *
     * @param array $state Состояние машины (по ссылке).
     * @return void
     */
    private function advanceToNextRule(array &$state): void
    {
        $state['current_rule']++;
        $state['rule_pools']      = null;
        $state['pool_index']      = 0;
        $state['phase']           = 'search';
        $state['pool_pair_total'] = 0;
        $state['pool_offset']     = 0;
        $state['rule_matched']    = 0;
        if ($state['current_rule'] < count($state['rule_ids'])) {
            $next = MatchingRule::findOne((int) $state['rule_ids'][$state['current_rule']]);
            $state['current_rule_name'] = $next ? $next->name : null;
        }
    }

    /**
     * Помечает зависшие running-задания компании как прерванные и чистит пары.
     *
     * Защищает от вечного замка, если пользователь закрыл вкладку посреди
     * прогона: задание без активности дольше {@see JOB_STALE_SECONDS} реклеймится.
     *
     * @param int $companyId ID компании.
     * @return void
     */
    private function reclaimStaleJobs(int $companyId): void
    {
        $db = Yii::$app->db;
        $stale = $db->createCommand(
            "SELECT job_id FROM {{%automatch_jobs}}
              WHERE company_id = :c AND status = 'running'
                AND updated_at < (now() - (:sec || ' seconds')::interval)",
            [':c' => $companyId, ':sec' => self::JOB_STALE_SECONDS]
        )->queryColumn();

        foreach ($stale as $jid) {
            $db->createCommand()->delete('{{%automatch_pairs}}', ['job_id' => $jid])->execute();
            $db->createCommand()->update('{{%automatch_jobs}}', [
                'status'        => 'error',
                'error_message' => 'Прервано: нет активности',
                'finished_at'   => date('Y-m-d H:i:s'),
            ], ['job_id' => $jid])->execute();
        }
    }

    private function isGroupRule(MatchingRule $rule): bool
    {
        return (bool) $rule->group_match_enabled && $rule->group_ls_type !== null && $rule->group_ls_type !== '';
    }

    private function groupSideEnabled(MatchingRule $rule, string $ls): bool
    {
        if ($rule->group_ls_type === MatchingRule::GROUP_LS) {
            return true;
        }

        return $rule->group_ls_type === $ls;
    }

    private function groupRuleCanRun(MatchingRule $rule): bool
    {
        return $this->isGroupRule($rule) && $rule->hasAnyMatchedIdField();
    }

    /**
     * Возвращает список ID пулов компании в области автоквитования.
     *
     * @param int $companyId ID компании.
     * @param int|null $accountId Ограничение по конкретному счёту.
     * @param int[]|null $limitAccountIds Список допустимых счетов из scope.
     * @param int|null $rulePoolId Ограничение по ностро-банку правила (NULL — все пулы).
     * @return int[] ID пулов.
     */
    private function resolvePoolIds(int $companyId, ?int $accountId, ?array $limitAccountIds, array $rulePoolIds = []): array
    {
        $db  = Yii::$app->db;
        $sql = "SELECT DISTINCT pool_id FROM accounts WHERE company_id = {$companyId} AND pool_id IS NOT NULL";
        // Правило конкретного ностро-банка ограничивает перебор одним пулом.
        if (!empty($rulePoolIds)) {
            $sql .= " AND pool_id IN (" . implode(',', array_map('intval', $rulePoolIds)) . ")";
        }
        if ($accountId) {
            $sql .= " AND id = {$accountId}";
        }
        if ($limitAccountIds !== null) {
            if (empty($limitAccountIds)) {
                return [];
            }
            $list = implode(',', array_map('intval', $limitAccountIds));
            $sql .= " AND id IN ({$list})";
        }
        return array_map('intval', $db->createCommand($sql)->queryColumn());
    }

    /**
     * Возвращает счета пула в пределах компании и (опц.) scope-ограничения.
     *
     * @param int $companyId ID компании.
     * @param int $poolId ID ностро-банка.
     * @param int[]|null $limitAccountIds Список допустимых счетов из scope.
     * @return int[] ID счетов.
     */
    private function poolAccountIds(int $companyId, int $poolId, ?array $limitAccountIds): array
    {
        $db  = Yii::$app->db;
        $ids = array_map('intval', $db->createCommand(
            "SELECT id FROM accounts WHERE pool_id = {$poolId} AND company_id = {$companyId}"
        )->queryColumn());
        if ($limitAccountIds !== null) {
            $ids = array_values(array_intersect($ids, array_map('intval', $limitAccountIds)));
        }
        return $ids;
    }

    private function groupMatchesSelect(MatchingRule $rule, int $companyId, string $accList, ?int $accountId): string
    {
        if (!$this->groupRuleCanRun($rule)) {
            return 'SELECT NULL::integer AS id_a, NULL::integer AS id_b, ARRAY[]::integer[] AS entry_ids WHERE false';
        }

        [$typeA, $typeB] = $this->pairTypes($rule->pair_type);
        $groupA = $this->groupSideEnabled($rule, $typeA);
        $groupB = $this->groupSideEnabled($rule, $typeB);
        $groupBothSides = $rule->group_ls_type === MatchingRule::GROUP_LS && $typeA !== $typeB;
        if (!$groupA && !$groupB) {
            return 'SELECT NULL::integer AS id_a, NULL::integer AS id_b, ARRAY[]::integer[] AS entry_ids WHERE false';
        }

        $filterA = $accountId ? "e.account_id = {$accountId}" : "e.account_id IN ({$accList})";
        $filterB = "e.account_id IN ({$accList})";
        $sideA = $this->groupSideSelect($rule, $companyId, $typeA, $filterA, $groupA, $groupBothSides ? 1 : 2);
        $sideB = $this->groupSideSelect($rule, $companyId, $typeB, $filterB, $groupB, $groupBothSides ? 1 : 2);
        $dcCondition = $rule->match_dc
            ? "AND ((a.dc_key = 'Debit' AND b.dc_key = 'Credit') OR (a.dc_key = 'Credit' AND b.dc_key = 'Debit'))"
            : '';
        $dateCondition = $rule->match_value_date
            ? 'AND a.value_date_key IS NOT DISTINCT FROM b.value_date_key'
            : '';
        $sameSideCondition = ($typeA === $typeB) ? 'AND a.first_id < b.first_id' : '';
        $realGroupCondition = $groupBothSides ? 'AND (a.entries_count > 1 OR b.entries_count > 1)' : '';

        return "
            WITH a_side AS (
                {$sideA}
            ),
            b_side AS (
                {$sideB}
            ),
            raw AS (
                SELECT
                    a.first_id AS id_a,
                    b.first_id AS id_b,
                    merged.entry_ids
                FROM a_side a
                JOIN b_side b
                  ON a.ref_field = b.ref_field
                 AND a.ref_value = b.ref_value
                 AND a.sum_amount = b.sum_amount
                 {$dcCondition}
                 {$dateCondition}
                 {$sameSideCondition}
                 {$realGroupCondition}
                 AND NOT (a.entry_ids && b.entry_ids)
                CROSS JOIN LATERAL (
                    SELECT array_agg(DISTINCT eid ORDER BY eid) AS entry_ids
                    FROM unnest(a.entry_ids || b.entry_ids) AS eid
                ) merged
                WHERE cardinality(merged.entry_ids) >= 2
            ),
            a_dedup AS (
                SELECT DISTINCT ON (id_a) id_a, id_b, entry_ids
                FROM raw
                ORDER BY id_a, id_b
            ),
            b_dedup AS (
                SELECT DISTINCT ON (id_b) id_a, id_b, entry_ids
                FROM a_dedup
                ORDER BY id_b, id_a
            )
            SELECT id_a, id_b, entry_ids FROM b_dedup
        ";
    }

    private function groupSideSelect(MatchingRule $rule, int $companyId, string $ls, string $accountFilter, bool $grouped, int $minEntries = 2): string
    {
        $refsSql = $this->groupReferenceRowsSql($rule, $companyId, $ls, $accountFilter);
        if ($grouped) {
            return "
                WITH refs AS (
                    {$refsSql}
                )
                SELECT
                    array_agg(id ORDER BY id)::integer[] AS entry_ids,
                    min(id) AS first_id,
                    sum(amount) AS sum_amount,
                    count(*) AS entries_count,
                    ref_field,
                    ref_value,
                    dc_key,
                    value_date_key
                FROM refs
                GROUP BY ref_field, ref_value, dc_key, value_date_key
                HAVING count(*) >= {$minEntries}
            ";
        }

        return "
            WITH refs AS (
                {$refsSql}
            )
            SELECT
                ARRAY[id]::integer[] AS entry_ids,
                id AS first_id,
                amount AS sum_amount,
                1 AS entries_count,
                ref_field,
                ref_value,
                dc_key,
                value_date_key
            FROM refs
        ";
    }

    private function groupReferenceRowsSql(MatchingRule $rule, int $companyId, string $ls, string $accountFilter): string
    {
        $fields = $rule->getSelectedReferenceFields();
        if (empty($fields)) {
            return "SELECT NULL::integer AS id, NULL::numeric AS amount, NULL::text AS ref_field, NULL::text AS ref_value, NULL::text AS dc_key, NULL::date AS value_date_key WHERE false";
        }

        $branches = [];
        $referenceValue = $rule->reference_value;
        $quotedReferenceValue = ($referenceValue !== null && $referenceValue !== '')
            ? Yii::$app->db->quoteValue($referenceValue)
            : null;
        $prefixLength = ($rule->id_prefix_match && $rule->id_prefix_length) ? (int) $rule->id_prefix_length : null;

        foreach ($fields as $field) {
            $refField = ($rule->cross_id_search || $quotedReferenceValue !== null)
                ? Yii::$app->db->quoteValue('*')
                : Yii::$app->db->quoteValue($field);
            $refValueExpr = $quotedReferenceValue !== null
                ? $quotedReferenceValue
                : ($prefixLength ? "left(e.{$field}, {$prefixLength})" : "e.{$field}");
            $fieldCondition = $quotedReferenceValue !== null
                ? "e.{$field} = {$quotedReferenceValue}"
                : "e.{$field} IS NOT NULL AND e.{$field} <> ''";
            if ($prefixLength && $quotedReferenceValue === null) {
                $fieldCondition .= " AND char_length(e.{$field}) >= {$prefixLength}";
            }

            $dcExpr = $rule->match_dc ? 'e.dc' : 'NULL';
            $valueDateExpr = $rule->match_value_date ? 'e.value_date' : 'NULL';
            $branches[] = "
                SELECT
                    e.id,
                    e.amount,
                    {$refField}::text AS ref_field,
                    {$refValueExpr}::text AS ref_value,
                    {$dcExpr}::text AS dc_key,
                    {$valueDateExpr}::date AS value_date_key
                FROM nostro_entries e
                WHERE e.company_id = {$companyId}
                  AND e.ls = '{$ls}'
                  AND e.match_status = 'U'
                  AND {$accountFilter}
                  AND {$fieldCondition}
            ";
        }

        return implode("\n                UNION\n", $branches);
    }

    /**
     * Строит SELECT уникальных пар (id_a, id_b) одного пула для правила.
     *
     * `DISTINCT ON (id_a)` — каждая запись a не более чем в одной паре;
     * `DISTINCT ON (id_b)` — каждая запись b не более чем в одной паре.
     * Используется и в `runRule` (синхронно), и в `advanceJob` (пошагово).
     *
     * ВАЖНО (производительность): условия по ID-полям НЕ объединяются в один
     * большой `... ON (a.f1=b.f1 OR a.f1=b.f2 OR ...)`. Такой OR планировщик
     * PostgreSQL не может свести к hash/index join и уходит в Nested Loop по
     * декартову произведению (O(n²)) — на 150 000 пар это минуты. Вместо этого
     * каждое равенство ID-полей выносится в отдельную ветвь `UNION`, и каждая
     * ветвь идёт по частичным индексам `idx_ne_uid_*` (Index/Hash Join, ~O(n)).
     * На проде это сократило поиск пар с ~14 минут до нескольких секунд.
     *
     * @param MatchingRule $rule Правило автоквитования.
     * @param int $companyId ID компании.
     * @param string $accList CSV-список ID счетов пула.
     * @param string $filterA Доп. фильтр стороны a (по конкретному счёту или пулу).
     * @return string SQL-выражение SELECT id_a, id_b.
     */
    private function poolPairsSelect(MatchingRule $rule, int $companyId, string $accList, string $filterA): string
    {
        [$typeA, $typeB] = $this->pairTypes($rule->pair_type);

        // Общие для всех ветвей условия-равенства (amount / value_date).
        $baseConditions    = array_merge($this->buildBaseConditions($rule), $this->buildReferenceValueConditions($rule));
        $baseAndSql        = empty($baseConditions) ? '' : 'AND ' . implode(' AND ', $baseConditions);
        $dcCondition       = $rule->match_dc
            ? "AND ((a.dc = 'Debit' AND b.dc = 'Credit') OR (a.dc = 'Credit' AND b.dc = 'Debit'))"
            : '';
        $sameSideCondition = ($typeA === $typeB) ? 'AND a.id < b.id' : '';

        // Условия по ID-полям — каждое в свою ветвь UNION (индексируемый join).
        $idPairExprs = $rule->reference_value !== null && $rule->reference_value !== '' ? [] : $this->buildIdPairExprs($rule);

        // Если ID-условий нет (правило только по amount/value_date), одна ветвь
        // с базовыми равенствами — они уже дают индексируемый ключ join.
        $branchIdConds = empty($idPairExprs) ? [''] : array_map(
            fn(string $expr) => "AND ({$expr})",
            $idPairExprs
        );

        $branches = [];
        foreach ($branchIdConds as $idCond) {
            $branches[] = "
                SELECT a.id AS id_a, b.id AS id_b
                FROM nostro_entries a
                JOIN nostro_entries b
                    ON b.company_id = {$companyId}
                   AND b.ls         = '{$typeB}'
                   AND b.match_status = 'U'
                   AND b.account_id IN ({$accList})
                   AND b.id <> a.id
                   {$sameSideCondition}
                   {$baseAndSql}
                   {$dcCondition}
                   {$idCond}
                WHERE
                    a.company_id   = {$companyId}
                    AND a.ls       = '{$typeA}'
                    AND a.match_status = 'U'
                    {$filterA}
            ";
        }

        $candSql = implode("\n                UNION\n", $branches);

        return "
            WITH cand AS (
                {$candSql}
            ),
            l_matches AS (
                SELECT DISTINCT ON (id_a) id_a, id_b
                FROM cand
                ORDER BY id_a, id_b
            ),
            s_dedup AS (
                SELECT DISTINCT ON (id_b) id_a, id_b
                FROM l_matches
                ORDER BY id_b, id_a
            )
            SELECT id_a, id_b FROM s_dedup
        ";
    }

    /**
     * Формирует JSON-ответ с прогрессом задания для UI.
     *
     * @param array $row Строка `automatch_jobs` (или её обновлённая копия).
     * @param array $state Состояние машины.
     * @param bool $finished Завершено ли задание.
     * @return array Прогресс: сквитовано, осталось, процент, фаза, итоги по правилам.
     */
    private function progressResponse(array $row, array $state, bool $finished): array
    {
        $matched   = (int) ($row['matched_pairs'] ?? 0);
        $totalU    = (int) ($row['total_unmatched'] ?? 0);
        $processed = $matched * 2;
        $percent   = $finished
            ? 100
            : ($totalU > 0 ? min(99, (int) floor($processed / $totalU * 100)) : 0);

        return [
            'success'             => true,
            'is_finished'         => $finished,
            'status'              => $row['status'] ?? ($finished ? 'finished' : 'running'),
            'total_matched'       => $matched,
            'matched_pairs'       => $matched,
            'total_unmatched'     => $totalU,
            'remaining_unmatched' => max(0, $totalU - $processed),
            'percent'             => $percent,
            'phase'               => $finished ? 'finished' : ($row['phase'] ?? 'matching'),
            'current_rule_name'   => $row['current_rule_name'] ?? null,
            'current_rule_index'  => $state['current_rule'] ?? null,
            'total_steps'         => isset($state['rule_ids']) ? count($state['rule_ids']) : null,
            'step_results'        => $state['step_results'] ?? [],
            'message'             => $finished ? ('Автоквитование завершено. Сквитовано пар: ' . $matched) : null,
        ];
    }

    /**
     * Выполняет одно правило автоквитования.
     *
     * Стратегия (оптимизирована для больших объёмов):
     *   1. Обрабатываем по одному ностро-банку (pool) за раз — меньший рабочий набор.
     *   2. За один проход находим ВСЕ уникальные пары пула (DISTINCT ON по обеим
     *      сторонам) и материализуем их во временную таблицу. Этот SELECT идёт по
     *      partial-индексам незаквитованных записей и быстр даже на сотнях тысяч
     *      строк — в отличие от прежнего LIMIT-батча, который заставлял планировщик
     *      пересортировывать весь набор пар на каждой итерации (O(n²)).
     *   3. Сам UPDATE (смена match_status → M обновляет много индексов) выполняется
     *      порциями по {$updateChunk} пар, каждая в своей короткой транзакции —
     *      блокировки не держатся на всём пуле сразу.
     *   4. Внешний do-while повторяет проход, пока находятся новые пары: так
     *      «проигравшие» в конкуренции за общую b записи дойдут на следующем проходе.
     *
     * Побочный эффект: пакетно обновляет найденные записи в `nostro_entries`,
     * присваивает новый `match_id`, статус `M`, `matched_at` и служебные поля.
     *
     * @param MatchingRule $rule Правило автоквитования.
     * @param int $companyId ID компании.
     * @param int|null $accountId Ограничение по конкретному счёту или `null`.
     * @param int[]|null $limitAccountIds Дополнительный список допустимых счетов из scope UI.
     * @return int Количество сквитованных пар.
     * @throws \Exception Если SQL-обновление правила завершилось ошибкой.
     */
    public function runRule(MatchingRule $rule, int $companyId, ?int $accountId, ?array $limitAccountIds = null): int
    {
        if ($this->isGroupRule($rule)) {
            return $this->runGroupRule($rule, $companyId, $accountId, $limitAccountIds);
        }

        $db = Yii::$app->db;

        // Условия пары строятся внутри poolPairsSelect(); здесь только ранний
        // выход, если правило не содержит ни одного условия JOIN.
        if (empty($this->buildJoinConditions($rule))) {
            return 0;
        }

        $now       = date('Y-m-d H:i:s');
        $userId    = (Yii::$app instanceof \yii\web\Application && !Yii::$app->user->isGuest)
            ? (int) Yii::$app->user->id
            : null;
        $userIdSql = $userId !== null ? $userId : 'NULL';
        $updateChunk = 10000; // пар на одну UPDATE-транзакцию
        $total       = 0;

        // ── Получаем список пулов для обработки ───────────────────────────────
        $poolQuery = "
            SELECT DISTINCT pool_id
            FROM accounts
            WHERE company_id = {$companyId} AND pool_id IS NOT NULL
        ";
        // Правило, привязанное к конкретному ностро-банку, обрабатывает только его.
        // Общее правило (pool_id IS NULL) обрабатывает все пулы компании.
        $rulePoolIds = $rule->getEffectivePoolIds();
        if (!empty($rulePoolIds)) {
            $poolQuery .= " AND pool_id IN (" . implode(',', array_map('intval', $rulePoolIds)) . ")";
        }
        if ($accountId) {
            $poolQuery .= " AND id = {$accountId}";
        }
        if ($limitAccountIds !== null) {
            if (empty($limitAccountIds)) return 0;
            $limitList = implode(',', array_map('intval', $limitAccountIds));
            $poolQuery .= " AND id IN ({$limitList})";
        }
        $poolIds = $db->createCommand($poolQuery)->queryColumn();

        if (empty($poolIds)) {
            return 0;
        }

        // ── Обрабатываем каждый пул отдельно ─────────────────────────────────
        foreach ($poolIds as $poolId) {
            $accountIds = $db->createCommand("
                SELECT id FROM accounts
                WHERE pool_id = {$poolId} AND company_id = {$companyId}
            ")->queryColumn();

            // Применяем scope-ограничение
            if ($limitAccountIds !== null) {
                $accountIds = array_values(array_intersect($accountIds, $limitAccountIds));
            }

            if (empty($accountIds)) {
                continue;
            }

            $accList = implode(',', array_map('intval', $accountIds));
            // При фильтре по конкретному счёту ограничиваем только сторону a
            $filterA = $accountId
                ? "AND a.account_id = {$accountId}"
                : "AND a.account_id IN ({$accList})";

            // Запрос материализации всех уникальных пар пула во временную таблицу.
            // Общий с пошаговым автоквитованием SELECT (poolPairsSelect):
            //   DISTINCT ON (a.id) — каждая запись a участвует не более чем в одной паре;
            //   DISTINCT ON (id_b) — каждая запись b участвует не более чем в одной паре.
            // Идёт по partial-индексам незаквитованных записей, без LIMIT и без
            // материализации всего декартова набора пар.
            $pairsSelect = $this->poolPairsSelect($rule, $companyId, $accList, $filterA);
            $materializeSql = "
                CREATE TEMP TABLE _am_pairs AS
                SELECT id_a, id_b, row_number() OVER (ORDER BY id_a) AS rn
                FROM ({$pairsSelect}) p
            ";

            // Внешний цикл: повторяем, пока находятся новые пары (конкуренция за b).
            do {
                $db->createCommand('DROP TABLE IF EXISTS _am_pairs')->execute();
                $db->createCommand($materializeSql)->execute();

                $pairCount = (int) $db->createCommand('SELECT count(*) FROM _am_pairs')->queryScalar();
                if ($pairCount === 0) {
                    $db->createCommand('DROP TABLE IF EXISTS _am_pairs')->execute();
                    break;
                }
                $db->createCommand('CREATE INDEX ON _am_pairs (rn)')->execute();

                // Обновляем порциями по {$updateChunk} пар — короткие транзакции.
                for ($lo = 0; $lo < $pairCount; $lo += $updateChunk) {
                    $hi = $lo + $updateChunk;
                    $sql = "
                        WITH chunk AS (
                            SELECT id_a, id_b,
                                   'MTCH' || lpad(nextval('match_id_seq')::text, 8, '0') AS mid
                            FROM _am_pairs
                            WHERE rn > {$lo} AND rn <= {$hi}
                        ),
                        to_update AS (
                            SELECT id_a AS eid, mid FROM chunk
                            UNION ALL
                            SELECT id_b AS eid, mid FROM chunk
                        )
                        UPDATE nostro_entries ne
                        SET match_id     = u.mid,
                            match_status = 'M',
                            matched_at   = '{$now}',
                            updated_at   = '{$now}',
                            updated_by   = {$userIdSql}
                        FROM to_update u
                        WHERE ne.id = u.eid
                    ";

                    $transaction = $db->beginTransaction();
                    try {
                        $affected = $db->createCommand($sql)->execute();
                        $transaction->commit();
                    } catch (\Exception $e) {
                        $transaction->rollBack();
                        $db->createCommand('DROP TABLE IF EXISTS _am_pairs')->execute();
                        throw $e;
                    }
                    $total += (int) ($affected / 2);
                }

                $db->createCommand('DROP TABLE IF EXISTS _am_pairs')->execute();
            } while ($pairCount > 0);
        }

        return $total;
    }

    private function runGroupRule(MatchingRule $rule, int $companyId, ?int $accountId, ?array $limitAccountIds = null): int
    {
        if (!$this->groupRuleCanRun($rule)) {
            return 0;
        }

        $db = Yii::$app->db;
        $now = date('Y-m-d H:i:s');
        $userId = (Yii::$app instanceof \yii\web\Application && !Yii::$app->user->isGuest)
            ? (int) Yii::$app->user->id
            : null;
        $userIdSql = $userId !== null ? $userId : 'NULL';
        $updateChunk = 10000;
        $total = 0;

        $poolIds = $this->resolvePoolIds($companyId, $accountId, $limitAccountIds, $rule->getEffectivePoolIds());
        if (empty($poolIds)) {
            return 0;
        }

        foreach ($poolIds as $poolId) {
            $accountIds = $this->poolAccountIds($companyId, (int) $poolId, $limitAccountIds);
            if (empty($accountIds)) {
                continue;
            }

            $accList = implode(',', array_map('intval', $accountIds));
            $matchesSelect = $this->groupMatchesSelect($rule, $companyId, $accList, $accountId);
            $materializeSql = "
                CREATE TEMP TABLE _am_groups AS
                SELECT id_a, id_b, entry_ids, row_number() OVER (ORDER BY id_a) AS rn
                FROM ({$matchesSelect}) p
            ";

            do {
                $db->createCommand('DROP TABLE IF EXISTS _am_groups')->execute();
                $db->createCommand($materializeSql)->execute();

                $groupCount = (int) $db->createCommand('SELECT count(*) FROM _am_groups')->queryScalar();
                if ($groupCount === 0) {
                    $db->createCommand('DROP TABLE IF EXISTS _am_groups')->execute();
                    break;
                }
                $db->createCommand('CREATE INDEX ON _am_groups (rn)')->execute();

                for ($lo = 0; $lo < $groupCount; $lo += $updateChunk) {
                    $hi = $lo + $updateChunk;
                    $sql = "
                        WITH chunk AS (
                            SELECT entry_ids,
                                   'MTCH' || lpad(nextval('match_id_seq')::text, 8, '0') AS mid
                            FROM _am_groups
                            WHERE rn > {$lo} AND rn <= {$hi}
                        ),
                        to_update AS (
                            SELECT unnest(entry_ids) AS eid, mid FROM chunk
                        )
                        UPDATE nostro_entries ne
                        SET match_id     = u.mid,
                            match_status = 'M',
                            matched_at   = '{$now}',
                            updated_at   = '{$now}',
                            updated_by   = {$userIdSql}
                        FROM to_update u
                        WHERE ne.id = u.eid
                    ";

                    $transaction = $db->beginTransaction();
                    try {
                        $db->createCommand($sql)->execute();
                        $transaction->commit();
                    } catch (\Exception $e) {
                        $transaction->rollBack();
                        $db->createCommand('DROP TABLE IF EXISTS _am_groups')->execute();
                        throw $e;
                    }
                    $total += min($updateChunk, $groupCount - $lo);
                }

                $db->createCommand('DROP TABLE IF EXISTS _am_groups')->execute();
            } while ($groupCount > 0);
        }

        return $total;
    }

    /**
     * Генерирует диагностический SQL для правила без выполнения.
     *
     * Используется консольной командой для анализа условий правила,
     * заполненности ID-полей и количества незаквитованных записей.
     *
     * @param MatchingRule $rule Правило автоквитования.
     * @param int $companyId ID компании.
     * @param int|null $accountId Ограничение по счёту или `null`.
     * @return string|null SQL-текст или `null`, если правило не содержит условий JOIN.
     */
    public function buildDebugSql(MatchingRule $rule, int $companyId, ?int $accountId): ?string
    {
        $db = Yii::$app->db;
        [$typeA, $typeB] = $this->pairTypes($rule->pair_type);

        $joinConditions = $this->buildJoinConditions($rule);
        if (empty($joinConditions)) {
            return null;
        }

        $accountFilter = $accountId ? "AND a.account_id = $accountId" : '';
        $joinSql       = implode(' AND ', $joinConditions);

        $dcCondition = '';
        if ($rule->match_dc) {
            $dcCondition = "AND ((a.dc = 'Debit' AND b.dc = 'Credit') OR (a.dc = 'Credit' AND b.dc = 'Debit'))";
        }

        return "-- Правило: {$rule->name} | pair_type={$rule->pair_type} | cross_id={$rule->cross_id_search}
WITH pairs AS (
    SELECT a.id AS id_a, b.id AS id_b,
           ROW_NUMBER() OVER (PARTITION BY a.id ORDER BY b.id) AS rn_a,
           ROW_NUMBER() OVER (PARTITION BY b.id ORDER BY a.id) AS rn_b
    FROM nostro_entries a
    JOIN accounts acc_a ON acc_a.id = a.account_id AND acc_a.pool_id IS NOT NULL
    JOIN accounts acc_b ON acc_b.pool_id = acc_a.pool_id
    JOIN nostro_entries b
        ON b.account_id = acc_b.id
       AND a.id <> b.id
       AND {$joinSql}
       {$dcCondition}
    WHERE a.company_id = {$companyId}
      AND b.company_id = {$companyId}
      AND a.ls = '{$typeA}'
      AND b.ls = '{$typeB}'
      AND a.match_status = 'U'
      AND b.match_status = 'U'
      {$accountFilter}
),
unique_pairs AS (SELECT id_a, id_b FROM pairs WHERE rn_a = 1 AND rn_b = 1)
SELECT id_a, id_b FROM unique_pairs;

-- Диагностика: число незаквитованных L и S по этой компании
SELECT ls, COUNT(*) FROM nostro_entries
WHERE company_id = {$companyId} AND match_status = 'U'
GROUP BY ls;

-- Диагностика: заполненность ID-полей
SELECT
  COUNT(*) FILTER (WHERE instruction_id IS NOT NULL)  AS has_instruction_id,
  COUNT(*) FILTER (WHERE end_to_end_id  IS NOT NULL)  AS has_end_to_end_id,
  COUNT(*) FILTER (WHERE transaction_id IS NOT NULL)  AS has_transaction_id,
  COUNT(*) FILTER (WHERE message_id     IS NOT NULL)  AS has_message_id,
  COUNT(*) FILTER (WHERE other_id       IS NOT NULL)  AS has_other_id,
  COUNT(*) FILTER (WHERE value_date     IS NOT NULL)  AS has_value_date,
  ls
FROM nostro_entries
WHERE company_id = {$companyId} AND match_status = 'U'
GROUP BY ls;";
    }

    /**
     * Строит SQL-условия JOIN для правила автоквитования.
     *
     * При включённом `cross_id_search` любой выбранный ID-поле стороны A
     * может совпасть с любым выбранным ID-полем стороны B. При выключенном
     * режиме сравниваются только одноимённые поля.
     *
     * @param MatchingRule $rule Правило автоквитования.
     * @return string[] Список SQL-условий для соединения записей.
     */
    private function buildJoinConditions(MatchingRule $rule): array
    {
        $conditions = $this->buildBaseConditions($rule);
        $referenceValueConditions = $this->buildReferenceValueConditions($rule);
        if (!empty($referenceValueConditions)) {
            return array_merge($conditions, $referenceValueConditions);
        }

        // ID-условия объединяются в один OR — используется в `buildDebugSql`
        // (диагностика) и для проверки «есть ли вообще условия у правила».
        // В боевом поиске пар (`poolPairsSelect`) вместо этого OR применяется
        // UNION по каждому равенству — см. комментарий там.
        $idConditions = $this->buildIdPairExprs($rule);
        if (!empty($idConditions)) {
            $conditions[] = '(' . implode(' OR ', $idConditions) . ')';
        }

        return $conditions;
    }

    /**
     * Возвращает список базовых условий-равенств пары (amount / value_date).
     *
     * Это индексируемые равенства, общие для всех ID-ветвей поиска пар.
     *
     * @param MatchingRule $rule Правило автоквитования.
     * @return string[] SQL-условия вида `a.amount = b.amount`.
     */
    private function buildBaseConditions(MatchingRule $rule): array
    {
        $conditions = [];
        if ($rule->match_amount) {
            $conditions[] = 'a.amount = b.amount';
        }
        if ($rule->match_value_date) {
            $conditions[] = 'a.value_date = b.value_date';
        }
        return $conditions;
    }

    /**
     * Возвращает список равенств по ID-полям для правила.
     *
     * Каждый элемент — самостоятельное условие вида
     * `a.f IS NOT NULL AND a.f = b.g`. При включённом `cross_id_search` любое
     * выбранное ID-поле стороны A сравнивается с любым выбранным ID-полем
     * стороны B (N×N выражений), иначе — только одноимённые поля (N выражений).
     * В `poolPairsSelect` каждое такое выражение становится отдельной ветвью
     * UNION (индексируемый join), что и даёт кратное ускорение автоквитования.
     *
     * @param MatchingRule $rule Правило автоквитования.
     * @return string[] Список SQL-равенств по ID-полям.
     */
    private function buildIdPairExprs(MatchingRule $rule): array
    {
        $idFields = $rule->getSelectedReferenceFields();

        if (empty($idFields)) {
            return [];
        }

        $exprs = [];
        if ($rule->id_prefix_match) {
            foreach ($idFields as $field) {
                $exprs[] = $this->buildIdCompareExpr($field, $field, $rule);
            }
        } elseif ($rule->cross_id_search) {
            foreach ($idFields as $fa) {
                foreach ($idFields as $fb) {
                    $exprs[] = $this->buildIdCompareExpr($fa, $fb, $rule);
                }
            }
        } else {
            foreach ($idFields as $field) {
                $exprs[] = $this->buildIdCompareExpr($field, $field, $rule);
            }
        }
        return $exprs;
    }

    private function buildIdCompareExpr(string $fieldA, string $fieldB, MatchingRule $rule): string
    {
        if ($rule->id_prefix_match && $rule->id_prefix_length) {
            $length = (int) $rule->id_prefix_length;

            return "a.{$fieldA} IS NOT NULL"
                . " AND b.{$fieldB} IS NOT NULL"
                . " AND char_length(a.{$fieldA}) >= {$length}"
                . " AND char_length(b.{$fieldB}) >= {$length}"
                . " AND left(a.{$fieldA}, {$length}) = left(b.{$fieldB}, {$length})";
        }

        return "a.{$fieldA} IS NOT NULL AND a.{$fieldA} = b.{$fieldB}";
    }

    /**
     * @param MatchingRule $rule Правило автоквитования.
     * @return string[]
     */
    private function buildReferenceValueConditions(MatchingRule $rule): array
    {
        $referenceValue = $rule->reference_value;
        $idFields = $rule->getSelectedReferenceFields();
        if ($referenceValue === null || $referenceValue === '' || empty($idFields)) {
            return [];
        }

        $quotedValue = Yii::$app->db->quoteValue($referenceValue);
        $sideA = [];
        $sideB = [];
        foreach ($idFields as $field) {
            $sideA[] = "a.{$field} = {$quotedValue}";
            $sideB[] = "b.{$field} = {$quotedValue}";
        }

        return [
            '(' . implode(' OR ', $sideA) . ')',
            '(' . implode(' OR ', $sideB) . ')',
        ];
    }

    /**
     * Возвращает типы сторон пары по коду `pair_type`.
     *
     * @param string $pairType Код пары из `MatchingRule::PAIR_*`.
     * @return string[] Массив из двух значений L/S для сторон A и B.
     */
    private function pairTypes(string $pairType): array
    {
        switch ($pairType) {
            case MatchingRule::PAIR_LS:
                return ['L', 'S'];
            case MatchingRule::PAIR_LL:
                return ['L', 'L'];
            case MatchingRule::PAIR_SS:
                return ['S', 'S'];
            default:
                return ['L', 'S'];
        }
    }

    /**
     * Генерирует уникальный `match_id` через PostgreSQL sequence.
     *
     * Формат результата: `MTCH00000001`. `nextval()` атомарен, поэтому
     * дополнительных проверок на коллизии в активной и архивной таблицах не нужно.
     *
     * @return string Новый идентификатор группы квитования.
     */
    public function generateMatchId(): string
    {
        $seq = Yii::$app->db->createCommand("SELECT nextval('match_id_seq')")->queryScalar();
        return 'MTCH' . str_pad($seq, 8, '0', STR_PAD_LEFT);
    }

    /**
     * Считает предварительную сводку по выбранным записям.
     *
     * Используется UI перед ручным квитованием, чтобы показать пользователю
     * суммы Ledger/Statement и разницу в выбранном наборе.
     *
     * @param int[] $ids ID записей для расчёта.
     * @param int|null $companyId ID компании для tenant-ограничения.
     * @return array Суммы и количества по L/S: `sum_ledger`, `sum_statement`, `diff`, `cnt_*`.
     */
    public function calcSummary(array $ids, ?int $companyId = null): array
    {
        $query = NostroEntry::find()->where(['id' => array_map('intval', $ids)]);
        if ($companyId !== null) {
            $query->andWhere(['company_id' => $companyId]);
        }
        $entries = $query->all();

        $sumL = 0.0;
        $sumS = 0.0;
        $cntL = 0;
        $cntS = 0;

        foreach ($entries as $e) {
            if ($e->ls === NostroEntry::LS_LEDGER) {
                $sumL += $e->amount;
                $cntL++;
            } else {
                $sumS += $e->amount;
                $cntS++;
            }
        }

        return [
            'sum_ledger'    => $sumL,
            'sum_statement' => $sumS,
            'diff'          => round($sumL - $sumS, 2),
            'cnt_ledger'    => $cntL,
            'cnt_statement' => $cntS,
        ];
    }
}
