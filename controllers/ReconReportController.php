<?php

namespace app\controllers;

use Yii;
use yii\web\Response;
use app\models\Account;
use app\models\AccountPool;
use app\models\Category;
use app\services\ReconReportService;
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
        $cid = Yii::$app->request->get('cid', $this->cid());
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

    private function readReportParams(array $source): array
    {
        return (new ReconReportService())->readReportParams($source);
    }

    private function resolveReportLevel(int $poolId, int $categoryId, int $cid): array
    {
        return (new ReconReportService())->resolveReportLevel($poolId, $categoryId, $cid);
    }

    private function buildReports(array $params, int $cid, string $generatedAt, int $onlyPoolId = 0): array
    {
        return (new ReconReportService())->buildReports($params, $cid, $generatedAt, $onlyPoolId);
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
        return (new ReconReportService())->createPdfFile($report);
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
