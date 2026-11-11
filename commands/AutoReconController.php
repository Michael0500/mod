<?php

namespace app\commands;

use app\models\AccountPool;
use app\models\Company;
use app\models\MatchingRule;
use app\services\ReconReportService;
use app\services\MatchingService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\FileHelper;

/** Фоновое автоквитование перед сохранением PDF Раккорда по всем банкам NRE. */
class AutoReconController extends Controller
{
    /** @var int|null Компания NRE; по умолчанию все компании NRE. */
    public $company;
    /** @var string|null Дата Раккорда YYYY-MM-DD; по умолчанию предыдущий день. */
    public $date;
    /** @var string Каталог PDF, абсолютный путь или Yii alias. */
    public $output = '@app/web/files/auto_recon';

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['company', 'date', 'output']);
    }

    /** Выполняет отмеченные правила, затем сохраняет PDF Раккорда по всем банкам NRE. */
    public function actionRun(): int
    {
        $date = $this->date ?? date('Y-m-d', strtotime('yesterday'));
        $parsed = \DateTime::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date
            || ($this->company !== null && !preg_match('/^[1-9][0-9]*$/', (string) $this->company))) {
            $this->stderr("Неверная дата (ожидается YYYY-MM-DD) или ID компании.\n");
            return ExitCode::USAGE;
        }
        $companies = Company::find()->where(['code' => 'NRE']);
        if ($this->company !== null) {
            $companies->andWhere(['id' => (int) $this->company]);
        }
        $companies = $companies->orderBy(['id' => SORT_ASC])->all();
        if (!$companies) {
            $this->stderr("Компании раздела NRE не найдены.\n");
            return ExitCode::DATAERR;
        }

        // Один замок на всю команду, в том числе при запуске с разных серверов.
        $db = Yii::$app->db;
        $locked = $db->createCommand('SELECT pg_try_advisory_lock(73421, 1)')->queryScalar();
        if (!$locked) {
            $this->stderr("Фоновый Раккорд уже выполняется.\n");
            return ExitCode::TEMPFAIL;
        }
        try {
            $directory = Yii::getAlias($this->output);
            FileHelper::createDirectory($directory);
            if (!is_writable($directory)) {
                throw new \RuntimeException('Нет прав на запись в ' . $directory);
            }
            $matching = $this->matchingService();
            // Сначала завершаем ВСЕ выбранные правила, затем начинаем создавать PDF.
            foreach ($companies as $company) {
                $rules = MatchingRule::find()->where([
                    'company_id' => $company->id, 'section' => MatchingRule::SECTION_NRE,
                    'is_active' => true, 'recon_background_only' => true,
                ])->orderBy(['priority' => SORT_ASC, 'id' => SORT_ASC])->all();
                foreach ($rules as $rule) {
                    $matched = $matching->runRule($rule, (int) $company->id, null);
                    $this->stdout("Компания {$company->id}, правило {$rule->id} ({$rule->name}): пар {$matched}\n");
                }
            }
            $reports = $this->reportService();
            $generatedAt = date('Y-m-d H:i:s');
            $errors = 0;
            $count = 0;
            foreach ($companies as $company) {
                $banks = AccountPool::find()->where(['company_id' => $company->id])->orderBy(['id' => SORT_ASC]);
                foreach ($banks->each() as $bank) {
                    try {
                        $report = $reports->buildBankReport($bank, $date, $generatedAt);
                        $path = $reports->savePdf($report, $directory, (int) $company->id);
                        $this->stdout("PDF: {$path}\n");
                        $count++;
                    } catch (\Throwable $e) {
                        $errors++;
                        $this->stderr("Банк {$bank->id}: {$e->getMessage()}\n");
                        Yii::error($e, __METHOD__);
                    }
                }
            }
            $this->stdout("Сформировано PDF: {$count}, ошибок: {$errors}, дата: {$date}\n");
            return $errors ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
        } catch (\Throwable $e) {
            // При ошибке квитования генерация отчётов не начинается.
            $this->stderr("Фоновый Раккорд остановлен: {$e->getMessage()}\n");
            Yii::error($e, __METHOD__);
            return ExitCode::UNSPECIFIED_ERROR;
        } finally {
            $db->createCommand('SELECT pg_advisory_unlock(73421, 1)')->execute();
        }
    }

    protected function matchingService(): MatchingService
    {
        return new MatchingService();
    }

    protected function reportService(): ReconReportService
    {
        return new ReconReportService();
    }
}
