<?php

namespace app\services;

use app\models\Account;
use app\models\AccountPool;
use app\models\Category;
use app\models\NostroBalance;
use app\models\NostroEntry;
use Mpdf\Mpdf;
use Yii;
use yii\helpers\FileHelper;
use yii\web\View;

/** Единые расчёты и PDF Раккорда для интерфейса и консольных задач. */
class ReconReportService
{
    /** Формирует отчёт банка, включая банк без счетов для фоновой выгрузки. */
    public function buildBankReport(AccountPool $bank, string $date, string $generatedAt): array
    {
        $params = $this->readReportParams(['pool_id' => $bank->id, 'date_recon' => $date]);
        $reports = $this->buildReports($params, (int) $bank->company_id, $generatedAt);
        // Cron сохраняет также пустой отчёт для банка без счетов.
        return $reports[0] ?? $this->buildReportData($bank, [], $params, (int) $bank->company_id, $generatedAt);
    }

    /** Создаёт временный PDF для скачивания из интерфейса или ZIP. */
    public function createPdfFile(array $report): string
    {
        $directory = Yii::getAlias('@runtime/recon-report');
        FileHelper::createDirectory($directory);
        $path = $directory . DIRECTORY_SEPARATOR . uniqid('recon_', true) . '.pdf';
        try {
            $this->writePdf($report, $path);
        } catch (\Throwable $e) {
            if (is_file($path)) {
                unlink($path);
            }
            throw $e;
        }
        return $path;
    }

    /** Единая настройка mPDF и отрисовка существующего шаблона отчёта. */
    public function writePdf(array $report, string $path): void
    {
        $tempDir = Yii::getAlias('@runtime/mpdf');
        FileHelper::createDirectory($tempDir);
        $mpdf = new Mpdf(['mode' => 'utf-8', 'format' => 'A4-L', 'tempDir' => $tempDir]);
        $html = (new View())->renderFile(Yii::getAlias('@app/views/recon-report/_pdf.php'), ['report' => $report]);
        $mpdf->WriteHTML($html);
        $mpdf->Output($path, \Mpdf\Output\Destination::FILE);
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
    public function readReportParams(array $source): array
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
    public function resolveReportLevel(int $poolId, int $categoryId, int $cid): array
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
    public function buildReports(array $params, int $cid, string $generatedAt, int $onlyPoolId = 0): array
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
                $row['amount'] = (float)$entry->amount;
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

        $netLedgerDebit = -$this->sumAmount($sections['ledger_debit']);
        $netLedgerCredit = $this->sumAmount($sections['ledger_credit']);
        $netStmtDebit = $this->sumAmount($sections['stmt_debit']);
        $netStmtCredit = $this->sumAmount($sections['stmt_credit']);
        $ledgerNetAmount = $netLedgerDebit + $netLedgerCredit;
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

    /** Публикует готовый PDF атомарно; незавершённый файл не имеет расширения PDF. */
    public function savePdf(array $report, string $directory, int $companyId): string
    {
        FileHelper::createDirectory($directory);
        $name = preg_replace('/[^\pL\pN_-]+/u', '_', $report['nostro_bank']);
        $name = mb_substr($name ?: 'Bank', 0, 80);
        $path = $directory . DIRECTORY_SEPARATOR . 'ReconReport_' . $companyId . '_'
            . $report['pool_id'] . '_' . $name . '_' . $report['date_recon'] . '.pdf';
        $temp = tempnam($directory, '.recon_');
        if ($temp === false) {
            throw new \RuntimeException('Не удалось создать временный файл отчёта');
        }
        try {
            $this->writePdf($report, $temp);
            if (!rename($temp, $path)) {
                throw new \RuntimeException('Не удалось сохранить PDF: ' . $path);
            }
        } finally {
            if (is_file($temp)) {
                unlink($temp);
            }
        }
        return $path;
    }
}
