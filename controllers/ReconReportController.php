<?php

namespace app\controllers;

use Yii;
use yii\web\Response;
use app\models\Account;
use app\models\AccountPool;
use app\models\Category;
use app\models\NostroBalance;
use app\models\NostroEntry;
use Mpdf\Mpdf;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Контроллер Reconciliation Report (раккорд) для раздела NRE.
 *
 * Формирует отчёт вручную по выбранной категории или ностро-банку. Одна
 * карточка отчёта соответствует одному `AccountPool` и агрегирует все его
 * Ledger/Statement-счета. Экспорт поддерживает XLSX, PDF и ZIP для нескольких
 * ностро-банков.
 */
class ReconReportController extends BaseController
{
    /**
     * Отключает CSRF для JSON API генерации отчёта.
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
    private function cid()
    {
        $u = Yii::$app->user->identity;
        return ($u && $u->company_id) ? (int)$u->company_id : null;
    }

    /**
     * Рендерит страницу Reconciliation Report.
     *
     * GET `/recon-report`. Передаёт во Vue списки категорий и ностро-банков
     * текущей компании.
     *
     * @return string|\yii\web\Response HTML-страница или redirect на выбор компании.
     */
    public function actionIndex()
    {
        $cid = $this->cid();
        if (!$cid) {
            Yii::$app->session->setFlash('warning', 'Выберите компанию для работы с отчётами.');
            return $this->redirect(['/site/index']);
        }

        $pools = AccountPool::find()
            ->where(['company_id' => $cid])
            ->orderBy(['name' => SORT_ASC])
            ->all();

        $categories = Category::find()
            ->where(['company_id' => $cid])
            ->orderBy(['name' => SORT_ASC])
            ->all();

        return $this->render('index', [
            'initData' => [
                'pools' => array_map(function ($p) {
                    return ['id' => (int)$p->id, 'name' => $p->name];
                }, $pools),
                'categories' => array_map(function ($c) {
                    return ['id' => (int)$c->id, 'name' => $c->name];
                }, $categories),
            ],
        ]);
    }

    /**
     * Генерирует данные отчёта без файлового экспорта.
     *
     * POST `/recon-report/generate`. Возвращает одну или несколько карточек
     * отчёта по выбранной категории/ностро-банку и параметрам даты.
     *
     * @return array JSON-результат генерации.
     */
    public function actionGenerate()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $cid = $this->cid();
        if (!$cid) {
            return ['success' => false, 'message' => 'Компания не выбрана'];
        }

        try {
            $params = $this->readReportParams(Yii::$app->request->post());
            $generatedAt = date('Y-m-d H:i:s');
            $reports = $this->buildReports($params, $cid, $generatedAt);
            if (empty($reports)) {
                return ['success' => false, 'message' => 'Не найдено ни одного ностро-банка для формирования отчёта'];
            }

            return [
                'success' => true,
                'reports' => $reports,
                'report_level' => $this->resolveReportLevel($params['pool_id'], $params['category_id'], $cid),
                'export_params' => $params,
            ];
        } catch (\InvalidArgumentException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Экспортирует отчёт в XLSX или PDF.
     *
     * GET `/recon-report/export?format=xlsx|pdf`. Для одного отчёта возвращает
     * файл напрямую, для нескольких создаёт ZIP. Временные файлы удаляются
     * после отправки ответа.
     *
     * @return \yii\web\Response Ответ отправки файла.
     * @throws \yii\web\BadRequestHttpException При неверных параметрах.
     * @throws \yii\web\NotFoundHttpException Если отчёты не найдены.
     */
    public function actionExport()
    {
        $cid = $this->cid();
        if (!$cid) {
            throw new \yii\web\BadRequestHttpException('Компания не выбрана');
        }

        $format = strtolower((string)Yii::$app->request->get('format', 'xlsx'));
        if (!in_array($format, ['xlsx', 'pdf'], true)) {
            throw new \yii\web\BadRequestHttpException('Неверный формат выгрузки');
        }

        $params = $this->readReportParams(Yii::$app->request->get());
        $reportPoolId = (int)Yii::$app->request->get('report_pool_id', 0);
        $generatedAt = date('Y-m-d H:i:s');
        $reports = $this->buildReports($params, $cid, $generatedAt, $reportPoolId);

        if (empty($reports)) {
            throw new \yii\web\NotFoundHttpException('Не найдено ни одного ностро-банка для выгрузки');
        }

        if (count($reports) === 1) {
            $filePath = $format === 'xlsx'
                ? $this->createXlsxFile($reports[0])
                : $this->createPdfFile($reports[0]);

            return $this->sendTempFile($filePath, $this->reportFilename($reports[0], $format), $this->mimeType($format));
        }

        $zipPath = $this->createZipFile($reports, $format);
        $zipName = 'ReconReport_' . $this->safeFilename($this->resolveReportLevel($params['pool_id'], $params['category_id'], $cid)['label']) . '_' . $this->formatDate($params['date_recon']) . '.zip';
        return $this->sendTempFile($zipPath, $zipName, 'application/zip');
    }

    /**
     * Возвращает счета для выпадающих списков отчёта.
     *
     * GET `/recon-report/accounts`, опциональный `pool_id` ограничивает
     * результат одним ностро-банком.
     *
     * @return array JSON со счетами текущей компании.
     */
    public function actionAccounts()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $cid = $this->cid();
        $poolId = (int)Yii::$app->request->get('pool_id', 0);

        if (!$cid) {
            return ['success' => false, 'data' => []];
        }

        $query = Account::find()->where(['company_id' => $cid]);
        if ($poolId > 0) {
            $query->andWhere(['pool_id' => $poolId]);
        }

        return [
            'success' => true,
            'data' => array_map(function ($account) {
                return [
                    'id' => (int)$account->id,
                    'name' => $account->name,
                    'currency' => $account->currency,
                    'pool_id' => $account->pool_id,
                ];
            }, $query->orderBy(['name' => SORT_ASC])->all()),
        ];
    }

    /**
     * Читает и валидирует параметры формирования отчёта.
     *
     * Допускается выбор либо категории, либо ностро-банка. Если указан период,
     * `date_recon` автоматически берётся как `date_to`; иначе требуется
     * отдельная дата раккорда.
     *
     * @param array $source POST/GET параметры запроса.
     * @return array Нормализованные параметры отчёта.
     * @throws \InvalidArgumentException При некорректном выборе или датах.
     */
    private function readReportParams(array $source): array
    {
        $poolId = (int)($source['pool_id'] ?? 0);
        $categoryId = (int)($source['category_id'] ?? 0);
        $dateRecon = trim((string)($source['date_recon'] ?? ''));
        $dateFrom = trim((string)($source['date_from'] ?? ''));
        $dateTo = trim((string)($source['date_to'] ?? ''));

        if (($poolId > 0 && $categoryId > 0) || ($poolId <= 0 && $categoryId <= 0)) {
            throw new \InvalidArgumentException('Выберите категорию или ностро-банк');
        }

        if ($dateFrom || $dateTo) {
            if (!$dateFrom || !$dateTo) {
                throw new \InvalidArgumentException('Для периода укажите обе даты');
            }
            $this->assertDate($dateFrom, 'Неверный формат даты начала периода');
            $this->assertDate($dateTo, 'Неверный формат даты конца периода');
            if ($dateFrom > $dateTo) {
                throw new \InvalidArgumentException('Дата начала периода не может быть позже даты конца');
            }
            $dateRecon = $dateTo;
        } else {
            $this->assertDate($dateRecon, 'Неверный формат даты раккорда');
        }

        return [
            'pool_id' => $poolId,
            'category_id' => $categoryId,
            'date_recon' => $dateRecon,
            'date_from' => $dateFrom ?: null,
            'date_to' => $dateTo ?: null,
        ];
    }

    /**
     * Проверяет дату в формате `Y-m-d`.
     *
     * @param string $date Проверяемая дата.
     * @param string $message Сообщение исключения при ошибке.
     * @return void
     * @throws \InvalidArgumentException Если формат даты некорректен.
     */
    private function assertDate(string $date, string $message): void
    {
        $dt = \DateTime::createFromFormat('Y-m-d', $date);
        if (!$dt || $dt->format('Y-m-d') !== $date) {
            throw new \InvalidArgumentException($message);
        }
    }

    /**
     * Разрешает выбранную категорию или ностро-банк в список счетов.
     *
     * Категория разворачивается в ностро-банки, а ностро-банк — во все счета
     * `accounts.pool_id`. Все счета ограничены текущей компанией.
     *
     * @param int $poolId ID ностро-банка или 0.
     * @param int $categoryId ID категории или 0.
     * @param int $cid ID компании.
     * @return Account[] Счета, участвующие в отчёте.
     */
    private function resolveAccounts(int $poolId, int $categoryId, int $cid): array
    {
        if ($poolId > 0) {
            return Account::find()
                ->where(['company_id' => $cid, 'pool_id' => $poolId])
                ->orderBy(['name' => SORT_ASC])
                ->all();
        }

        $category = Category::findOne(['id' => $categoryId, 'company_id' => $cid]);
        if (!$category) {
            return [];
        }

        $poolIds = AccountPool::find()
            ->select('id')
            ->where(['category_id' => $categoryId, 'company_id' => $cid])
            ->column();

        if (empty($poolIds)) {
            return [];
        }

        $accounts = Account::find()
            ->with('pool')
            ->where(['pool_id' => $poolIds, 'company_id' => $cid])
            ->all();

        usort($accounts, function ($a, $b) {
            $poolA = $a->pool ? $a->pool->name : '';
            $poolB = $b->pool ? $b->pool->name : '';
            return strcmp($poolA . $a->name, $poolB . $b->name);
        });

        return $accounts;
    }

    /**
     * Возвращает один счёт текущей компании.
     *
     * Метод оставлен как вспомогательный контракт для возможного режима
     * отчёта по отдельному счёту.
     *
     * @param int $accountId ID счёта.
     * @param int $cid ID компании.
     * @return Account[] Массив из одного счёта или пустой массив.
     */
    private function resolveSingleAccount(int $accountId, int $cid): array
    {
        $account = Account::findOne(['id' => $accountId, 'company_id' => $cid]);
        return $account ? [$account] : [];
    }

    /**
     * Возвращает тип и подпись уровня отчёта.
     *
     * @param int $poolId ID ностро-банка или 0.
     * @param int $categoryId ID категории или 0.
     * @param int $cid ID компании.
     * @return array Структура `type` и `label`.
     */
    private function resolveReportLevel(int $poolId, int $categoryId, int $cid): array
    {
        if ($poolId > 0) {
            $pool = AccountPool::findOne(['id' => $poolId, 'company_id' => $cid]);
            return ['type' => 'pool', 'label' => $pool ? $pool->name : ''];
        }

        $category = Category::findOne(['id' => $categoryId, 'company_id' => $cid]);
        return ['type' => 'category', 'label' => $category ? $category->name : ''];
    }

    /**
     * Строит набор отчётов по выбранной области.
     *
     * Для категории создаёт отдельный отчёт по каждому ностро-банку. Параметр
     * `onlyPoolId` используется экспортом одной карточки из уже выбранной области.
     *
     * @param array $params Нормализованные параметры отчёта.
     * @param int $cid ID компании.
     * @param string $generatedAt Timestamp формирования.
     * @param int $onlyPoolId Ограничение одним ностро-банком для экспорта.
     * @return array Список структур отчёта.
     */
    private function buildReports(array $params, int $cid, string $generatedAt, int $onlyPoolId = 0): array
    {
        $accounts = $this->resolveAccounts($params['pool_id'], $params['category_id'], $cid);
        $groups = $this->groupAccountsByPool($accounts, $cid);
        $reports = [];

        foreach ($groups as $poolId => $group) {
            if ($onlyPoolId > 0 && (int)$poolId !== $onlyPoolId) {
                continue;
            }
            $reports[] = $this->buildReportData($group['pool'], $group['accounts'], $params, $cid, $generatedAt);
        }

        return $reports;
    }

    /**
     * Группирует счета по ностро-банкам.
     *
     * @param Account[] $accounts Счета выбранной области.
     * @param int $cid ID компании.
     * @return array Карта `pool_id => ['pool' => AccountPool, 'accounts' => Account[]]`.
     */
    private function groupAccountsByPool(array $accounts, int $cid): array
    {
        $groups = [];
        foreach ($accounts as $account) {
            $poolId = (int)$account->pool_id;
            if ($poolId <= 0) {
                continue;
            }

            if (!isset($groups[$poolId])) {
                $pool = AccountPool::findOne(['id' => $poolId, 'company_id' => $cid]);
                if (!$pool) {
                    continue;
                }
                $groups[$poolId] = ['pool' => $pool, 'accounts' => []];
            }
            $groups[$poolId]['accounts'][] = $account;
        }

        uasort($groups, function ($a, $b) {
            return strcmp($a['pool']->name, $b['pool']->name);
        });

        return $groups;
    }

    /**
     * Формирует данные одной карточки Reconciliation Report.
     *
     * Учитывает только несквитованные записи без `match_id` и со статусом `U`.
     * В режиме даты берутся предыдущий день и дата раккорда на момент
     * формирования; в режиме периода берётся диапазон `date_from..date_to`.
     *
     * @param AccountPool $pool Ностро-банк карточки.
     * @param Account[] $accounts Счета ностро-банка.
     * @param array $params Параметры отчёта.
     * @param int $cid ID компании.
     * @param string $generatedAt Timestamp формирования.
     * @return array Данные отчёта для UI и экспорта.
     */
    private function buildReportData(AccountPool $pool, array $accounts, array $params, int $cid, string $generatedAt): array
    {
        $dateRecon = $params['date_recon'];
        $dateFrom = $params['date_from'];
        $dateTo = $params['date_to'];
        $prevDay = (new \DateTime($dateRecon))->modify('-1 day')->format('Y-m-d');
        $balanceDate = $dateTo ?: $dateRecon;
        $accountIds = array_map(function ($account) {
            return (int)$account->id;
        }, $accounts);
        $accountNames = array_map(function ($account) {
            return $account->name;
        }, $accounts);
        $currency = $this->commonCurrency($accounts);

        $cbLedgerAmt = $this->sumClosingBalance($accountIds, $cid, NostroBalance::LS_LEDGER, $balanceDate);
        $cbStatAmt = $this->sumClosingBalance($accountIds, $cid, NostroBalance::LS_STATEMENT, $balanceDate);
        $cbDiff = ($cbLedgerAmt !== null && $cbStatAmt !== null) ? $cbLedgerAmt - $cbStatAmt : null;

        $query = NostroEntry::find()
            ->from(['ne' => NostroEntry::tableName()])
            ->where(['ne.account_id' => $accountIds, 'ne.company_id' => $cid])
            ->andWhere(['or', ['ne.match_id' => null], ['ne.match_id' => '']])
            ->andWhere(['ne.match_status' => NostroEntry::STATUS_UNMATCHED])
            ->orderBy(['ne.value_date' => SORT_ASC, 'ne.id' => SORT_ASC]);

        if ($dateFrom && $dateTo) {
            $query->andWhere(['between', 'DATE(ne.value_date)', $dateFrom, $dateTo]);
        } else {
            $query->andWhere(['in', 'DATE(ne.value_date)', [$prevDay, $dateRecon]])
                ->andWhere(['<=', 'ne.created_at', $generatedAt]);
        }

        $sections = [
            'ledger_debit' => [],
            'ledger_credit' => [],
            'stmt_debit' => [],
            'stmt_credit' => [],
        ];

        foreach ($query->all() as $entry) {
            $row = [
                'value' => $entry->value_date,
                'instruction_id' => $entry->instruction_id,
                'end_to_end_id' => $entry->end_to_end_id,
                'transaction_id' => $entry->transaction_id,
                'message_id' => $entry->message_id,
                'dc' => $entry->dc,
                'amount' => 0,
                'account' => $entry->account ? $entry->account->name : '',
            ];

            if ($entry->ls === NostroEntry::LS_LEDGER && $entry->dc === NostroEntry::DC_DEBIT) {
                $row['amount'] = -(float)$entry->amount;
                $sections['ledger_debit'][] = $row;
            } elseif ($entry->ls === NostroEntry::LS_LEDGER) {
                $row['amount'] = (float)$entry->amount;
                $sections['ledger_credit'][] = $row;
            } elseif ($entry->dc === NostroEntry::DC_DEBIT) {
                $row['amount'] = (float)$entry->amount;
                $sections['stmt_debit'][] = $row;
            } else {
                $row['amount'] = (float)$entry->amount;
                $sections['stmt_credit'][] = $row;
            }
        }

        $netLedgerDebit = $this->sumAmount($sections['ledger_debit']);
        $netLedgerCredit = $this->sumAmount($sections['ledger_credit']);
        $netStmtDebit = $this->sumAmount($sections['stmt_debit']);
        $netStmtCredit = $this->sumAmount($sections['stmt_credit']);
        $ledgerNetAmount = $netLedgerDebit - $netLedgerCredit;
        $stmtNetAmount = $netStmtDebit - $netStmtCredit;
        $oiLedger = $ledgerNetAmount;
        $oiStatement = $stmtNetAmount;
        $oiDiff = $oiLedger - $oiStatement;
        $tbLedger = ($cbLedgerAmt ?? 0) + $oiLedger;
        $tbStmt = ($cbStatAmt ?? 0) + $oiStatement;

        return [
            'generated_at' => $generatedAt,
            'date_recon' => $dateRecon,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'company' => 'NRE',
            'nostro_bank' => $pool->name,
            'account_name' => implode(', ', $accountNames),
            'account_count' => count($accounts),
            'pool_id' => (int)$pool->id,
            'currency' => $currency,
            'selection' => [
                'pool_id' => $params['pool_id'],
                'category_id' => $params['category_id'],
            ],
            'closing_balance' => [
                'ledger' => $cbLedgerAmt,
                'statement' => $cbStatAmt,
                'difference' => $cbDiff,
            ],
            'outstanding_items' => [
                'ledger_debit' => $sections['ledger_debit'],
                'ledger_credit' => $sections['ledger_credit'],
                'stmt_debit' => $sections['stmt_debit'],
                'stmt_credit' => $sections['stmt_credit'],
                'net_ledger_debit' => $netLedgerDebit,
                'net_ledger_credit' => $netLedgerCredit,
                'net_stmt_debit' => $netStmtDebit,
                'net_stmt_credit' => $netStmtCredit,
                'ledger_net_amount' => $ledgerNetAmount,
                'stmt_net_amount' => $stmtNetAmount,
                'ledger' => $oiLedger,
                'statement' => $oiStatement,
                'difference' => $oiDiff,
            ],
            'trial_balance' => [
                'ledger' => $tbLedger,
                'statement' => $tbStmt,
                'difference' => $tbLedger - $tbStmt,
            ],
            'totals' => [
                'ledger_net_amount' => $ledgerNetAmount,
                'statement_net_amount' => $stmtNetAmount,
                'total_amount' => $ledgerNetAmount - $stmtNetAmount,
            ],
        ];
    }

    /**
     * Суммирует последние Closing Balance по счетам на дату отчёта.
     *
     * Для каждого счёта берётся последняя запись `nostro_balance` с датой
     * не позже указанной, строго в разделе NRE и нужном L/S типе.
     *
     * @param int[] $accountIds ID счетов.
     * @param int $cid ID компании.
     * @param string $lsType Тип баланса `L` или `S`.
     * @param string $date Дата отчёта `Y-m-d`.
     * @return float|null Сумма или `null`, если балансы не найдены.
     */
    private function sumClosingBalance(array $accountIds, int $cid, string $lsType, string $date): ?float
    {
        if (empty($accountIds)) {
            return null;
        }

        $balances = NostroBalance::find()
            ->where([
                'account_id' => $accountIds,
                'company_id' => $cid,
                'ls_type' => $lsType,
                'section' => NostroBalance::SECTION_NRE,
                // Промежуточные балансы страниц (M/INTM) в раккорд не берём.
                'is_intermediate' => false,
            ])
            ->andWhere(['<=', 'value_date', $date])
            ->orderBy(['account_id' => SORT_ASC, 'value_date' => SORT_DESC, 'id' => SORT_DESC])
            ->all();

        $sum = 0.0;
        $seen = [];
        foreach ($balances as $balance) {
            if (isset($seen[$balance->account_id])) {
                continue;
            }
            $seen[$balance->account_id] = true;
            $amount = (float)$balance->closing_balance;
            $sum += $lsType === NostroBalance::LS_LEDGER
                ? $this->signedLedgerBalance($amount, (string)$balance->closing_dc)
                : $this->signedStatementBalance($amount, (string)$balance->closing_dc);
        }

        return empty($seen) ? null : $sum;
    }

    /**
     * Преобразует баланс и D/C-признак в знаковое значение.
     *
     * @param float $amount Абсолютная сумма.
     * @param string $dc Признак `D` или `C`.
     * @return float Положительное значение для Debit, отрицательное для Credit.
     */
    private function signedLedgerBalance(float $amount, string $dc): float
    {
        return $dc === NostroBalance::DC_DEBIT ? abs($amount) : -abs($amount);
    }

    /**
     * Преобразует Statement-баланс и D/C-признак в знаковое значение.
     *
     * @param float $amount Абсолютная сумма баланса.
     * @param string $dc Признак `D` или `C`.
     * @return float Положительное значение для Credit, отрицательное для Debit.
     */
    private function signedStatementBalance(float $amount, string $dc): float
    {
        return $dc === NostroBalance::DC_CREDIT ? abs($amount) : -abs($amount);
    }

    /**
     * Суммирует поле `amount` в строках детализации отчёта.
     *
     * @param array $rows Строки outstanding items.
     * @return float Сумма значений `amount`.
     */
    private function sumAmount(array $rows): float
    {
        return array_sum(array_map(function ($row) {
            return (float)$row['amount'];
        }, $rows));
    }

    /**
     * Определяет общую валюту группы счетов.
     *
     * @param Account[] $accounts Счета отчёта.
     * @return string Код валюты, `MULTI` при разных валютах или пустая строка.
     */
    private function commonCurrency(array $accounts): string
    {
        $currencies = [];
        foreach ($accounts as $account) {
            if ($account->currency) {
                $currencies[$account->currency] = true;
            }
        }

        if (count($currencies) === 1) {
            return (string)array_key_first($currencies);
        }
        return count($currencies) > 1 ? 'MULTI' : '';
    }

    /**
     * Создаёт временный XLSX-файл отчёта.
     *
     * @param array $report Данные одной карточки отчёта.
     * @return string Абсолютный путь к временному XLSX-файлу.
     */
    private function createXlsxFile(array $report): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Recon Report');
        $this->prepareReconSheet($sheet);

        $row = 1;
        $sheet->setCellValue("A{$row}", 'Reconciliation Report');
        $sheet->mergeCells("A{$row}:H{$row}");
        $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(16);
        $row += 2;

        foreach ($this->metaRows($report) as $meta) {
            $sheet->setCellValue("A{$row}", $meta[0]);
            $sheet->setCellValue("B{$row}", $meta[1]);
            $row++;
        }
        $row++;

        $row = $this->writeReconSummarySection($sheet, $row, $report);

        $row += 2;
        $ledgerStart = $row;
        $sheet->setCellValue("A{$row}", 'Ledger');
        $sheet->mergeCells("A{$row}:H{$row}");
        $this->styleGroupHeader($sheet, $row, 'E8F0FE');
        $row++;
        $row = $this->writeDetailSection($sheet, $row, 'Ledger - Debit (Outstanding Items)', $report['outstanding_items']['ledger_debit'], $report['outstanding_items']['net_ledger_debit']);
        $row = $this->writeDetailSection($sheet, $row + 1, 'Ledger - Credit (Outstanding Items)', $report['outstanding_items']['ledger_credit'], $report['outstanding_items']['net_ledger_credit']);
        $row = $this->writeNetAmountRow($sheet, $row + 1, 'Ledger Net Amount', $report['totals']['ledger_net_amount']);
        $this->styleBlock($sheet, $ledgerStart, $row - 1);

        $row += 2;
        $statementStart = $row;
        $sheet->setCellValue("A{$row}", 'Statement');
        $sheet->mergeCells("A{$row}:H{$row}");
        $this->styleGroupHeader($sheet, $row, 'E6F4EA');
        $row++;
        $row = $this->writeDetailSection($sheet, $row, 'Statement - Debit (Outstanding Items)', $report['outstanding_items']['stmt_debit'], $report['outstanding_items']['net_stmt_debit']);
        $row = $this->writeDetailSection($sheet, $row + 1, 'Statement - Credit (Outstanding Items)', $report['outstanding_items']['stmt_credit'], $report['outstanding_items']['net_stmt_credit']);
        $row = $this->writeNetAmountRow($sheet, $row + 1, 'Statement Net Amount', $report['totals']['statement_net_amount']);
        $this->styleBlock($sheet, $statementStart, $row - 1);

        $row += 2;
        $row = $this->writeNetAmountRow($sheet, $row, 'Total Amount', $report['totals']['total_amount']);

        $sheet->getStyle('B:B')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $sheet->getStyle("B1:H{$row}")->getNumberFormat()->setFormatCode('#,##0.00;[Red]-#,##0.00');

        $path = $this->tempPath('xlsx');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
        return $path;
    }

    /**
     * Настраивает базовые размеры листа XLSX.
     *
     * @param mixed $sheet Worksheet PhpSpreadsheet.
     * @return void
     */
    private function prepareReconSheet($sheet): void
    {
        $widths = [
            'A' => 22,
            'B' => 24,
            'C' => 24,
            'D' => 24,
            'E' => 24,
            'F' => 18,
            'G' => 18,
            'H' => 18,
        ];

        foreach ($widths as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }

        $sheet->getDefaultRowDimension()->setRowHeight(20);
    }

    /**
     * Записывает простую двухколоночную секцию сводки в XLSX.
     *
     * @param mixed $sheet Worksheet PhpSpreadsheet.
     * @param int $row Стартовая строка.
     * @param string $title Заголовок секции.
     * @param array $rows Строки `[label, amount]`.
     * @return int Следующая свободная строка.
     */
    private function writeSummarySection($sheet, int $row, string $title, array $rows): int
    {
        $sheet->setCellValue("A{$row}", $title);
        $sheet->getStyle("A{$row}")->getFont()->setBold(true);
        $row++;
        $sheet->fromArray(['Type', 'Amount'], null, "A{$row}");
        $sheet->getStyle("A{$row}:B{$row}")->getFont()->setBold(true);
        $row++;
        foreach ($rows as $item) {
            $sheet->setCellValue("A{$row}", $item[0]);
            $sheet->setCellValue("B{$row}", $item[1]);
            $row++;
        }
        return $row;
    }

    /**
     * Записывает секцию Reconciliation Summary в XLSX.
     *
     * @param mixed $sheet Worksheet PhpSpreadsheet.
     * @param int $row Стартовая строка.
     * @param array $report Данные отчёта.
     * @return int Следующая свободная строка.
     */
    private function writeReconSummarySection($sheet, int $row, array $report): int
    {
        $sheet->setCellValue("A{$row}", 'Reconciliation Summary');
        $sheet->mergeCells("A{$row}:D{$row}");
        $sheet->getStyle("A{$row}:D{$row}")->getFont()->setBold(true);
        $sheet->getStyle("A{$row}:D{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F3F4F6');
        $row++;

        $sheet->fromArray(['', 'Ledger', 'Statement', 'Difference'], null, "A{$row}");
        $sheet->getStyle("A{$row}:D{$row}")->getFont()->setBold(true);
        $sheet->getStyle("A{$row}:D{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F9FAFB');
        $row++;

        $rows = [
            ['Closing Balance', $report['closing_balance']['ledger'], $report['closing_balance']['statement'], $report['closing_balance']['difference']],
            ['Outstanding Items', $report['outstanding_items']['ledger'], $report['outstanding_items']['statement'], $report['outstanding_items']['difference']],
            ['Trial Balance', $report['trial_balance']['ledger'], $report['trial_balance']['statement'], $report['trial_balance']['difference']],
        ];

        foreach ($rows as $item) {
            $sheet->fromArray($item, null, "A{$row}");
            $sheet->getStyle("A{$row}:D{$row}")->getFont()->setBold(true);
            $row++;
        }

        $this->styleBlock($sheet, $row - 5, $row - 1, 'D');
        return $row;
    }

    /**
     * Записывает детальную секцию outstanding items в XLSX.
     *
     * @param mixed $sheet Worksheet PhpSpreadsheet.
     * @param int $row Стартовая строка.
     * @param string $title Заголовок секции.
     * @param array $rows Строки операций.
     * @param float $net Итог секции.
     * @return int Следующая свободная строка.
     */
    private function writeDetailSection($sheet, int $row, string $title, array $rows, float $net): int
    {
        $sheet->setCellValue("A{$row}", $title);
        $sheet->getStyle("A{$row}")->getFont()->setBold(true);
        $sheet->getStyle("A{$row}:H{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F9FAFB');
        $row++;
        $sheet->fromArray(['Value', 'Account', 'Instruction_ID', 'EndToEnd_ID', 'Transaction_ID', 'Message_ID', 'D/C Mark', 'Amount'], null, "A{$row}");
        $sheet->getStyle("A{$row}:H{$row}")->getFont()->setBold(true);
        $sheet->getStyle("A{$row}:H{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F3F4F6');
        $row++;
        foreach ($rows as $entry) {
            $sheet->fromArray([
                $entry['value'],
                $entry['account'],
                $entry['instruction_id'],
                $entry['end_to_end_id'],
                $entry['transaction_id'],
                $entry['message_id'],
                $entry['dc'],
                $entry['amount'],
            ], null, "A{$row}");
            $row++;
        }
        $sheet->setCellValue("G{$row}", 'Amount');
        $sheet->setCellValue("H{$row}", $net);
        $sheet->getStyle("G{$row}:H{$row}")->getFont()->setBold(true);
        $sheet->getStyle("G{$row}:H{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F9FAFB');
        return $row + 1;
    }

    /**
     * Записывает итоговую строку суммы в XLSX.
     *
     * @param mixed $sheet Worksheet PhpSpreadsheet.
     * @param int $row Номер строки.
     * @param string $label Подпись итога.
     * @param float $amount Сумма.
     * @return int Следующая строка.
     */
    private function writeNetAmountRow($sheet, int $row, string $label, float $amount): int
    {
        $sheet->setCellValue("G{$row}", $label);
        $sheet->setCellValue("H{$row}", $amount);
        $sheet->getStyle("G{$row}:H{$row}")->getFont()->setBold(true);
        $sheet->getStyle("G{$row}:H{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F3F4F6');
        return $row + 1;
    }

    /**
     * Применяет стиль заголовка группы в XLSX.
     *
     * @param mixed $sheet Worksheet PhpSpreadsheet.
     * @param int $row Номер строки заголовка.
     * @param string $color RGB-цвет заливки без `#`.
     * @return void
     */
    private function styleGroupHeader($sheet, int $row, string $color): void
    {
        $sheet->getStyle("A{$row}:H{$row}")->getFont()->setBold(true)->setSize(12);
        $sheet->getStyle("A{$row}:H{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($color);
        $sheet->getStyle("A{$row}:H{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    }

    /**
     * Применяет рамки и выравнивание к блоку XLSX.
     *
     * @param mixed $sheet Worksheet PhpSpreadsheet.
     * @param int $startRow Первая строка блока.
     * @param int $endRow Последняя строка блока.
     * @param string $lastCol Последняя колонка блока.
     * @return void
     */
    private function styleBlock($sheet, int $startRow, int $endRow, string $lastCol = 'H'): void
    {
        if ($endRow < $startRow) {
            return;
        }

        $range = "A{$startRow}:{$lastCol}{$endRow}";
        $sheet->getStyle($range)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('D1D5DB');
        $sheet->getStyle($range)->getBorders()->getOutline()->setBorderStyle(Border::BORDER_MEDIUM)->getColor()->setRGB('6B7280');
        $sheet->getStyle($range)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle("B{$startRow}:G{$endRow}")->getAlignment()->setWrapText(true);
        $sheet->getStyle("H{$startRow}:H{$endRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    }

    /**
     * Создаёт временный PDF-файл отчёта через mPDF.
     *
     * @param array $report Данные одной карточки отчёта.
     * @return string Абсолютный путь к временному PDF-файлу.
     */
    private function createPdfFile(array $report): string
    {
        $tempDir = Yii::getAlias('@runtime/mpdf');
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0777, true);
        }

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4-L',
            'tempDir' => $tempDir,
        ]);
        $mpdf->WriteHTML($this->renderPartial('_pdf', ['report' => $report]));

        $path = $this->tempPath('pdf');
        $mpdf->Output($path, \Mpdf\Output\Destination::FILE);
        return $path;
    }

    /**
     * Создаёт ZIP-архив с несколькими отчётами.
     *
     * Временные XLSX/PDF файлы отдельных отчётов удаляются после добавления
     * в архив.
     *
     * @param array $reports Список отчётов.
     * @param string $format Формат вложений `xlsx` или `pdf`.
     * @return string Абсолютный путь к ZIP-файлу.
     * @throws \RuntimeException Если ZIP создать не удалось.
     */
    private function createZipFile(array $reports, string $format): string
    {
        $zipPath = $this->tempPath('zip');
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Не удалось создать ZIP-архив');
        }

        $usedNames = [];
        $tempFiles = [];
        foreach ($reports as $report) {
            $filePath = $format === 'xlsx' ? $this->createXlsxFile($report) : $this->createPdfFile($report);
            $name = $this->uniqueZipName($this->reportFilename($report, $format), $usedNames, (string)$report['nostro_bank']);
            $zip->addFile($filePath, $name);
            $tempFiles[] = $filePath;
        }
        $zip->close();

        foreach ($tempFiles as $filePath) {
            if (is_file($filePath)) {
                @unlink($filePath);
            }
        }

        return $zipPath;
    }

    /**
     * Возвращает уникальное имя файла внутри ZIP.
     *
     * @param string $name Базовое имя файла.
     * @param array $usedNames Уже занятые имена, обновляется по ссылке.
     * @param string $fallbackName Дополнительная часть имени при конфликте.
     * @return string Уникальное имя внутри архива.
     */
    private function uniqueZipName(string $name, array &$usedNames, string $fallbackName): string
    {
        if (!isset($usedNames[$name])) {
            $usedNames[$name] = true;
            return $name;
        }

        $dot = strrpos($name, '.');
        $base = $dot === false ? $name : substr($name, 0, $dot);
        $ext = $dot === false ? '' : substr($name, $dot);
        $candidate = $base . '_' . $this->safeFilename($fallbackName) . $ext;
        $i = 2;
        while (isset($usedNames[$candidate])) {
            $candidate = $base . '_' . $this->safeFilename($fallbackName) . '_' . $i . $ext;
            $i++;
        }
        $usedNames[$candidate] = true;
        return $candidate;
    }

    /**
     * Возвращает секции детализации для PDF-шаблона.
     *
     * @param array $report Данные отчёта.
     * @return array Список секций outstanding items.
     */
    private function detailSections(array $report): array
    {
        return [
            ['title' => 'Ledger - Debit (Outstanding Items)', 'rows' => $report['outstanding_items']['ledger_debit'], 'net' => $report['outstanding_items']['net_ledger_debit']],
            ['title' => 'Ledger - Credit (Outstanding Items)', 'rows' => $report['outstanding_items']['ledger_credit'], 'net' => $report['outstanding_items']['net_ledger_credit']],
            ['title' => 'Statement - Debit (Outstanding Items)', 'rows' => $report['outstanding_items']['stmt_debit'], 'net' => $report['outstanding_items']['net_stmt_debit']],
            ['title' => 'Statement - Credit (Outstanding Items)', 'rows' => $report['outstanding_items']['stmt_credit'], 'net' => $report['outstanding_items']['net_stmt_credit']],
        ];
    }

    /**
     * Возвращает метаданные отчёта для XLSX/PDF.
     *
     * @param array $report Данные отчёта.
     * @return array Строки `[label, value]`.
     */
    private function metaRows(array $report): array
    {
        $rows = [
            ['Company', $report['company']],
            ['Date', $this->formatDateTime($report['generated_at'])],
            ['Date Reconciliation', $this->formatDate($report['date_recon'])],
            ['Nostro Bank', $report['nostro_bank']],
            ['Account', $report['account_name']],
            ['Currency', $report['currency']],
        ];

        if (!empty($report['date_from']) && !empty($report['date_to'])) {
            $rows[] = ['Period', $this->formatDate($report['date_from']) . ' - ' . $this->formatDate($report['date_to'])];
        }

        return $rows;
    }

    /**
     * Формирует имя файла отчёта.
     *
     * @param array $report Данные отчёта.
     * @param string $ext Расширение без точки.
     * @return string Безопасное имя файла.
     */
    private function reportFilename(array $report, string $ext): string
    {
        return 'ReconReport_' . $this->safeFilename($report['nostro_bank']) . '_' . $this->formatDate($report['date_recon']) . '.' . $ext;
    }

    /**
     * Очищает строку для использования в имени файла.
     *
     * @param string $name Исходная строка.
     * @return string Безопасная строка имени файла.
     */
    private function safeFilename(string $name): string
    {
        $name = preg_replace('/[\\\\\/:*?"<>|]+/u', '_', $name);
        return trim($name) ?: 'Report';
    }

    /**
     * Создаёт путь для временного файла экспорта.
     *
     * @param string $ext Расширение файла без точки.
     * @return string Абсолютный путь в `runtime/recon-report`.
     */
    private function tempPath(string $ext): string
    {
        $dir = Yii::getAlias('@runtime/recon-report');
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        return $dir . DIRECTORY_SEPARATOR . uniqid('recon_', true) . '.' . $ext;
    }

    /**
     * Отправляет временный файл пользователю и планирует его удаление.
     *
     * @param string $path Абсолютный путь к файлу.
     * @param string $name Имя файла для скачивания.
     * @param string $mimeType MIME-тип ответа.
     * @return \yii\web\Response Ответ отправки файла.
     */
    private function sendTempFile(string $path, string $name, string $mimeType)
    {
        Yii::$app->response->on(Response::EVENT_AFTER_SEND, function () use ($path) {
            if (is_file($path)) {
                @unlink($path);
            }
        });

        return Yii::$app->response->sendFile($path, $name, [
            'mimeType' => $mimeType,
            'inline' => false,
        ]);
    }

    /**
     * Возвращает MIME-тип для формата экспорта.
     *
     * @param string $format `xlsx` или `pdf`.
     * @return string MIME-тип файла.
     */
    private function mimeType(string $format): string
    {
        return $format === 'pdf'
            ? 'application/pdf'
            : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    }

    /**
     * Форматирует дату для отображения в отчёте и имени файла.
     *
     * @param string $date Дата в формате, понимаемом `strtotime`.
     * @return string Дата `d.m.Y`.
     */
    private function formatDate(string $date): string
    {
        return date('d.m.Y', strtotime($date));
    }

    /**
     * Форматирует дату и время для метаданных отчёта.
     *
     * @param string $dateTime Дата-время в формате, понимаемом `strtotime`.
     * @return string Дата-время `d.m.Y H:i:s`.
     */
    private function formatDateTime(string $dateTime): string
    {
        return date('d.m.Y H:i:s', strtotime($dateTime));
    }
}
