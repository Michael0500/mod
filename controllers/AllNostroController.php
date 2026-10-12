<?php
namespace app\controllers;

use Yii;
use yii\web\Response;
use app\models\NostroEntry;
use app\models\Account;
use app\models\AccountPool;

/**
 * Контроллер страницы "Выверка по всем ностро-банкам".
 *
 * Страница показывает активные `NostroEntry` текущей компании сразу по всем
 * ностро-банкам и добавляет фильтры по множеству `pool_ids` и конкретному
 * счёту, которых нет на основной странице выверки.
 */
class AllNostroController extends BaseController
{
    /**
     * Отключает CSRF для JSON API страницы.
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
     * @return int|null ID компании или `null`.
     */
    private function cid(): ?int
    {
        $u = Yii::$app->user->identity;
        return ($u && $u->company_id) ? (int)$u->company_id : null;
    }

    /**
     * Рендерит standalone-страницу выверки по всем ностро-банкам.
     *
     * GET `/all-nostro`. Передаёт во Vue начальный список ностро-банков
     * текущей компании.
     *
     * @return string|\yii\web\Response HTML-страница или redirect на выбор компании.
     */
    public function actionIndex()
    {
        $cid = $this->cid();
        if (!$cid) {
            Yii::$app->session->setFlash('warning', 'Выберите компанию.');
            return $this->redirect(['/site/index']);
        }

        $pools = AccountPool::find()
            ->where(['company_id' => $cid])
            ->orderBy(['name' => SORT_ASC])
            ->all();

        $batchId = (int)Yii::$app->request->get('batch_id', 0);

        $initData = [
            'pools' => array_map(function ($p) {
                return ['id' => $p->id, 'name' => $p->name];
            }, $pools),
            // Предустановленный фильтр по номеру пакета (переход со страницы отката).
            'batchId' => $batchId ?: null,
        ];

        return $this->render('index', ['initData' => $initData]);
    }

    /**
     * Возвращает постраничный список операций по всем ностро-банкам.
     *
     * GET `/all-nostro/list`. Параметр `filters` содержит JSON с
     * `pool_ids[]`, `account_id` и стандартными фильтрами таблицы выверки.
     *
     * @return array JSON с данными, total, page, limit и pages.
     */
    public function actionList(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $cid = $this->cid();
        if (!$cid) return ['success' => false, 'message' => 'Компания не выбрана'];

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

        // Мультивыбор ностро-банков
        if (isset($filters['pool_ids']) && is_array($filters['pool_ids']) && !empty($filters['pool_ids'])) {
            $poolIds = array_values(array_filter(array_map('intval', $filters['pool_ids'])));
            if (!empty($poolIds)) {
                $q->andWhere(['a.pool_id' => $poolIds]);
            }
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
        if (isset($filters['account_id']) && $filters['account_id'] !== '') {
            $q->andWhere(['ne.account_id' => (int)$filters['account_id']]);
        }
        // Номер пакета загрузки (tds_status.id)
        if (isset($filters['batch_id']) && $filters['batch_id'] !== '') {
            $q->andWhere(['ne.batch_id' => (int)$filters['batch_id']]);
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

        $total = (int)(clone $q)->count('ne.id');

        $order = [];
        foreach ($sorts as $field => $direction) {
            $order["ne.{$field}"] = $direction;
        }
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
     * Ищет счета для Select2 с учётом выбранных ностро-банков.
     *
     * GET `/all-nostro/search-accounts?q=&pool_ids[]=`.
     *
     * @return array JSON в формате Select2 `results`.
     */
    public function actionSearchAccounts(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $cid     = $this->cid();
        $q       = trim(Yii::$app->request->get('q', ''));
        $poolIds = (array)Yii::$app->request->get('pool_ids', []);
        $poolIds = array_values(array_filter(array_map('intval', $poolIds)));

        $query = Account::find()->where(['company_id' => $cid]);
        if (!empty($poolIds)) {
            $query->andWhere(['pool_id' => $poolIds]);
        }
        if ($q !== '') {
            $query->andWhere(['ilike', 'name', $q]);
        }

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
}
