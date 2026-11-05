<?php

namespace app\commands;

use app\commands\concerns\ImportProcessingLock;
use app\models\NostroBalance;
use app\models\TdsValidationError;
use app\services\StatementSequenceValidator;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Перенос выписок TDS (CAMT053 / MT950 / ED211 / ED743) из таблиц
 * ph_tds_stmt_hdr + ph_tds_stmt_dtl в nostro_balance / nostro_entries.
 *
 * Алгоритм:
 *   1. В tds_status ищем is_merged = false и type = 'PH_TDS' (один пакет на загрузку,
 *      внутри которого приходят все format_type разом).
 *   2. Для каждой такой записи открываем транзакцию.
 *   3. Для каждого format_type ∈ {CAMT053, MT950, ED211, ED743} находим в
 *      ph_tds_stmt_hdr все заголовки с этим format_type (связь с tds_status —
 *      только по типу пакета; stmt_id из tds_status не используется).
 *   4. Для каждого hdr:
 *      - находим счёт по accounts.name = hdr.account_no (company_id = 1);
 *        если не нашли — пропускаем этот hdr (пакет помечается частично-обработанным
 *        и tds_status НЕ помечается merged, чтобы можно было повторить);
 *      - вставляем одну строку nostro_balance;
 *      - вставляем все строки nostro_entries из ph_tds_stmt_dtl (stmt_id = hdr.stmt_id).
 *   5. Пишем batch-аудит (nostro_entry_audit / nostro_balance_audit).
 *   6. Если пропущенных hdr нет — tds_status.is_merged := true и, при --delete-source,
 *      удаляем обработанные строки из ph_tds_stmt_hdr/dtl.
 *   7. Commit.
 *
 * Использование:
 *   php yii tds-merge/run
 *   php yii tds-merge/run --type=CAMT053
 *   php yii tds-merge/run --delete-source
 */
class TdsMergeController extends Controller
{
    use ImportProcessingLock;

    /** Подавлять консольный вывод (true при вызове processOne из web-контекста). */
    public bool $quiet = false;

    const COMPANY_ID    = 1;
    const SECTION       = 'NRE';
    const LS_STATEMENT  = 'S';

    /** Тип пачки в tds_status. Один пакет содержит все format_type сразу. */
    const STATUS_TYPE   = 'PH_TDS';

    /** Допустимые format_type внутри ph_tds_stmt_hdr. */
    const SUPPORTED_TYPES = ['CAMT053', 'MT950', 'ED211', 'ED743'];

    /** Сколько строк-источников тянуть за один SELECT. */
    const FETCH_CHUNK  = 5000;
    /** Сколько строк копить перед batchInsert. */
    const INSERT_CHUNK = 1000;

    /** --type=CAMT053|MT950|ED211|ED743: обработать только один тип */
    public ?string $type = null;

    /** --delete-source: удалять обработанные строки ph_tds_stmt_hdr/dtl после успешного merge */
    public bool $deleteSource = false;

    /**
     * Описание опций командной строки.
     *
     * @param string $actionID Идентификатор action.
     * @return array
     */
    public function options($actionID)
    {
        return array_merge(parent::options($actionID), ['type', 'deleteSource']);
    }

    /**
     * Алиасы опций (--delete-source → --deleteSource).
     *
     * @return array
     */
    public function optionAliases()
    {
        return array_merge(parent::optionAliases(), [
            'delete-source' => 'deleteSource',
        ]);
    }

    /**
     * Запускает перенос всех необработанных TDS-пакетов.
     *
     * @return int Код завершения консольной команды.
     */
    public function actionRun(): int
    {
        $modeFlags = [];
        if ($this->deleteSource) {
            $modeFlags[] = 'delete-source';
        }
        if ($this->type !== null) {
            $modeFlags[] = 'type=' . $this->type;
        }
        $modeStr = $modeFlags ? ' [' . implode(', ', $modeFlags) . ']' : '';

        $this->stdout("=== TDS merge: " . date('Y-m-d H:i:s') . $modeStr . " ===\n", Console::BOLD);

        $formatTypes = self::SUPPORTED_TYPES;
        if ($this->type !== null) {
            $t = strtoupper($this->type);
            if (!in_array($t, self::SUPPORTED_TYPES, true)) {
                $this->stderr("Неизвестный тип: '{$this->type}'. Допустимы: " . implode(', ', self::SUPPORTED_TYPES) . "\n", Console::FG_RED);
                return ExitCode::USAGE;
            }
            $formatTypes = [$t];
        }

        $db = Yii::$app->db;

        // Один пакет tds_status (type=PH_TDS) содержит все format_type разом.
        $pending = $db->createCommand(
            "SELECT id, type
               FROM {{%tds_status}}
              WHERE is_merged = FALSE
                AND type = :type
              ORDER BY id",
            [':type' => self::STATUS_TYPE]
        )->queryAll();

        if (empty($pending)) {
            $this->stdout("Нет записей для обработки.\n", Console::FG_GREY);
            return ExitCode::OK;
        }

        $this->stdout("К обработке пакетов: " . count($pending) . "\n");

        $totalBalances = 0;
        $totalEntries  = 0;
        $errors        = 0;

        foreach ($pending as $row) {
            $statusId = (int)$row['id'];

            $this->stdout("\n┌─ tds_status.id={$statusId}, type={$row['type']}\n", Console::FG_CYAN);

            $res = $this->processOne($statusId, 'background');

            if ($res['busy']) {
                $this->stdout("└─ SKIP (пачка уже обрабатывается другим процессом)\n", Console::FG_GREY);
                continue;
            }
            if ($res['error'] !== null) {
                $errors++;
                $this->stderr("│  Ошибка: " . $res['error'] . "\n", Console::FG_RED);
                $this->stdout("└─ ROLLBACK\n", Console::FG_RED);
                continue;
            }

            $totalBalances += $res['balances'];
            $totalEntries  += $res['entries'];

            if ($res['ok']) {
                $this->stdout("│  Итого по пакету: балансов {$res['balances']}, записей {$res['entries']}\n", Console::FG_GREEN);
                $this->stdout("└─ OK\n");
            } else {
                $this->stdout("│  Итого по пакету: балансов {$res['balances']}, записей {$res['entries']}, не обработано hdr: {$res['skipped']} (счёт не найден или ожидаются недостающие страницы)\n", Console::FG_YELLOW);
                $this->stdout("└─ PARTIAL (не помечен merged, будет повторён)\n", Console::FG_YELLOW);
            }
        }

        $this->stdout("\n=== Итого: балансов {$totalBalances}, записей {$totalEntries}", Console::BOLD);
        if ($errors > 0) {
            $this->stdout(", ошибок {$errors}", Console::FG_RED);
        }
        $this->stdout(" ===\n");

        return $errors > 0 ? ExitCode::SOFTWARE : ExitCode::OK;
    }

    /**
     * Обрабатывает один пакет PH_TDS под блокировкой.
     *
     * Захватывает строку tds_status (взаимоисключение ручного и фонового
     * процессов), в одной транзакции переносит все `format_type` в `nostro_*`,
     * помечает пакет merged и снимает блокировку. Используется и фоновым
     * `actionRun`, и ручным запуском из интерфейса (web). При `$quiet=true`
     * консольный вывод деталей подавляется.
     *
     * @param int $statusId ID строки tds_status (type=PH_TDS).
     * @param string $owner Кто запустил: 'manual' или 'background'.
     * @return array `['busy'=>bool,'ok'=>bool,'balances'=>int,'entries'=>int,'skipped'=>int,'error'=>?string]`.
     */
    public function processOne(int $statusId, string $owner = 'background'): array
    {
        if (!$this->acquireProcessingLock($statusId, $owner)) {
            return ['busy' => true, 'ok' => false, 'balances' => 0, 'entries' => 0, 'skipped' => 0, 'skipped_accounts' => [], 'error' => null];
        }

        $db = Yii::$app->db;

        $formatTypes = self::SUPPORTED_TYPES;
        if ($this->type !== null) {
            $t = strtoupper($this->type);
            if (in_array($t, self::SUPPORTED_TYPES, true)) {
                $formatTypes = [$t];
            }
        }

        try {
            $tx = $db->beginTransaction();
            try {
                $batchBalances       = 0;
                $batchEntries        = 0;
                $allProcessedStmtIds = [];
                $allSkippedHdrs      = [];
                $allSkippedAccounts  = [];

                $settings = $this->validationSettings();

                // Все format_type обрабатываются в рамках одного пакета (batch_id = statusId).
                foreach ($formatTypes as $formatType) {
                    // MT950 / CAMT053 идут через проверяемый постраничный путь
                    // (Признаки 1/2/3). ED211/ED743 — через legacy без проверок.
                    if ($formatType === 'MT950' || $formatType === 'CAMT053') {
                        [$balances, $entries, $processedStmtIds, $skippedHdrs, $skippedAccounts]
                            = $this->mergeTypeValidated($formatType, $statusId, $settings);
                    } else {
                        [$balances, $entries, $processedStmtIds, $skippedHdrs, $skippedAccounts]
                            = $this->mergeType($formatType, $statusId);
                    }

                    $batchBalances      += $balances;
                    $batchEntries       += $entries;
                    $allProcessedStmtIds = array_merge($allProcessedStmtIds, $processedStmtIds);
                    $allSkippedHdrs      = array_merge($allSkippedHdrs, $skippedHdrs);
                    foreach ($skippedAccounts as $acc) {
                        $allSkippedAccounts[$acc] = true;
                    }

                    if ($balances > 0 || $entries > 0 || !empty($skippedHdrs)) {
                        $line = "│  {$formatType}: балансов {$balances}, записей {$entries}";
                        if (!empty($skippedHdrs)) {
                            $line .= ", пропущено hdr " . count($skippedHdrs);
                        }
                        $this->out($line . "\n", empty($skippedHdrs) ? Console::FG_GREEN : Console::FG_YELLOW);
                    }
                }

                if ($batchBalances === 0 && $batchEntries === 0 && empty($allSkippedHdrs)) {
                    $this->out("│  Нет заголовков PH_TDS в ph_tds_stmt_hdr — нечего переносить.\n", Console::FG_GREY);
                }

                $allOk = empty($allSkippedHdrs);

                // Причина частичной загрузки — какие счета не найдены в системе.
                $db->createCommand()->update('{{%tds_status}}', [
                    'skipped_accounts' => empty($allSkippedAccounts)
                        ? null
                        : json_encode(array_keys($allSkippedAccounts), JSON_UNESCAPED_UNICODE),
                ], ['id' => $statusId])->execute();

                if ($allOk) {
                    if ($this->deleteSource && !empty($allProcessedStmtIds)) {
                        $idsList = implode(',', array_map('strval', $allProcessedStmtIds));
                        $db->createCommand("DELETE FROM {{%ph_tds_stmt_dtl}} WHERE stmt_id IN ({$idsList})")->execute();
                        $db->createCommand("DELETE FROM {{%ph_tds_stmt_hdr}} WHERE stmt_id IN ({$idsList})")->execute();
                    }

                    $db->createCommand()->update('{{%tds_status}}', [
                        'is_merged'      => true,
                        'company_id'     => self::COMPANY_ID,
                        'entries_count'  => $batchEntries,
                        'balances_count' => $batchBalances,
                    ], ['id' => $statusId])->execute();
                }

                $tx->commit();

                return [
                    'busy'     => false,
                    'ok'       => $allOk,
                    'balances' => $batchBalances,
                    'entries'  => $batchEntries,
                    'skipped'  => count($allSkippedHdrs),
                    'skipped_accounts' => array_keys($allSkippedAccounts),
                    'error'    => null,
                ];
            } catch (\Throwable $e) {
                $tx->rollBack();
                return ['busy' => false, 'ok' => false, 'balances' => 0, 'entries' => 0, 'skipped' => 0, 'skipped_accounts' => [], 'error' => $e->getMessage()];
            }
        } finally {
            $this->releaseProcessingLock($statusId);
        }
    }

    /**
     * Переносит все заголовки и детали одного типа в nostro_balance/nostro_entries.
     *
     * Читает ph_tds_stmt_hdr потоково по stmt_id, по каждому hdr подтягивает
     * соответствующие ph_tds_stmt_dtl и формирует batch для вставки.
     *
     * @param string $type Один из {CAMT053, MT950, ED211, ED743}.
     * @param int $batchId ID пачки `tds_status` для трассировки/отката.
     * @return array `[balancesInserted, entriesInserted, processedStmtIds, skippedStmtIds, skippedAccountNames]`.
     */
    private function mergeType(string $type, int $batchId): array
    {
        $db = Yii::$app->db;

        $entryColumns = [
            'account_id', 'company_id', 'ls', 'dc', 'amount', 'currency',
            'value_date', 'post_date', 'instruction_id', 'end_to_end_id',
            'transaction_id', 'message_id', 'statement_number', 'other_id', 'source',
            'match_status', 'line_no', 'branch_code',
            'stmt_id', 'edno', 'eddate', 'edauthor',
            'created_at', 'updated_at', 'batch_id',
        ];
        $balanceColumns = self::balanceColumns();

        $accountCache = [];
        $now = date('Y-m-d H:i:s');

        $entryBuf    = [];
        $balanceBuf  = [];

        $totalEntries  = 0;
        $totalBalances = 0;

        $processedStmtIds = [];
        $skippedStmtIds   = [];
        $skippedAccounts  = [];

        $flushEntries = function () use ($db, $entryColumns, &$entryBuf, &$totalEntries, $type) {
            if (empty($entryBuf)) return;
            $lastId = (int)$db->createCommand("SELECT COALESCE(MAX(id), 0) FROM {{%nostro_entries}}")->queryScalar();

            $db->createCommand()
                ->batchInsert('{{%nostro_entries}}', $entryColumns, $entryBuf)
                ->execute();

            $this->writeEntryAuditAfterFlush($lastId, $entryBuf, $type);
            $totalEntries += count($entryBuf);
            $entryBuf = [];
        };

        $flushBalances = function () use ($db, $balanceColumns, &$balanceBuf, &$totalBalances, $type) {
            if (empty($balanceBuf)) return;
            $lastId = (int)$db->createCommand("SELECT COALESCE(MAX(id), 0) FROM {{%nostro_balance}}")->queryScalar();

            $db->createCommand()
                ->batchInsert('{{%nostro_balance}}', $balanceColumns, $balanceBuf)
                ->execute();

            $this->writeBalanceAuditAfterFlush($lastId, $balanceBuf, $type);
            $totalBalances += count($balanceBuf);
            $balanceBuf = [];
        };

        // Потоковое чтение hdr.
        $lastStmtId = '-1';
        while (true) {
            $hdrRows = $db->createCommand(
                "SELECT * FROM {{%ph_tds_stmt_hdr}}
                  WHERE format_type = :ft
                    AND stmt_id > :sid
                  ORDER BY stmt_id
                  LIMIT :lim",
                [
                    ':ft'  => $type,
                    ':sid' => $lastStmtId,
                    ':lim' => self::FETCH_CHUNK,
                ]
            )->queryAll();

            if (empty($hdrRows)) {
                break;
            }

            foreach ($hdrRows as $hdr) {
                $stmtId = (string)$hdr['stmt_id'];
                $lastStmtId = $stmtId;

                $accountNo = trim((string)($hdr['account_no'] ?? ''));
                if ($accountNo === '') {
                    $this->out("│  Пропуск stmt_id={$stmtId}: пустой account_no\n", Console::FG_YELLOW);
                    $skippedStmtIds[] = $stmtId;
                    continue;
                }

                if (!array_key_exists($accountNo, $accountCache)) {
                    $accountCache[$accountNo] = $db->createCommand(
                        "SELECT id FROM {{%accounts}}
                          WHERE company_id = :cid
                            AND name = :name
                          LIMIT 1",
                        [':cid' => self::COMPANY_ID, ':name' => $accountNo]
                    )->queryScalar();
                }

                $accountId = $accountCache[$accountNo];
                if (!$accountId) {
                    $this->out("│  Пропуск stmt_id={$stmtId}: счёт не найден для account_no='{$accountNo}'\n", Console::FG_YELLOW);
                    $skippedStmtIds[] = $stmtId;
                    $skippedAccounts[$accountNo] = true;
                    continue;
                }

                $balanceBuf[] = $this->buildBalanceRow($hdr, $type, $accountId, $now, $batchId);
                if (count($balanceBuf) >= self::INSERT_CHUNK) {
                    $flushBalances();
                }

                // Тянем детали по stmt_id потоково.
                $lastLineNo = -1;
                while (true) {
                    $dtlRows = $db->createCommand(
                        "SELECT * FROM {{%ph_tds_stmt_dtl}}
                          WHERE stmt_id = :sid
                            AND line_no > :ln
                          ORDER BY line_no
                          LIMIT :lim",
                        [
                            ':sid' => $stmtId,
                            ':ln'  => $lastLineNo,
                            ':lim' => self::FETCH_CHUNK,
                        ]
                    )->queryAll();

                    if (empty($dtlRows)) {
                        break;
                    }

                    foreach ($dtlRows as $dtl) {
                        $entryBuf[] = $this->buildEntryRow($hdr, $dtl, $type, $accountId, $now, $batchId);
                        $lastLineNo = (int)$dtl['line_no'];

                        if (count($entryBuf) >= self::INSERT_CHUNK) {
                            $flushEntries();
                        }
                    }

                    if (count($dtlRows) < self::FETCH_CHUNK) {
                        break;
                    }
                }

                $processedStmtIds[] = $stmtId;
            }

            if (count($hdrRows) < self::FETCH_CHUNK) {
                break;
            }
        }

        $flushEntries();
        $flushBalances();

        return [$totalBalances, $totalEntries, $processedStmtIds, $skippedStmtIds, array_keys($skippedAccounts)];
    }

    /**
     * Читает настройки проверок последовательности выписок из params.
     *
     * @return array Флаги вкл/выкл проверок и допуск сравнения сумм.
     */
    private function validationSettings(): array
    {
        $v = Yii::$app->params['validation'] ?? [];
        return [
            'enable_balance_check'       => $v['enable_balance_check']       ?? true,
            'enable_page_sequence_check' => $v['enable_page_sequence_check'] ?? true,
            'enable_intermediate_check'  => $v['enable_intermediate_check']  ?? true,
            'enable_turnover_check'      => $v['enable_turnover_check']      ?? true,
            'balance_tolerance'          => (float)($v['balance_tolerance']  ?? 0.01),
        ];
    }

    /**
     * Проверяемый постраничный перенос MT950 / CAMT053 (Признаки 1/2/3).
     *
     * Группирует заголовки по выписке (`account_no`, `opening_currency`,
     * `stmt_nb`) и передаёт каждую группу в mergeStatementGroup. Заголовки в
     * ph_tds_stmt_hdr накапливаются между загрузками (связь только по
     * format_type), поэтому многостраничная выписка, пришедшая частями в разных
     * загрузках, собирается здесь целиком: неполные выписки откладываются
     * (их stmt_id не помечаются обработанными, tds_status остаётся не merged).
     *
     * @param string $type MT950 или CAMT053.
     * @param int $batchId ID пачки tds_status.
     * @param array $settings Настройки проверок.
     * @return array `[balances, entries, processedStmtIds, skippedStmtIds, skippedAccounts]`.
     */
    private function mergeTypeValidated(string $type, int $batchId, array $settings): array
    {
        $db = Yii::$app->db;
        $now = date('Y-m-d H:i:s');

        $hdrs = $db->createCommand(
            "SELECT * FROM {{%ph_tds_stmt_hdr}}
              WHERE format_type = :ft
              ORDER BY account_no, opening_currency, opening_value_dt, stmt_ref, stmt_nb NULLS LAST, pg_nb NULLS LAST, stmt_id",
            [':ft' => $type]
        )->queryAll();

        if (empty($hdrs)) {
            return [0, 0, [], [], []];
        }

        // Группируем по выписке. Идентификатор выписки — stmt_nb (MT950) или,
        // если его нет (camt.053 не имеет номера выписки), stmt_ref. Страницы
        // одной выписки объединяются только при наличии постраничных метаданных
        // (pg_nb / INTM / last_pg_ind). Строки без метаданных обрабатываются
        // по-отдельности как одиночные балансы с проверкой непрерывности.
        $groups = [];
        foreach ($hdrs as $hdr) {
            $acct   = trim((string)($hdr['account_no'] ?? ''));
            $ccy    = trim((string)($hdr['opening_currency'] ?? ''));
            $seqKey = $this->statementSeqKey($hdr);

            if ($this->hasPageMetadata($hdr) && $seqKey !== null) {
                $key = "S|{$acct}|{$ccy}|{$seqKey}";
            } else {
                $key = "L|" . (string)$hdr['stmt_id'];
            }
            $groups[$key][] = $hdr;
        }

        $totalBalances    = 0;
        $totalEntries     = 0;
        $processedStmtIds = [];
        $skippedStmtIds   = [];
        $skippedAccounts  = [];
        $accountCache     = [];

        foreach ($groups as $groupHdrs) {
            $res = $this->mergeStatementGroup($type, $groupHdrs, $batchId, $settings, $now, $accountCache);

            $totalBalances   += $res['balances'];
            $totalEntries    += $res['entries'];
            $processedStmtIds = array_merge($processedStmtIds, $res['processed']);
            $skippedStmtIds   = array_merge($skippedStmtIds, $res['skipped']);
            foreach ($res['skipped_accounts'] as $a) {
                $skippedAccounts[$a] = true;
            }
        }

        return [$totalBalances, $totalEntries, $processedStmtIds, $skippedStmtIds, array_keys($skippedAccounts)];
    }

    /**
     * Обрабатывает одну выписку (группу страниц) MT950 / CAMT053.
     *
     * Определяет полноту выписки (страницы 1..N без пропусков и наличие
     * последней страницы). Неполная выписка откладывается до прихода
     * недостающих страниц. Полная — прогоняется через Признаки 1/2.2/2.1/3;
     * при нарушении проверки баланс и проводки (`nostro_entries`) не грузятся.
     * Промежуточные
     * балансы (M/INTM) считаются в памяти и в `nostro_balance` не хранятся;
     * в базу пишется единственный конечный баланс выписки (opening первой
     * страницы → closing последней). Идентификатор выписки — stmt_nb (MT950)
     * или stmt_ref (camt.053, у которого номера выписки нет).
     *
     * @param string $type MT950 или CAMT053.
     * @param array $groupHdrs Заголовки страниц одной выписки.
     * @param int $batchId ID пачки tds_status.
     * @param array $settings Настройки проверок.
     * @param string $now Метка времени.
     * @param array $accountCache Кэш `account_no => account_id` (по ссылке).
     * @return array `['balances'=>int,'entries'=>int,'processed'=>string[],'skipped'=>string[],'skipped_accounts'=>string[]]`.
     */
    private function mergeStatementGroup(string $type, array $groupHdrs, int $batchId, array $settings, string $now, array &$accountCache): array
    {
        $db = Yii::$app->db;
        $result = ['balances' => 0, 'entries' => 0, 'processed' => [], 'skipped' => [], 'skipped_accounts' => []];

        $stmtIds = array_map(static fn($h) => (string)$h['stmt_id'], $groupHdrs);

        // Резолвим счёт (одинаков для всей выписки).
        $accountNo = trim((string)($groupHdrs[0]['account_no'] ?? ''));
        if ($accountNo === '') {
            $result['skipped'] = $stmtIds;
            return $result;
        }
        if (!array_key_exists($accountNo, $accountCache)) {
            $accountCache[$accountNo] = $db->createCommand(
                "SELECT id FROM {{%accounts}} WHERE company_id = :cid AND name = :name LIMIT 1",
                [':cid' => self::COMPANY_ID, ':name' => $accountNo]
            )->queryScalar();
        }
        $accountId = $accountCache[$accountNo];
        if (!$accountId) {
            $result['skipped'] = $stmtIds;
            $result['skipped_accounts'][] = $accountNo;
            return $result;
        }

        $currency = trim((string)($groupHdrs[0]['opening_currency'] ?? ''));

        // Постраничные проверки требуют метаданных; непрерывность балансов
        // проверяем и для одиночной выписки без разметки страниц.
        $hasPageMeta = false;
        foreach ($groupHdrs as $h) {
            if ($this->hasPageMetadata($h)) {
                $hasPageMeta = true;
                break;
            }
        }

        // Идентификатор выписки: stmt_nb (MT950) или stmt_ref (camt.053).
        $seqNo = $this->statementSeqKey($groupHdrs[0]);

        // Дескрипторы страниц (полные флаги доступны из hdr).
        $pages = [];
        foreach ($groupHdrs as $h) {
            $pages[] = $this->pageDescriptor($h, $type);
        }
        usort($pages, static fn($a, $b) => $a['page_no'] <=> $b['page_no']);

        // Полнота выписки. Неполную откладываем (ждём недостающие страницы).
        if (!$this->statementComplete($pages)) {
            $result['skipped'] = $stmtIds;
            $this->out("│  {$type}: выписка №{$seqNo} неполная — отложена до прихода недостающих страниц\n", Console::FG_GREY);
            return $result;
        }

        $first = $pages[0];
        $last  = $pages[count($pages) - 1];

        // ─── Прогон проверок ───
        // Проверки страниц и оборотов сохраняют требования к метаданным.
        // Сверка с предыдущим балансом не зависит от номера и разметки выписки.
        $errors = [];
        if ($hasPageMeta && $seqNo !== null) {
            // Проводки выписки (все страницы) — для Признака 3.
            $entriesForTurnover = [];
            foreach ($stmtIds as $sid) {
                $dtlRows = $db->createCommand(
                    "SELECT dc_mark, amount FROM {{%ph_tds_stmt_dtl}} WHERE stmt_id = :sid",
                    [':sid' => $sid]
                )->queryAll();
                foreach ($dtlRows as $d) {
                    $entriesForTurnover[] = [
                        'amount' => $d['amount'] ?? 0,
                        'dc'     => $this->mapDc($d['dc_mark'] ?? null, $type) ?? 'Credit',
                    ];
                }
            }

            $validator = new StatementSequenceValidator($settings['balance_tolerance']);

            if ($settings['enable_page_sequence_check'] ?? true) {
                $e = $validator->checkPageSequence($pages);
                if ($e) {
                    $errors[] = ['code' => TdsValidationError::ERROR_PAGE_SEQUENCE, 'message' => $e];
                }
            }
            if (($settings['enable_intermediate_check'] ?? true) && count($pages) > 1) {
                $e = $validator->checkIntermediateContinuity($pages);
                if ($e) {
                    $errors[] = ['code' => TdsValidationError::ERROR_INTERMEDIATE_CONTINUITY, 'message' => $e];
                }
            }
            if (($settings['enable_turnover_check'] ?? true) && !empty($entriesForTurnover)) {
                $e = $validator->checkTurnover(
                    (string)$first['opening_balance'], $first['opening_dc'],
                    (string)$last['closing_balance'], $last['closing_dc'],
                    $entriesForTurnover
                );
                if ($e) {
                    $errors[] = ['code' => TdsValidationError::ERROR_TURNOVER, 'message' => $e];
                }
            }
        }
        if ($settings['enable_balance_check'] ?? true) {
            // Признак 2.1: opening выписки = closing предыдущей (через модель, до сохранения).
            $probe = new NostroBalance();
            $probe->company_id      = self::COMPANY_ID;
            $probe->account_id      = (int)$accountId;
            $probe->currency        = $currency;
            $probe->section         = self::SECTION;
            $probe->ls_type         = self::LS_STATEMENT;
            $probe->is_intermediate = false;
            $probe->value_date      = $first['_hdr']['opening_value_dt'] ?? null;
            $probe->opening_balance = (string)$first['opening_balance'];
            $probe->opening_dc      = $first['opening_dc'];
            $probe->closing_balance = (string)$last['closing_balance'];
            $probe->closing_dc      = $last['closing_dc'];
            $e = $probe->checkBalanceContinuity($settings['balance_tolerance']);
            if ($e) {
                $errors[] = ['code' => TdsValidationError::ERROR_BALANCE_CONTINUITY, 'message' => $e];
            }
        }

        $hasError = !empty($errors);
        $comment  = $hasError ? mb_substr(implode('; ', array_column($errors, 'message')), 0, 255) : null;

        // ─── Баланс и проводки грузим только если проверки пройдены ───
        if (!$hasError) {
            $this->clearValidationErrorLogs(
                $batchId,
                $type,
                $accountNo,
                $seqNo,
                $first['_hdr']['opening_value_dt'] ?? null
            );

            // Промежуточные балансы (M/INTM) в интерфейс не грузятся и как отдельные
            // строки не хранятся (уникальный индекс nostro_balance допускает лишь одну
            // строку на account/currency/value_date/section/source). Их суммы уже
            // учтены проверками выше. В nostro_balance пишем единственный видимый
            // конечный баланс выписки: opening первой страницы → closing последней.
            $balanceBuf = [];

            $balanceBuf[] = $this->balanceRowFromAssoc([
                'account_id'       => (int)$accountId,
                'statement_number' => $first['_hdr']['stmt_ref'] ?? null,
                'currency'         => $currency,
                'value_date'       => $first['_hdr']['opening_value_dt'] ?? null,
                'opening_balance'  => $first['opening_balance'],
                'opening_dc'       => $first['opening_dc'],
                'closing_balance'  => $last['closing_balance'],
                'closing_dc'       => $last['closing_dc'],
                'source'           => $type,
                'status'           => NostroBalance::STATUS_NORMAL,
                'branch_code'      => $first['_hdr']['edbranch'] ?? null,
                'stmt_id'          => $last['_hdr']['stmt_id'],
                'stmt_seq_no'      => $seqNo,
                // Для одностраничной — номер из сырого pg_nb (иначе null); для многостраничной
                // видимый баланс синтетический и номер страницы не несёт.
                'page_no'          => count($pages) === 1
                    ? (isset($first['_hdr']['pg_nb']) && $first['_hdr']['pg_nb'] !== '' ? (int)$first['_hdr']['pg_nb'] : null)
                    : null,
                'is_intermediate'  => false,
                'comment'          => null,
                'now'              => $now,
                'batch_id'         => $batchId,
            ]);

            $lastBalId = (int)$db->createCommand("SELECT COALESCE(MAX(id), 0) FROM {{%nostro_balance}}")->queryScalar();
            $db->createCommand()->batchInsert('{{%nostro_balance}}', self::balanceColumns(), $balanceBuf)->execute();
            $this->writeBalanceAuditAfterFlush($lastBalId, $balanceBuf, $type);
            $result['balances'] = count($balanceBuf);

            $hdrByStmtId = [];
            foreach ($groupHdrs as $h) {
                $hdrByStmtId[(string)$h['stmt_id']] = $h;
            }

            $entryBuf = [];
            foreach ($stmtIds as $sid) {
                $dtlRows = $db->createCommand(
                    "SELECT * FROM {{%ph_tds_stmt_dtl}} WHERE stmt_id = :sid ORDER BY line_no",
                    [':sid' => $sid]
                )->queryAll();
                foreach ($dtlRows as $dtl) {
                    $entryBuf[] = $this->buildEntryRow($hdrByStmtId[$sid], $dtl, $type, (int)$accountId, $now, $batchId);
                }
            }

            if (!empty($entryBuf)) {
                $lastEntryId = (int)$db->createCommand("SELECT COALESCE(MAX(id), 0) FROM {{%nostro_entries}}")->queryScalar();
                $db->createCommand()->batchInsert('{{%nostro_entries}}', $this->entryColumns(), $entryBuf)->execute();
                $this->writeEntryAuditAfterFlush($lastEntryId, $entryBuf, $type);
                $result['entries'] = count($entryBuf);
            }

            $this->out("│  {$type}: выписка №{$seqNo} — OK, страниц " . count($pages) . ", записей {$result['entries']}\n", Console::FG_GREEN);
        } else {
            $this->replaceValidationErrorLogs(
                $batchId,
                $type,
                $accountNo,
                $seqNo,
                $first['_hdr']['opening_value_dt'] ?? null,
                $first['_hdr']['load_dt'] ?? null,
                $errors
            );
            $this->out("│  {$type}: выписка №{$seqNo} — ОШИБКА проверки, баланс и проводки не загружены: {$comment}\n", Console::FG_YELLOW);
        }

        $result['processed'] = $stmtIds;
        return $result;
    }

    /**
     * Возвращает идентификатор выписки для группировки страниц.
     *
     * MT950 несёт номер выписки в stmt_nb (:28C:). camt.053 номера выписки не
     * имеет (stmt_nb пуст), поэтому страницы группируются по референсу stmt_ref
     * (Stmt/Id), одинаковому у всех страниц одной выписки.
     *
     * @param array $hdr Строка ph_tds_stmt_hdr.
     * @return string|null Идентификатор выписки или null.
     */
    private function statementSeqKey(array $hdr): ?string
    {
        return $this->nonEmptyValue($hdr['stmt_nb'] ?? null)
            ?? $this->nonEmptyValue($hdr['stmt_ref'] ?? null);
    }

    /**
     * Определяет, есть ли у заголовка постраничные метаданные последовательности.
     *
     * Проверки Признака 1/2.2 применяются только к выпискам, у которых внешний
     * загрузчик заполнил хотя бы одно из полей pg_nb / opening_is_intim /
     * closing_is_intim / last_pg_ind. Непрерывность балансов проверяется и без них.
     *
     * @param array $hdr Строка ph_tds_stmt_hdr.
     * @return bool
     */
    private function hasPageMetadata(array $hdr): bool
    {
        return $this->nonEmptyValue($hdr['pg_nb'] ?? null) !== null
            || $this->nonEmptyValue($hdr['opening_is_intim'] ?? null) !== null
            || $this->nonEmptyValue($hdr['closing_is_intim'] ?? null) !== null
            || $this->nonEmptyValue($hdr['last_pg_ind'] ?? null) !== null;
    }

    /**
     * Строит дескриптор страницы выписки из hdr для валидатора.
     *
     * @param array $hdr Строка ph_tds_stmt_hdr.
     * @param string $type MT950 или CAMT053.
     * @return array Дескриптор страницы (+ '_hdr' с исходной строкой).
     */
    private function pageDescriptor(array $hdr, string $type): array
    {
        return [
            'page_no'              => isset($hdr['pg_nb']) && $hdr['pg_nb'] !== '' ? (int)$hdr['pg_nb'] : 1,
            'stmt_seq_no'          => $this->nonEmptyValue($hdr['stmt_nb'] ?? null),
            'opening_intermediate' => StatementSequenceValidator::isIntermediateFlag($hdr['opening_is_intim'] ?? null),
            'closing_intermediate' => StatementSequenceValidator::isIntermediateFlag($hdr['closing_is_intim'] ?? null),
            'is_last_page'         => StatementSequenceValidator::isLastPageFlag($hdr['last_pg_ind'] ?? null),
            'opening_balance'      => $hdr['opening_amount'] ?? 0,
            'opening_dc'           => $this->mapDcShort($hdr['opening_dc'] ?? null, $type) ?? 'C',
            'closing_balance'      => $hdr['closing_amount'] ?? 0,
            'closing_dc'           => $this->mapDcShort($hdr['closing_dc'] ?? null, $type) ?? 'C',
            '_hdr'                 => $hdr,
        ];
    }

    /**
     * Определяет, полна ли выписка (все страницы пришли).
     *
     * Полной считается выписка, у которой номера страниц идут без пропусков от 1
     * и присутствует последняя страница: признак last_pg_ind либо финальный
     * исходящий баланс (не промежуточный) у страницы с наибольшим номером.
     *
     * @param array $pages Дескрипторы страниц, отсортированные по page_no.
     * @return bool
     */
    private function statementComplete(array $pages): bool
    {
        if (empty($pages)) {
            return false;
        }

        // Непрерывность 1..N.
        foreach ($pages as $i => $p) {
            if ((int)$p['page_no'] !== $i + 1) {
                return false;
            }
        }

        // Наличие последней страницы.
        foreach ($pages as $p) {
            if (!empty($p['is_last_page'])) {
                return true;
            }
        }

        $last = $pages[count($pages) - 1];
        return empty($last['closing_intermediate']);
    }

    /**
     * Собирает строку nostro_balance из ассоциативного массива в порядке
     * balanceColumns().
     *
     * @param array $a Значения полей баланса.
     * @return array Строка для batchInsert.
     */
    private function balanceRowFromAssoc(array $a): array
    {
        $map = [
            'company_id'       => self::COMPANY_ID,
            'account_id'       => $a['account_id'],
            'ls_type'          => self::LS_STATEMENT,
            'statement_number' => $a['statement_number'] ?? null,
            'currency'         => $a['currency'] ?? null,
            'value_date'       => $a['value_date'] ?? null,
            'opening_balance'  => $a['opening_balance'] ?? 0,
            'opening_dc'       => $a['opening_dc'] ?? null,
            'closing_balance'  => $a['closing_balance'] ?? 0,
            'closing_dc'       => $a['closing_dc'] ?? null,
            'section'          => self::SECTION,
            'source'           => $a['source'],
            'status'           => $a['status'] ?? NostroBalance::STATUS_NORMAL,
            'branch_code'      => $a['branch_code'] ?? null,
            'stmt_id'          => $a['stmt_id'],
            'edno'             => null,
            'eddate'           => null,
            'edauthor'         => null,
            'created_at'       => $a['now'],
            'updated_at'       => $a['now'],
            'batch_id'         => $a['batch_id'],
            'stmt_seq_no'      => $a['stmt_seq_no'] ?? null,
            'page_no'          => $a['page_no'] ?? null,
            'is_intermediate'  => $a['is_intermediate'] ?? false,
            'comment'          => $a['comment'] ?? null,
        ];

        $row = [];
        foreach (self::balanceColumns() as $c) {
            $row[] = $map[$c];
        }
        return $row;
    }

    /**
     * Возвращает порядок колонок для batchInsert в nostro_entries.
     *
     * @return string[]
     */
    private function entryColumns(): array
    {
        return [
            'account_id', 'company_id', 'ls', 'dc', 'amount', 'currency',
            'value_date', 'post_date', 'instruction_id', 'end_to_end_id',
            'transaction_id', 'message_id', 'statement_number', 'other_id', 'source',
            'match_status', 'line_no', 'branch_code',
            'stmt_id', 'edno', 'eddate', 'edauthor',
            'created_at', 'updated_at', 'batch_id',
        ];
    }

    /**
     * Строит строку для batchInsert в nostro_balance по hdr и типу.
     *
     * @param array $hdr Строка ph_tds_stmt_hdr.
     * @param string $type Тип источника.
     * @param int $accountId Найденный account_id.
     * @param string $now Метка времени created_at/updated_at.
     * @param int $batchId ID пачки tds_status.
     * @return array Строка для batchInsert в порядке `$balanceColumns`.
     */
    private function buildBalanceRow(array $hdr, string $type, int $accountId, string $now, int $batchId): array
    {
        $statementNumber = $hdr['stmt_ref'] ?? null;

        [$edno, $eddate, $edauthor] = $this->edFieldsForBalance($hdr, $type);

        return [
            self::COMPANY_ID,
            $accountId,
            self::LS_STATEMENT,
            $statementNumber,
            $hdr['opening_currency'] ?? null,
            $hdr['opening_value_dt'] ?? null,
            $hdr['opening_amount'] ?? 0,
            $this->mapDcShort($hdr['opening_dc'] ?? null, $type),
            $hdr['closing_amount'] ?? 0,
            $this->mapDcShort($hdr['closing_dc'] ?? null, $type),
            self::SECTION,
            $hdr['format_type'],
            'normal',
            $hdr['edbranch'] ?? null,
            $hdr['stmt_id'],
            $edno,
            $eddate,
            $edauthor,
            $now,
            $now,
            $batchId,
            // Постраничные поля (в legacy-пути ED211/ED743 обычно null/false).
            $hdr['stmt_nb'] ?? null,
            isset($hdr['pg_nb']) && $hdr['pg_nb'] !== '' ? (int)$hdr['pg_nb'] : null,
            \app\services\StatementSequenceValidator::isIntermediateFlag($hdr['closing_is_intim'] ?? null),
            null, // comment
        ];
    }

    /**
     * Возвращает порядок колонок для batchInsert в nostro_balance.
     *
     * Постраничные поля (`stmt_seq_no`, `page_no`, `is_intermediate`, `comment`)
     * добавлены в конец, чтобы не сдвигать индекс `stmt_id` (14), используемый аудитом.
     *
     * @return string[]
     */
    private static function balanceColumns(): array
    {
        return [
            'company_id', 'account_id', 'ls_type', 'statement_number', 'currency',
            'value_date', 'opening_balance', 'opening_dc', 'closing_balance', 'closing_dc',
            'section', 'source', 'status', 'branch_code',
            'stmt_id', 'edno', 'eddate', 'edauthor',
            'created_at', 'updated_at', 'batch_id',
            'stmt_seq_no', 'page_no', 'is_intermediate', 'comment',
        ];
    }

    /**
     * Строит строку для batchInsert в nostro_entries по hdr+dtl и типу.
     *
     * @param array $hdr Строка ph_tds_stmt_hdr.
     * @param array $dtl Строка ph_tds_stmt_dtl.
     * @param string $type Тип источника.
     * @param int $accountId Найденный account_id.
     * @param string $now Метка времени created_at/updated_at.
     * @param int $batchId ID пачки tds_status.
     * @return array Строка для batchInsert в порядке `$entryColumns`.
     */
    private function buildEntryRow(array $hdr, array $dtl, string $type, int $accountId, string $now, int $batchId): array
    {
        // Маппинг общих полей.
        $amount      = $dtl['amount'] ?? null;
        $currency    = $dtl['currency'] ?? ($hdr['opening_currency'] ?? null);
        $endToEndId  = $dtl['end_to_end_id'] ?? null;
        $branchCode  = $hdr['edbranch'] ?? null;
        $lineNo      = $dtl['line_no'] ?? null;
        $stmtId      = $dtl['stmt_id'];
        $statementNumber = $hdr['stmt_ref'] ?? null;

        // Поля, специфичные для типа.
        $valueDate     = null;
        $postDate      = null;
        $instructionId = null;
        $transactionId = null;
        $messageId     = null;
        $otherId       = null;
        $otherId = $this->nonEmptyValue($dtl['other_id'] ?? null);
        $edno          = null;
        $eddate        = null;
        $edauthor      = null;

        switch ($type) {
            case 'CAMT053':
                $valueDate     = $hdr['opening_value_dt'] ?? null;
                $postDate      = $hdr['opening_value_dt'] ?? null;
                $instructionId = $dtl['instr_id'] ?? null;
                $transactionId = $dtl['tx_id'] ?? null;
                $messageId = $dtl['msg_id'] ?? null;
                break;

            case 'MT950':
                $valueDate     = $dtl['value_dt'] ?? null;
                $postDate      = $dtl['entry_dt'] ?? null;
                $instructionId = $dtl['instr_id'] ?? null;
                $transactionId = $dtl['tx_id'] ?? null;
                $otherId       = $this->nonEmptyValue($dtl['op_type'] ?? null);
                break;

            case 'ED211':
            case 'ED743':
                $valueDate     = $hdr['opening_value_dt'] ?? null;
                $postDate      = $hdr['opening_value_dt'] ?? null;
                $instructionId = $dtl['entry_ref'] ?? null;
                $transactionId = $dtl['ed_bank_name'] ?? null;
                $edno          = $dtl['ref_edno'] ?? null;
                $eddate        = $dtl['ref_eddate'] ?? null;
                $edauthor      = $dtl['ref_edauthor'] ?? null;
                break;
        }

        return [
            $accountId,
            self::COMPANY_ID,
            self::LS_STATEMENT,
            $this->mapDc($dtl['dc_mark'] ?? null, $type),
            $amount,
            $currency,
            $valueDate,
            $postDate,
            $instructionId,
            $endToEndId,
            $transactionId,
            $messageId,
            $statementNumber,
            $otherId,
            $hdr['format_type'],
            'U',
            $lineNo,
            $branchCode,
            $stmtId,
            $edno,
            $eddate,
            $edauthor,
            $now,
            $now,
            $batchId,
        ];
    }

    /**
     * Возвращает строковое значение, если источник не пустой.
     *
     * @param mixed $value Значение из таблицы-источника.
     * @return string|null
     */
    private function nonEmptyValue($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string)$value);
        return $value === '' ? null : $value;
    }

    /**
     * Возвращает [edno, eddate, edauthor] для строки баланса.
     *
     * Для ED211/ED743 берём edno/eddate из hdr, а edauthor — из первой строки
     * ph_tds_stmt_dtl (ref_edauthor), т.к. в ph_tds_stmt_hdr такого поля нет.
     *
     * @param array $hdr Строка ph_tds_stmt_hdr.
     * @param string $type Тип источника.
     * @return array [edno, eddate, edauthor]
     */
    private function edFieldsForBalance(array $hdr, string $type): array
    {
        if ($type !== 'ED211' && $type !== 'ED743') {
            return [null, null, null];
        }

        $edauthor = Yii::$app->db->createCommand(
            "SELECT ref_edauthor FROM {{%ph_tds_stmt_dtl}}
              WHERE stmt_id = :sid
                AND ref_edauthor IS NOT NULL
              ORDER BY line_no
              LIMIT 1",
            [':sid' => $hdr['stmt_id']]
        )->queryScalar();

        return [
            $hdr['edno'] ?? null,
            $hdr['eddate'] ?? null,
            $edauthor ?: null,
        ];
    }

    /**
     * Преобразует D/C-признак в значение `Debit` или `Credit`.
     *
     * CAMT053: DBIT/CRDT. MT950, ED211, ED743: D/C.
     * Пустое значение пропускается как null.
     *
     * @param string|null $raw Сырое значение из источника.
     * @param string $type Тип источника.
     * @return string|null Нормализованное значение.
     * @throws \RuntimeException Если признак не распознан.
     */
    private function mapDc(?string $raw, string $type): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $v = strtoupper(trim((string)$raw));

        if ($v === 'DBIT' || $v === 'D' || $v === 'DEBIT') return 'Debit';
        if ($v === 'CRDT' || $v === 'C' || $v === 'CREDIT') return 'Credit';

        throw new \RuntimeException("Некорректный D/C для {$type}: '{$raw}'");
    }

    /**
     * Преобразует D/C-признак в односимвольный код `D`/`C` для nostro_balance.
     *
     * Колонки nostro_balance.opening_dc/closing_dc — char(1), поэтому полная
     * форма `Debit`/`Credit` из mapDc() в них не помещается. Метод нормализует
     * результат к односимвольному виду, сохраняя строгую проверку источника.
     *
     * @param string|null $raw Сырое значение из источника.
     * @param string $type Тип источника.
     * @return string|null `D`, `C` или `null` для пустого значения.
     * @throws \RuntimeException Если признак не распознан.
     */
    private function mapDcShort(?string $raw, string $type): ?string
    {
        $full = $this->mapDc($raw, $type);
        if ($full === null) {
            return null;
        }
        return $full === 'Debit' ? 'D' : 'C';
    }

    /**
     * Пишет аудит создания TDS-записей после batchInsert.
     *
     * @param int $lastId Максимальный ID до вставки batch.
     * @param array $insertedRows Буфер строк, переданный в batchInsert.
     * @param string $type Тип источника (для reason).
     * @return void
     */
    private function writeEntryAuditAfterFlush(int $lastId, array $insertedRows, string $type): void
    {
        // stmt_id и line_no в порядке колонок entryColumns:
        // 0:account_id ... 16:line_no, 17:branch_code, 18:stmt_id
        $stmtIds = [];
        foreach ($insertedRows as $row) {
            if (isset($row[18])) {
                $stmtIds[(string)$row[18]] = true;
            }
        }
        if (empty($stmtIds)) {
            return;
        }
        $stmtIdsList = implode(',', array_map('strval', array_keys($stmtIds)));

        $rows = Yii::$app->db->createCommand(
            "SELECT id, account_id, company_id, ls, dc, amount, currency,
                    value_date, post_date, instruction_id, end_to_end_id,
                    transaction_id, message_id, statement_number, other_id, comment, source,
                    match_status, match_id, extract_no, line_no, branch_code,
                    stmt_id, edno, eddate, edauthor, created_at, updated_at
               FROM {{%nostro_entries}}
              WHERE id > :last_id
                AND source = :source
                AND stmt_id IN ({$stmtIdsList})
              ORDER BY id",
            [
                ':last_id' => $lastId,
                ':source'  => $type,
            ]
        )->queryAll();

        if (empty($rows)) {
            return;
        }

        $auditRows = [];
        $now = date('Y-m-d H:i:s');
        $reason = "Импорт {$type}";
        foreach ($rows as $row) {
            $auditRows[] = [
                (int)$row['id'],
                0,
                'create',
                null,
                json_encode($row, JSON_UNESCAPED_UNICODE),
                null,
                null,
                $reason,
                $now,
            ];
        }

        Yii::$app->db->createCommand()
            ->batchInsert('{{%nostro_entry_audit}}', [
                'entry_id', 'user_id', 'action', 'old_values', 'new_values',
                'changed_field', 'archived_id', 'reason', 'created_at',
            ], $auditRows)
            ->execute();
    }

    /**
     * Пишет аудит импорта TDS-балансов после batchInsert.
     *
     * @param int $lastId Максимальный ID до вставки batch.
     * @param array $insertedRows Буфер строк, переданный в batchInsert.
     * @param string $type Тип источника (для reason).
     * @return void
     */
    private function writeBalanceAuditAfterFlush(int $lastId, array $insertedRows, string $type): void
    {
        // stmt_id в порядке колонок balanceColumns:
        // 0:company_id ... 13:branch_code, 14:stmt_id
        $stmtIds = [];
        foreach ($insertedRows as $row) {
            if (isset($row[14])) {
                $stmtIds[(string)$row[14]] = true;
            }
        }
        if (empty($stmtIds)) {
            return;
        }
        $stmtIdsList = implode(',', array_map('strval', array_keys($stmtIds)));

        $rows = Yii::$app->db->createCommand(
            "SELECT id, company_id, account_id, ls_type, statement_number, currency,
                    value_date, opening_balance, opening_dc, closing_balance, closing_dc,
                    section, source, status, comment, extract_no, line_no, branch_code,
                    stmt_id, edno, eddate, edauthor, created_at, updated_at
               FROM {{%nostro_balance}}
              WHERE id > :last_id
                AND source = :source
                AND stmt_id IN ({$stmtIdsList})
              ORDER BY id",
            [
                ':last_id' => $lastId,
                ':source'  => $type,
            ]
        )->queryAll();

        if (empty($rows)) {
            return;
        }

        $auditRows = [];
        $now = date('Y-m-d H:i:s');
        $reason = "Импорт {$type}";
        foreach ($rows as $row) {
            $auditRows[] = [
                (int)$row['id'],
                0,
                'import',
                null,
                json_encode($row, JSON_UNESCAPED_UNICODE),
                $reason,
                $now,
            ];
        }

        Yii::$app->db->createCommand()
            ->batchInsert('{{%nostro_balance_audit}}', [
                'balance_id', 'user_id', 'action', 'old_values',
                'new_values', 'reason', 'created_at',
            ], $auditRows)
            ->execute();
    }

    /**
     * Полностью заменяет текущие логи ошибок для конкретной выписки.
     *
     * @param int $batchId
     * @param string $statementType
     * @param string $accountNo
     * @param string|null $statementNumber
     * @param string|null $statementDate
     * @param string|null $statementDateTime
     * @param array $errors
     * @return void
     */
    private function replaceValidationErrorLogs(
        int $batchId,
        string $statementType,
        string $accountNo,
        ?string $statementNumber,
        ?string $statementDate,
        ?string $statementDateTime,
        array $errors
    ): void {
        $this->clearValidationErrorLogs($batchId, $statementType, $accountNo, $statementNumber, $statementDate);

        if (empty($errors)) {
            return;
        }

        $statementTime = null;
        if (!empty($statementDateTime)) {
            $statementTime = date('H:i:s', strtotime($statementDateTime));
        }

        $rows = [];
        foreach ($errors as $error) {
            $rows[] = [
                self::COMPANY_ID,
                $batchId,
                $statementType,
                $error['code'],
                $statementDate,
                $statementTime,
                $accountNo,
                $statementNumber,
                $error['message'],
            ];
        }

        Yii::$app->db->createCommand()->batchInsert('{{%tds_validation_errors}}', [
            'company_id',
            'batch_id',
            'statement_type',
            'error_code',
            'statement_date',
            'statement_time',
            'account_no',
            'statement_number',
            'error_message',
        ], $rows)->execute();
    }

    /**
     * Удаляет актуальные логи ошибки для выписки после успешной загрузки или перед перезаписью.
     *
     * @param int $batchId
     * @param string $statementType
     * @param string $accountNo
     * @param string|null $statementNumber
     * @param string|null $statementDate
     * @return void
     */
    private function clearValidationErrorLogs(
        int $batchId,
        string $statementType,
        string $accountNo,
        ?string $statementNumber,
        ?string $statementDate
    ): void {
        $condition = [
            'company_id' => self::COMPANY_ID,
            'batch_id' => $batchId,
            'statement_type' => $statementType,
            'account_no' => $accountNo,
            'statement_date' => $statementDate,
        ];

        if ($statementNumber === null || $statementNumber === '') {
            $where = ['and', $condition, ['statement_number' => null]];
        } else {
            $where = array_merge($condition, ['statement_number' => $statementNumber]);
        }

        Yii::$app->db->createCommand()->delete('{{%tds_validation_errors}}', $where)->execute();
    }
}
