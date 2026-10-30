<?php
namespace app\controllers;

use Yii;
use yii\web\Response;
use app\models\NostroEntry;
use app\models\NostroEntryAudit;
use app\models\Account;

/**
 * JSON-контроллер активных записей выверки.
 *
 * Управляет списком, ручным созданием/редактированием/удалением операций,
 * комментариями и историей аудита. Все запросы ограничиваются `company_id`
 * текущего пользователя.
 */
class NostroEntryController extends BaseController
{
    /**
     * Отключает CSRF для API страницы выверки.
     *
     * @param \yii\base\Action $action Запускаемое действие.
     * @return bool Можно ли продолжать выполнение action.
     */
    public function beforeAction($action): bool
    {
        $this->enableCsrfValidation = false;
        return parent::beforeAction($action);
    }

    /**
     * Возвращает ID компании текущего пользователя.
     *
     * @return int|null ID компании или `null`, если компания не выбрана.
     */
    private function cid(): ?int
    {
        $u = Yii::$app->user->identity;
        return ($u && $u->company_id) ? (int)$u->company_id : null;
    }

    /**
     * Возвращает постраничный список активных операций выверки.
     *
     * GET `/nostro-entry/list`. Поддерживает фильтры по ностро-банку, счёту,
     * сумме, датам, ID-полям, статусу квитования и безопасную сортировку.
     *
     * @return array JSON с данными, total, page, limit и pages.
     */
    public function actionList(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $cid = $this->cid();
        if (!$cid) return ['success' => false, 'message' => 'Компания не выбрана'];

        $poolId  = (int)Yii::$app->request->get('pool_id', 0);
        $page    = max(1, (int)Yii::$app->request->get('page', 1));
        $limit   = min(200, max(10, (int)Yii::$app->request->get('limit', 50)));
        $sort    = Yii::$app->request->get('sort');
        $dirRaw  = Yii::$app->request->get('dir', 'asc');
        $filters = json_decode(Yii::$app->request->get('filters', '{}'), true) ?: [];

        $sortable = ['id','ls','dc','amount','currency','value_date','post_date',
            'instruction_id','end_to_end_id','transaction_id','message_id','other_id',
            'comment','match_status','match_id','account_id'];
        $sortsRaw = json_decode((string)Yii::$app->request->get('sorts', ''), true);
        $sorts = [];
        if (is_array($sortsRaw)) {
            foreach ($sortsRaw as $item) {
                $field = is_array($item) ? ($item['field'] ?? '') : '';
                if (!in_array($field, $sortable, true) || isset($sorts[$field])) {
                    continue;
                }
                $sorts[$field] = strtolower((string)($item['dir'] ?? 'asc')) === 'desc'
                    ? SORT_DESC
                    : SORT_ASC;
            }
        }
        // Старые клиенты по-прежнему могут передавать одиночные sort/dir.
        if (!$sorts && in_array($sort, $sortable, true)) {
            $sorts[$sort] = strtolower((string)$dirRaw) === 'desc' ? SORT_DESC : SORT_ASC;
        }
        if (!$sorts) {
            $sorts = ['value_date' => SORT_ASC, 'amount' => SORT_ASC];
        }

        $q = NostroEntry::find()
            ->from(['ne' => NostroEntry::tableName()])
            ->leftJoin(['a' => 'accounts'], 'a.id = ne.account_id')
            ->leftJoin(['ap' => 'account_pools'], 'ap.id = a.pool_id')
            ->where(['ne.company_id' => $cid]);

        // pool_id — ID ностро-банка. Фильтруем по счетам этого банка.
        if ($poolId > 0) {
            $q->andWhere(['a.pool_id' => $poolId]);
        }


        // Валюта — поддерживает массив (мультивыбор) или строку
        if (!empty($filters['currency'])) {
            if (is_array($filters['currency'])) {
                $codes = array_values(array_filter(array_map(static function ($v) {
                    return strtoupper(trim((string)$v));
                }, $filters['currency']), 'strlen'));
                if (!empty($codes)) {
                    $q->andWhere(['ne.currency' => $codes]);
                }
            } else {
                $q->andWhere(['ilike', 'ne.currency', $filters['currency']]);
            }
        }

        // Текстовые ILIKE-фильтры
        foreach (['ls','dc','match_status','match_id',
                     'instruction_id','end_to_end_id','transaction_id','message_id','other_id','comment'] as $f) {
            if (isset($filters[$f]) && $filters[$f] !== '') {
                $q->andWhere(['ilike', "ne.$f", $filters[$f]]);
            }
        }
        if (isset($filters['account_pool_id']) && $filters['account_pool_id'] !== '') {
            $q->andWhere(['a.pool_id' => (int)$filters['account_pool_id']]);
        }
        if (isset($filters['account_id']) && $filters['account_id'] !== '') {
            $q->andWhere(['ne.account_id' => (int)$filters['account_id']]);
        }
        if (isset($filters['amount_min']) && $filters['amount_min'] !== '') {
            $q->andWhere(['>=', 'ne.amount', (float)$filters['amount_min']]);
        }
        if (isset($filters['amount_max']) && $filters['amount_max'] !== '') {
            $q->andWhere(['<=', 'ne.amount', (float)$filters['amount_max']]);
        }
        if (!empty($filters['value_date_from'])) $q->andWhere(['>=', 'ne.value_date', $filters['value_date_from']]);
        if (!empty($filters['value_date_to']))   $q->andWhere(['<=', 'ne.value_date', $filters['value_date_to']]);
        if (!empty($filters['post_date_from']))  $q->andWhere(['>=', 'ne.post_date', $filters['post_date_from']]);
        if (!empty($filters['post_date_to']))    $q->andWhere(['<=', 'ne.post_date', $filters['post_date_to']]);

        // Считаем total через о��дельный запрос
        $total = (int)(clone $q)->count('ne.id');

        $order = [];
        foreach ($sorts as $field => $direction) {
            $order["ne.{$field}"] = $direction;
        }
        // Стабильный порядок обязателен для корректной постраничной подгрузки.
        if (!isset($sorts['id'])) {
            $order['ne.id'] = SORT_ASC;
        }
        $rows = $q
            ->select([
                'ne.*',
                'a.name AS account_name',
                'a.is_suspense AS account_is_suspense',
                'a.pool_id AS pool_id',
                'ap.name AS pool_name',
            ])
            ->orderBy($order)
            ->offset(($page - 1) * $limit)
            ->limit($limit)
            ->asArray()
            ->all();

        return [
            'success' => true,
            'data'    => $rows,
            'total'   => $total,
            'page'    => $page,
            'limit'   => $limit,
            'pages'   => (int)ceil($total / max(1, $limit)),
        ];
    }

    /**
     * Ищет счета для Select2/autocomplete на странице выверки.
     *
     * GET `/nostro-entry/search-accounts?pool_id=&q=`.
     *
     * @return array JSON в формате Select2 `results`.
     */
    public function actionSearchAccounts(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $cid    = $this->cid();
        $poolId = (int)Yii::$app->request->get('pool_id', 0);
        $q      = trim(Yii::$app->request->get('q', ''));

        $query = Account::find()->where(['company_id' => $cid]);

        if ($poolId > 0) {
            $query->andWhere(['pool_id' => $poolId]);
        }

        if ($q !== '') $query->andWhere(['ilike', 'name', $q]);

        $items = [];
        foreach ($query->orderBy('name')->limit(40)->all() as $acc) {
            $items[] = [
                'id'          => $acc->id,
                'text'        => $acc->name,
                'currency'    => $acc->currency ?? '',
                'is_suspense' => (bool)$acc->is_suspense,
            ];
        }
        return ['results' => $items];
    }

    /**
     * Создаёт активную запись выверки вручную.
     *
     * POST `/nostro-entry/create`. Счёт проверяется внутри текущей компании,
     * сумма нормализуется как decimal-строка, а аудит создания пишет модель.
     *
     * @return array JSON с созданной строкой или ошибками валидации.
     */
    public function actionCreate(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $cid = $this->cid();
        if (!$cid) return ['success' => false, 'message' => 'Компания не выбрана'];

        $p = Yii::$app->request->post();
        $accountId = (int)($p['account_id'] ?? 0);
        $account = Account::findOne(['id' => $accountId, 'company_id' => $cid]);
        if (!$account) {
            return ['success' => false, 'message' => 'Счёт не найден'];
        }

        $m = new NostroEntry();
        $m->company_id     = $cid;
        $m->account_id     = $accountId;
        $m->ls             = $p['ls']       ?? 'L';
        $m->dc             = $p['dc']       ?? 'Debit';
        $m->amount         = $this->normalizeDecimalInput($p['amount'] ?? '0');
        $m->currency       = strtoupper(trim($p['currency'] ?? ''));
        $m->value_date     = ($p['value_date']     ?? '') ?: null;
        $m->post_date      = ($p['post_date']      ?? '') ?: null;
        $m->instruction_id = ($p['instruction_id'] ?? '') ?: null;
        $m->end_to_end_id  = ($p['end_to_end_id']  ?? '') ?: null;
        $m->transaction_id = ($p['transaction_id'] ?? '') ?: null;
        $m->message_id     = ($p['message_id']     ?? '') ?: null;
        $m->other_id       = ($p['other_id']       ?? '') ?: null;
        $m->comment        = ($p['comment']        ?? '') ?: null;
        $m->match_status   = NostroEntry::STATUS_UNMATCHED;

        if (!$m->save()) {
            return ['success' => false, 'message' => $this->firstModelError($m->errors) ?: 'Ошибка', 'errors' => $m->errors];
        }

        $row = $m->toArray();
        $row['account_name']        = $account->name;
        $row['account_is_suspense'] = (bool)$account->is_suspense;
        return ['success' => true, 'message' => 'Запись добавлена', 'data' => $row];
    }

    /**
     * Обновляет активную запись выверки.
     *
     * POST `/nostro-entry/update`. Нельзя менять сумму у уже сквитованной
     * записи; остальные поля сохраняются с автоматическим аудитом модели.
     *
     * @return array JSON с обновлённой строкой или ошибкой.
     */
    public function actionUpdate(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $cid = $this->cid();
        $p   = Yii::$app->request->post();
        $m   = NostroEntry::findOne(['id' => (int)($p['id'] ?? 0), 'company_id' => $cid]);
        if (!$m) return ['success' => false, 'message' => 'Запись не найдена'];

        $accountId = (int)($p['account_id'] ?? $m->account_id);
        $account = Account::findOne(['id' => $accountId, 'company_id' => $cid]);
        if (!$account) {
            return ['success' => false, 'message' => 'Счёт не найден'];
        }

        $m->account_id     = $accountId;
        $m->ls             = $p['ls']                  ?? $m->ls;
        $m->dc             = $p['dc']                  ?? $m->dc;
        if ($m->match_status !== NostroEntry::STATUS_MATCHED) {
            $m->amount     = $this->normalizeDecimalInput($p['amount'] ?? $m->amount);
        }
        $m->currency       = strtoupper(trim($p['currency'] ?? $m->currency));
        $m->value_date     = ($p['value_date']         ?? '') ?: null;
        $m->post_date      = ($p['post_date']          ?? '') ?: null;
        $m->instruction_id = ($p['instruction_id']     ?? '') ?: null;
        $m->end_to_end_id  = ($p['end_to_end_id']      ?? '') ?: null;
        $m->transaction_id = ($p['transaction_id']     ?? '') ?: null;
        $m->message_id     = ($p['message_id']         ?? '') ?: null;
        if (array_key_exists('statement_number', $p)) {
            $m->statement_number = ($p['statement_number'] ?? '') ?: null;
        }
        $m->other_id       = ($p['other_id']           ?? '') ?: null;
        $m->comment        = ($p['comment']            ?? '') ?: null;

        if (!$m->save()) {
            return ['success' => false, 'message' => $this->firstModelError($m->errors) ?: 'Ошибка', 'errors' => $m->errors];
        }

        $row = $m->toArray();
        $row['account_name']        = $account->name;
        $row['account_is_suspense'] = (bool)$account->is_suspense;
        return ['success' => true, 'message' => 'Запись обновлена', 'data' => $row];
    }

    /**
     * Удаляет несквитованную запись выверки.
     *
     * POST `/nostro-entry/delete`. Сквитованные записи не удаляются из
     * активной таблицы напрямую; для них используется архивирование.
     *
     * @return array JSON-результат удаления.
     */
    public function actionDelete(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $cid = $this->cid();
        $m   = NostroEntry::findOne(['id' => (int)Yii::$app->request->post('id'), 'company_id' => $cid]);
        if (!$m) return ['success' => false, 'message' => 'Запись не найдена'];
        if ($m->match_status === NostroEntry::STATUS_MATCHED)
            return ['success' => false, 'message' => 'Нельзя удалить сквитованную запись'];
        $m->delete();
        return ['success' => true, 'message' => 'Запись удалена'];
    }

    /**
     * Нормализует пользовательский ввод суммы в decimal-строку.
     *
     * Поддерживает пробелы, запятую как десятичный разделитель и разделители
     * тысяч, не приводя значение к `float` перед валидацией модели.
     *
     * @param mixed $value Исходное значение из request.
     * @return string Нормализованная decimal-строка.
     */
    private function normalizeDecimalInput($value): string
    {
        $s = trim((string)$value);
        if ($s === '') {
            return '';
        }

        $s = preg_replace('/\s+/u', '', $s);
        $hasDot = strpos($s, '.') !== false;
        $hasComma = strpos($s, ',') !== false;

        if ($hasDot && $hasComma) {
            $lastDot = strrpos($s, '.');
            $lastComma = strrpos($s, ',');
            if ($lastComma > $lastDot) {
                $s = str_replace('.', '', $s);
                $pos = strrpos($s, ',');
                $s = str_replace(',', '', substr($s, 0, $pos)) . '.' . substr($s, $pos + 1);
            } else {
                $s = str_replace(',', '', $s);
            }
        } elseif ($hasComma) {
            $commaCount = substr_count($s, ',');
            $afterLast = substr($s, strrpos($s, ',') + 1);
            if ($commaCount === 1 && strlen($afterLast) <= 5) {
                $s = str_replace(',', '.', $s);
            } else {
                $s = str_replace(',', '', $s);
            }
        }

        if (strpos($s, '.') === 0) {
            $s = '0' . $s;
        }

        return $s;
    }

    /**
     * Возвращает первую ошибку валидации модели.
     *
     * @param array $errors Массив ошибок Yii `Model::$errors`.
     * @return string|null Текст первой ошибки или `null`.
     */
    private function firstModelError(array $errors): ?string
    {
        foreach ($errors as $messages) {
            if (!empty($messages[0])) {
                return $messages[0];
            }
        }
        return null;
    }

    /**
     * Обновляет только комментарий активной записи.
     *
     * POST `/nostro-entry/update-comment`. Используется быстрым редактированием
     * комментариев; сохраняет модель без валидации остальных полей.
     *
     * @return array JSON-результат обновления.
     */
    public function actionUpdateComment(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $cid = $this->cid();
        $m   = NostroEntry::findOne(['id' => (int)Yii::$app->request->post('id'), 'company_id' => $cid]);
        if (!$m) return ['success' => false, 'message' => 'Запись не найдена'];
        $m->comment = Yii::$app->request->post('comment') ?: null;
        $m->save(false);
        return ['success' => true];
    }

    /**
     * Возвращает историю изменений активной записи.
     *
     * GET `/nostro-entry/history?id=`.
     *
     * Логика:
     *  1. Загружаем все аудит-записи (ASC — от старых к новым).
     *  2. Группируем по (created_at до секунды + user_id + action):
     *     одно редактирование может создавать несколько строк (по одной на поле).
     *  3. Реконструируем полный снапшот записи на момент каждого изменения:
     *     идём снизу вверх, накапливая текущее состояние.
     *  4. Возвращаем результат в DESC-порядке (новые сверху).
     *
     * @return array JSON со снапшотами записи после каждого события аудита.
     */
    public function actionHistory(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $cid = $this->cid();
        $id  = (int)Yii::$app->request->get('id', 0);

        $entry = NostroEntry::findOne(['id' => $id, 'company_id' => $cid]);
        if (!$entry) {
            return ['success' => false, 'message' => 'Запись не найдена'];
        }

        // Загружаем от старых к новым (ASC) — нужно для реконструкции снапшотов.
        // Для восстановленных из архива записей подтягиваем и прежнюю цепочку по original_id.
        $audits = $this->findEntryHistoryAudits($id);

        if (empty($audits)) {
            return ['success' => true, 'data' => []];
        }

        // ── Кэш пользователей (username / email) ─────────────────────────
        $userIds = array_unique(array_column($audits, 'user_id'));
        $users   = [];
        if (!empty($userIds)) {
            $userRows = \app\models\User::find()
                ->select(['id', 'username', 'email'])
                ->where(['id' => $userIds])
                ->asArray()
                ->all();
            foreach ($userRows as $u) {
                $users[$u['id']] = $u['username'] ?: $u['email'];
            }
        }

        // ── Группировка аудитов (по created_at до секунды + user_id + action) ─
        // Ключ группы: "YYYY-MM-DD HH:MM:SS|user_id|action"
        $groups = [];   // ['key' => ['meta' => ..., 'changes' => [field => [old, new]]]]
        $groupOrder = [];

        foreach ($audits as $audit) {
            // Приводим created_at к точности до секунды (обрезаем дробную часть если есть)
            $ts  = substr($audit['created_at'], 0, 19);
            $key = $ts . '|' . $audit['user_id'] . '|' . $audit['action'];

            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'key'        => $key,
                    'action'     => $audit['action'],
                    'user_id'    => $audit['user_id'],
                    'username'   => $users[$audit['user_id']] ?? ('User #' . $audit['user_id']),
                    'reason'     => $audit['reason'],
                    'created_at' => $ts,
                    'changes'    => [],   // field => ['old' => ..., 'new' => ...]
                    // Снапшот всей записи будет добавлен ниже
                    'snapshot'      => [],
                    'changed_fields' => [],
                ];
                $groupOrder[] = $key;
            }

            // Накапливаем изменения в группе
            $field   = $audit['changed_field'];
            $oldVals = $audit['old_values'] ? json_decode($audit['old_values'], true) : null;
            $newVals = $audit['new_values'] ? json_decode($audit['new_values'], true) : null;

            if ($field) {
                $oldV = is_array($oldVals) ? ($oldVals[$field] ?? null) : null;
                $newV = is_array($newVals) ? ($newVals[$field] ?? null) : null;
                $groups[$key]['changes'][$field] = ['old' => $oldV, 'new' => $newV];
                $groups[$key]['changed_fields'][] = $field;
            } elseif ($oldVals || $newVals) {
                // create / delete — берём весь объект
                $groups[$key]['changes'] = array_merge(
                    $groups[$key]['changes'],
                    array_map(function($k) use ($oldVals, $newVals) {
                        return [
                            'old' => is_array($oldVals) ? ($oldVals[$k] ?? null) : null,
                            'new' => is_array($newVals) ? ($newVals[$k] ?? null) : null,
                        ];
                    }, array_unique(array_merge(
                        is_array($oldVals) ? array_keys($oldVals) : [],
                        is_array($newVals) ? array_keys($newVals) : []
                    )))
                );
            }
        }

        // ── Реконструкция снапшотов (от старых к новым) ──────────────────
        // Поля, которые показываем в таблице
        $fields = ['account_id', 'ls', 'dc', 'amount', 'currency',
            'value_date', 'post_date', 'instruction_id', 'end_to_end_id',
            'transaction_id', 'message_id', 'other_id', 'comment', 'branch_code',
            'match_status', 'match_id', 'matched_at'];

        // Стартовое состояние — текущие данные записи (самое актуальное)
        // Идём от новых к старым, чтобы вычислить снапшоты в обратном порядке.
        // Проще: идём ASC (groupOrder уже ASC), строим «живое» состояние нарастающим итогом.
        $current = [];
        foreach ($fields as $f) {
            $current[$f] = null;
        }

        // Инициализируем из первого события (create) если есть, иначе берём текущую запись
        $firstGroup = $groups[$groupOrder[0]];
        if ($firstGroup['action'] === NostroEntryAudit::ACTION_CREATE) {
            foreach ($fields as $f) {
                $v = $firstGroup['changes'][$f]['new'] ?? null;
                $current[$f] = $v;
            }
        } else {
            // Нет события создания — берём данные живой записи и "откатываемся" назад
            // Проще: просто используем current entry как базу, идём ASC, применяем изменения
            foreach ($fields as $f) {
                $current[$f] = $entry->$f ?? null;
            }
            // Откатываем к начальному состоянию: идём по всем аудитам и инвертируем
            foreach (array_reverse($groupOrder) as $key) {
                foreach ($groups[$key]['changes'] as $field => $change) {
                    if (array_key_exists($field, array_flip($fields))) {
                        $current[$field] = $change['old'];
                    }
                }
            }
        }

        // Теперь идём ASC и для каждой группы записываем снапшот ПОСЛЕ применения изменений
        foreach ($groupOrder as $key) {
            $group  = &$groups[$key];
            $action = $group['action'];

            if ($action === NostroEntryAudit::ACTION_CREATE) {
                // Снапшот = new_values из create
                foreach ($fields as $f) {
                    $current[$f] = $group['changes'][$f]['new'] ?? $current[$f];
                }
                $group['snapshot'] = $current;
            } elseif ($action === NostroEntryAudit::ACTION_DELETE
                || $action === NostroEntryAudit::ACTION_ARCHIVE) {
                // Снапшот = состояние до удаления/архивации (old_values)
                $snap = $current;
                foreach ($group['changes'] as $field => $change) {
                    if (in_array($field, $fields, true) && $change['old'] !== null) {
                        $snap[$field] = $change['old'];
                    }
                }
                if ($action === NostroEntryAudit::ACTION_ARCHIVE) {
                    $snap['match_status'] = 'A';
                }
                $group['snapshot'] = $snap;
                $current = $snap;
            } else {
                // update / restore — применяем new_values к текущему состоянию
                foreach ($group['changes'] as $field => $change) {
                    if (in_array($field, $fields, true)) {
                        $current[$field] = $change['new'];
                    }
                }
                $group['snapshot'] = $current;
            }
            unset($group);
        }

        // ── Кэш имён счетов ──────────────────────────────────────────────
        $accountIds = [];
        foreach ($groups as $g) {
            if (!empty($g['snapshot']['account_id'])) {
                $accountIds[] = (int)$g['snapshot']['account_id'];
            }
            if (!empty($g['changes']['account_id'])) {
                foreach (['old', 'new'] as $side) {
                    if (!empty($g['changes']['account_id'][$side])) {
                        $accountIds[] = (int)$g['changes']['account_id'][$side];
                    }
                }
            }
        }
        $accountIds = array_unique($accountIds);
        $accountNames = [];
        if (!empty($accountIds)) {
            $accRows = \app\models\Account::find()
                ->select(['id', 'name'])
                ->where(['company_id' => $cid, 'id' => $accountIds])
                ->asArray()
                ->all();
            foreach ($accRows as $a) {
                $accountNames[$a['id']] = $a['name'];
            }
        }

        // ── Формируем результат (DESC — новые сверху) ─────────────────────
        $rows = [];
        foreach (array_reverse($groupOrder) as $key) {
            $g = $groups[$key];
            $snap = $g['snapshot'];
            $changes = $g['changes'];
            // Добавляем удобочитаемое имя счёта
            if (!empty($snap['account_id'])) {
                $snap['account_name'] = $accountNames[$snap['account_id']] ?? ('ID: ' . $snap['account_id']);
            } else {
                $snap['account_name'] = '—';
            }
            if (!empty($changes['account_id'])) {
                foreach (['old', 'new'] as $side) {
                    $accountId = $changes['account_id'][$side] ?? null;
                    $changes['account_id'][$side . '_name'] = $accountId
                        ? ($accountNames[(int)$accountId] ?? ('ID: ' . $accountId))
                        : null;
                }
            }
            $rows[] = [
                'action'         => $g['action'],
                'user_id'        => $g['user_id'],
                'username'       => $g['username'],
                'reason'         => $g['reason'],
                'created_at'     => $g['created_at'],
                'changed_fields' => array_values(array_unique($g['changed_fields'])),
                'snapshot'       => $snap,
                'changes'        => $changes,
            ];
        }

        return ['success' => true, 'data' => $rows];
    }

    /**
     * Находит аудит активной записи с учётом восстановлений из архива.
     *
     * Если текущая запись была восстановлена, метод подтягивает предыдущую
     * цепочку аудита по `restore.old_values.original_id` и убирает техническое
     * create-событие новой физической строки.
     *
     * @param int $entryId ID активной записи.
     * @return array События аудита в порядке от старых к новым.
     */
    private function findEntryHistoryAudits(int $entryId): array
    {
        $currentAudits = NostroEntryAudit::find()
            ->where(['entry_id' => $entryId])
            ->orderBy(['created_at' => SORT_ASC, 'id' => SORT_ASC])
            ->asArray()
            ->all();

        $originalIds = [];
        foreach ($currentAudits as $audit) {
            if (($audit['action'] ?? '') !== NostroEntryAudit::ACTION_RESTORE) {
                continue;
            }

            $oldValues = !empty($audit['old_values']) ? json_decode($audit['old_values'], true) : null;
            if (is_array($oldValues) && !empty($oldValues['original_id'])) {
                $originalIds[] = (int)$oldValues['original_id'];
            }
        }

        $originalIds = array_values(array_unique(array_filter($originalIds)));
        if (empty($originalIds)) {
            return $currentAudits;
        }

        $previousAudits = $this->findPreviousAuditsForOriginalIds($originalIds);

        if (!empty($previousAudits)) {
            $currentAudits = array_values(array_filter($currentAudits, function ($audit) use ($entryId) {
                return !(($audit['action'] ?? '') === NostroEntryAudit::ACTION_CREATE
                    && (int)($audit['entry_id'] ?? 0) === $entryId);
            }));
        }

        $audits = array_merge($previousAudits, $currentAudits);
        usort($audits, function ($a, $b) {
            $dateCmp = strcmp((string)$a['created_at'], (string)$b['created_at']);
            if ($dateCmp !== 0) {
                return $dateCmp;
            }
            return (int)$a['id'] <=> (int)$b['id'];
        });

        return $audits;
    }

    /**
     * Загружает предыдущие события аудита для исходных ID архивных записей.
     *
     * Учитывает обычные события по `entry_id` и события с `entry_id IS NULL`,
     * где исходный ID сохранён внутри JSON-снимков.
     *
     * @param int[] $originalIds ID исходных записей до восстановления.
     * @return array События аудита, отсортированные от старых к новым.
     */
    private function findPreviousAuditsForOriginalIds(array $originalIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $originalIds)));
        if (empty($ids)) {
            return [];
        }

        $idList = implode(',', $ids);
        $idTextList = implode(',', array_map(function ($id) {
            return Yii::$app->db->quoteValue((string)$id);
        }, $ids));

        return Yii::$app->db->createCommand(
            "SELECT *
               FROM {{%nostro_entry_audit}}
              WHERE entry_id IN ({$idList})
                 OR (entry_id IS NULL AND (
                        (old_values IS NOT NULL AND old_values::jsonb ->> 'id' IN ({$idTextList}))
                     OR (new_values IS NOT NULL AND new_values::jsonb ->> 'id' IN ({$idTextList}))
                 ))
              ORDER BY created_at ASC, id ASC"
        )->queryAll();
    }
}
