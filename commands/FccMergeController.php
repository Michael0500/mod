<?php

namespace app\commands;

use app\commands\concerns\ImportProcessingLock;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Перенос выписок FCC12 из gitb_nostro_extract_custom
 * в nostro_balance и nostro_entries.
 *
 * Алгоритм:
 *   1. В tds_status ищем type = 'FCC12' И is_merged = false.
 *   2. Для каждой такой записи открываем транзакцию.
 *   3. Из gitb_nostro_extract_custom выбираем строки с extract_no = tds_status.fcc_extract_no.
 *      Тип строки определяется полем data_section:
 *      - data_section = 60 → строка-баланс → nostro_balance
 *      - data_section = 61 → строка-транзакция → nostro_entries
 *      При этом в обе таблицы пишем extract_no, line_no и branch_code.
 *   4. tds_status.is_merged := true.
 *   5. Удаляем строки из gitb_nostro_extract_custom с этим extract_no.
 *   6. Commit.
 *
 * Использование:
 *   php yii fcc-merge/run
 */
class FccMergeController extends Controller
{
    use ImportProcessingLock;

    /** Подавлять консольный вывод (true при вызове processOne из web-контекста). */
    public bool $quiet = false;

    const COMPANY_ID = 1;
    const SOURCE     = 'FCC12';
    const SECTION    = 'NRE';
    const LS_LEDGER  = 'L';

    /** Сколько строк-источников тянуть за один SELECT. */
    const FETCH_CHUNK  = 5000;
    /** Сколько строк копить перед batchInsert. 18 колонок × 1000 = 18000 параметров (< 65535). */
    const INSERT_CHUNK = 1000;

    /** --delete-source: удалять строки из gitb_nostro_extract_custom после обработки */
    public bool $deleteSource = false;

    /**
     * Описание опций командной строки.
     *
     * @param string $actionID Идентификатор action.
     * @return array
     */
    public function options($actionID)
    {
        return array_merge(parent::options($actionID), ['deleteSource']);
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
     * Запускает перенос всех необработанных пакетов FCC12.
     *
     * Для каждой строки `tds_status` с `type='FCC12'` и `is_merged=false`
     * открывает транзакцию, переносит строки источника, пишет аудит, удаляет
     * успешно обработанный источник и помечает пакет как merged.
     *
     * @return int Код завершения консольной команды.
     */
    public function actionRun(): int
    {
        $this->stdout("=== FCC12 merge: " . date('Y-m-d H:i:s') . ($this->deleteSource ? " [delete-source]" : "") . " ===\n", Console::BOLD);

        $db = Yii::$app->db;

        $pending = $db->createCommand(
            "SELECT id, fcc_extract_no
               FROM {{%tds_status}}
              WHERE type = :type
                AND is_merged = FALSE
                AND fcc_extract_no IS NOT NULL
              ORDER BY id",
            [':type' => self::SOURCE]
        )->queryAll();

        if (empty($pending)) {
            $this->stdout("Нет записей для обработки.\n", Console::FG_GREY);
            return ExitCode::OK;
        }

        $this->stdout("К обработке: " . count($pending) . "\n");

        $totalBalances = 0;
        $totalEntries  = 0;
        $errors        = 0;

        foreach ($pending as $row) {
            $statusId  = (int)$row['id'];
            $extractNo = (int)$row['fcc_extract_no'];

            $this->stdout("\n┌─ tds_status.id={$statusId}, extract_no={$extractNo}\n", Console::FG_CYAN);

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
                $this->stdout("│  Балансов: {$res['balances']}, записей: {$res['entries']}\n", Console::FG_GREEN);
                $this->stdout("└─ OK\n");
            } else {
                $this->stdout("│  Балансов: {$res['balances']}, записей: {$res['entries']}, пропущено строк: {$res['skipped']} (счёт не найден)\n", Console::FG_YELLOW);
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
     * Обрабатывает один пакет FCC12 под блокировкой.
     *
     * Захватывает строку tds_status (взаимоисключение ручного и фонового
     * процессов), переносит строки `gitb_nostro_extract_custom` с этим
     * `extract_no` в `nostro_*`, удаляет обработанный источник (если не
     * `keepSource`), помечает пакет merged и снимает блокировку. Используется
     * и фоновым `actionRun`, и ручным запуском из интерфейса (web).
     *
     * @param int $statusId ID строки tds_status (type=FCC12).
     * @param string $owner Кто запустил: 'manual' или 'background'.
     * @return array `['busy'=>bool,'ok'=>bool,'balances'=>int,'entries'=>int,'skipped'=>int,'error'=>?string]`.
     */
    public function processOne(int $statusId, string $owner = 'background'): array
    {
        if (!$this->acquireProcessingLock($statusId, $owner)) {
            return ['busy' => true, 'ok' => false, 'balances' => 0, 'entries' => 0, 'skipped' => 0, 'skipped_accounts' => [], 'error' => null];
        }

        $db = Yii::$app->db;

        try {
            $extractNo = (int)$db->createCommand(
                "SELECT fcc_extract_no FROM {{%tds_status}} WHERE id = :id",
                [':id' => $statusId]
            )->queryScalar();

            $tx = $db->beginTransaction();
            try {
                [$balances, $entries, $skipped, $skippedAccounts] = $this->mergeExtract($extractNo, $statusId);

                if ($this->deleteSource) {
                    if (empty($skipped)) {
                        $db->createCommand()
                            ->delete('{{%gitb_nostro_extract_custom}}', ['extract_no' => $extractNo])
                            ->execute();
                    } else {
                        $skippedStr = implode(',', array_map('intval', $skipped));
                        $db->createCommand(
                            "DELETE FROM {{%gitb_nostro_extract_custom}}
                              WHERE extract_no = :ext AND line_no NOT IN ({$skippedStr})",
                            [':ext' => $extractNo]
                        )->execute();
                    }
                }

                // Причина частичной загрузки — какие счета не найдены в системе.
                $db->createCommand()->update('{{%tds_status}}', [
                    'skipped_accounts' => empty($skippedAccounts)
                        ? null
                        : json_encode(array_values($skippedAccounts), JSON_UNESCAPED_UNICODE),
                ], ['id' => $statusId])->execute();

                if (empty($skipped) && $this->deleteSource) {
                    $db->createCommand()->update('{{%tds_status}}', [
                        'is_merged'      => true,
                        'company_id'     => self::COMPANY_ID,
                        'entries_count'  => $entries,
                        'balances_count' => $balances,
                    ], ['id' => $statusId])->execute();
                }

                $tx->commit();

                return [
                    'busy'     => false,
                    'ok'       => empty($skipped),
                    'balances' => $balances,
                    'entries'  => $entries,
                    'skipped'  => count($skipped),
                    'skipped_accounts' => array_values($skippedAccounts),
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
     * Переносит строки одного `extract_no` в балансы и операции.
     *
     * Читает источник потоково по `line_no`, использует batchInsert для
     * `nostro_entries` и явное обновление/вставку для `nostro_balance`, а строки с ненайденным
     * счётом возвращает как skipped, чтобы пакет можно было повторить.
     *
     * @param int $extractNo Номер FCC12-выгрузки.
     * @param int $batchId ID пачки `tds_status` для трассировки/отката.
     * @return array `[balancesInserted, entriesInserted, skippedLineNos, skippedAccountNames]`.
     */
    private function mergeExtract(int $extractNo, int $batchId): array
    {
        $db = Yii::$app->db;

        $entryColumns = [
            'account_id', 'company_id', 'ls', 'dc', 'amount', 'currency',
            'value_date', 'post_date', 'instruction_id', 'end_to_end_id',
            'transaction_id', 'source', 'match_status', 'extract_no', 'line_no',
            'branch_code', 'created_at', 'updated_at', 'batch_id',
        ];
        $balanceColumns = [
            'company_id', 'account_id', 'ls_type', 'currency', 'value_date',
            'opening_balance', 'opening_dc', 'closing_balance', 'closing_dc',
            'section', 'source', 'status', 'extract_no', 'line_no',
            'branch_code', 'created_at', 'updated_at', 'batch_id',
        ];

        // Кэш account_id по cbr_cc_no (accounts.name) — чтобы не искать в цикле.
        $accountCache = [];
        $now = date('Y-m-d H:i:s');

        $entryBuf    = [];
        $balanceBuf  = [];
        $skippedLineNos = [];
        $skippedAccounts = [];
        $totalEntries  = 0;
        $totalBalances = 0;

        $flushEntries = function () use ($db, $entryColumns, &$entryBuf, &$totalEntries) {
            if (empty($entryBuf)) return;
            $lastId = (int)$db->createCommand("SELECT COALESCE(MAX(id), 0) FROM {{%nostro_entries}}")->queryScalar();

            $db->createCommand()
                ->batchInsert('{{%nostro_entries}}', $entryColumns, $entryBuf)
                ->execute();

            $this->writeEntryAuditAfterFlush($lastId, $entryBuf);
            $totalEntries += count($entryBuf);
            $entryBuf = [];
        };

        $flushBalances = function () use ($balanceColumns, &$balanceBuf, &$totalBalances) {
            if (empty($balanceBuf)) return;

            $this->upsertBalances($balanceColumns, $balanceBuf);
            $totalBalances += count($balanceBuf);
            $balanceBuf = [];
        };

        // Потоковое чтение источника по (extract_no, line_no) — без OFFSET и без полного queryAll.
        $lastLineNo = -1;
        while (true) {
            $rows = $db->createCommand(
                "SELECT * FROM {{%gitb_nostro_extract_custom}}
                  WHERE extract_no = :ext
                    AND line_no > :ln
                  ORDER BY line_no
                  LIMIT :lim",
                [
                    ':ext' => $extractNo,
                    ':ln'  => $lastLineNo,
                    ':lim' => self::FETCH_CHUNK,
                ]
            )->queryAll();

            if (empty($rows)) {
                break;
            }

            foreach ($rows as $r) {
                // Тип строки определяется полем data_section:
                //   60 → баланс (nostro_balance), 61 → транзакция (nostro_entries).
                $dataSection = ($r['data_section'] === null || $r['data_section'] === '')
                    ? null
                    : (int)$r['data_section'];
                $isBalance = $dataSection === 60;
                $isEntry   = $dataSection === 61;

                // строки-заголовки / хвостовики — без финансовых данных, пропускаем
                if (!$isEntry && !$isBalance) {
                    $lastLineNo = (int)$r['line_no'];
                    continue;
                }

                $cbrCcNo = trim((string)$r['cbr_cc_no']);
                if ($cbrCcNo === '') {
                    throw new \RuntimeException("Строка line_no={$r['line_no']}: пустой cbr_cc_no");
                }

                if (!array_key_exists($cbrCcNo, $accountCache)) {
                    $accountCache[$cbrCcNo] = $db->createCommand(
                        "SELECT id FROM {{%accounts}}
                          WHERE company_id = :cid
                            AND name = :name
                          LIMIT 1",
                        [':cid' => self::COMPANY_ID, ':name' => $cbrCcNo]
                    )->queryScalar();
                }

                $accountId = $accountCache[$cbrCcNo];
                if (!$accountId) {
                    $this->out("│  Пропуск line_no={$r['line_no']}: счёт не найден для cbr_cc_no='{$cbrCcNo}'\n", Console::FG_YELLOW);
                    $skippedLineNos[] = (int)$r['line_no'];
                    $skippedAccounts[$cbrCcNo] = true;
                    $lastLineNo = (int)$r['line_no'];
                    continue;
                }

                if ($isEntry) {
                    $entryBuf[] = [
                        $accountId,
                        self::COMPANY_ID,
                        self::LS_LEDGER,
                        $this->mapDc($r['drcr_ind']),
                        $r['amount'],
                        $r['ccy'],
                        $r['value_dt'],
                        $r['trn_dt'],
                        $r['ed_no'],
                        $r['trn_ref_sr_no'],
                        $r['obj_ref'],
                        self::SOURCE,
                        'U',
                        $r['extract_no'],
                        $r['line_no'],
                        $r['branch_code'] ?? null,
                        $now,
                        $now,
                        $batchId,
                    ];
                    if (count($entryBuf) >= self::INSERT_CHUNK) {
                        $flushEntries();
                    }
                } elseif ($isBalance) {
                    $balanceBuf[] = [
                        self::COMPANY_ID,
                        $accountId,
                        self::LS_LEDGER,
                        $r['ccy'],
                        $r['dt'],
                        $r['opening_bal'] ?? 0,
                        $r['opening_bal_dc'] ?: 'C',
                        $r['closing_bal'] ?? 0,
                        $r['closing_bal_dc'] ?: 'C',
                        self::SECTION,
                        self::SOURCE,
                        'normal',
                        $r['extract_no'],
                        $r['line_no'],
                        $r['branch_code'] ?? null,
                        $now,
                        $now,
                        $batchId,
                    ];
                    if (count($balanceBuf) >= self::INSERT_CHUNK) {
                        $flushBalances();
                    }
                }

                $lastLineNo = (int)$r['line_no'];
            }

            if (count($rows) < self::FETCH_CHUNK) {
                break;
            }
        }

        $flushEntries();
        $flushBalances();

        return [$totalBalances, $totalEntries, $skippedLineNos, array_keys($skippedAccounts)];
    }

    /**
     * Пишет аудит создания FCC12-записей после batchInsert.
     *
     * @param int $lastId Максимальный ID до вставки batch.
     * @param array $insertedRows Буфер строк, переданный в batchInsert.
     * @return void
     */
    private function writeEntryAuditAfterFlush(int $lastId, array $insertedRows): void
    {
        $lineNos = $this->extractLineNos($insertedRows, 14);
        if (empty($lineNos)) {
            return;
        }
        $lineNoList = implode(',', array_map('intval', $lineNos));

        $rows = Yii::$app->db->createCommand(
            "SELECT id, account_id, company_id, ls, dc, amount, currency,
                    value_date, post_date, instruction_id, end_to_end_id,
                    transaction_id, message_id, other_id, comment, source,
                    match_status, match_id, extract_no, line_no, branch_code, created_at, updated_at
               FROM {{%nostro_entries}}
              WHERE id > :last_id
                AND source = :source
                AND extract_no = :extract_no
                AND line_no IN ({$lineNoList})
              ORDER BY id",
            [
                ':last_id'    => $lastId,
                ':source'     => self::SOURCE,
                ':extract_no' => (int)$insertedRows[0][13],
            ]
        )->queryAll();

        if (empty($rows)) {
            return;
        }

        $auditRows = [];
        $now = date('Y-m-d H:i:s');
        foreach ($rows as $row) {
            $auditRows[] = [
                (int)$row['id'],
                0,
                'create',
                null,
                json_encode($row, JSON_UNESCAPED_UNICODE),
                null,
                null,
                'Импорт FCC12',
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
     * Создаёт новые Ledger-балансы FCC12 или обновляет уже сформированные.
     *
     * Совпадение определяется составным бизнес-ключом: счёт, тип L/S, валюта,
     * Value, раздел и источник. Обновление выполняется явно по `id`, а не через
     * SQL UPSERT: так повторная загрузка FCC12 за тот же день не создаст дубль,
     * даже если в фактической БД ещё отсутствует индекс `uq_nbalance_entry`.
     *
     * @param array $columns Порядок колонок во входных строках.
     * @param array $rows Строки баланса из FCC12.
     * @return void
     */
    private function upsertBalances(array $columns, array $rows): void
    {
        $db = Yii::$app->db;

        foreach ($rows as $values) {
            $row = array_combine($columns, $values);
            if ($row === false) {
                throw new \RuntimeException('Не удалось сопоставить колонки баланса FCC12');
            }

            $key = [
                'account_id' => $row['account_id'],
                'ls_type'    => $row['ls_type'],
                'currency'   => $row['currency'],
                'value_date' => $row['value_date'],
                'section'    => $row['section'],
                'source'     => $row['source'],
            ];

            $oldValues = $this->findBalanceSnapshot($key, true);
            $updatedValues = [
                'opening_balance' => $row['opening_balance'],
                'opening_dc'      => $row['opening_dc'],
                'closing_balance' => $row['closing_balance'],
                'closing_dc'      => $row['closing_dc'],
                'status'          => $row['status'],
                'extract_no'      => $row['extract_no'],
                'line_no'         => $row['line_no'],
                'branch_code'     => $row['branch_code'],
                'updated_at'      => $row['updated_at'],
                'batch_id'        => $row['batch_id'],
            ];

            if ($oldValues === null) {
                $db->createCommand()->insert('{{%nostro_balance}}', $row)->execute();
            } else {
                $db->createCommand()->update(
                    '{{%nostro_balance}}',
                    $updatedValues,
                    ['id' => (int)$oldValues['id']]
                )->execute();
            }

            $newValues = $this->findBalanceSnapshot($key);
            if ($newValues === null) {
                throw new \RuntimeException('Не удалось получить сохранённый баланс FCC12');
            }

            $db->createCommand()->insert('{{%nostro_balance_audit}}', [
                'balance_id' => (int)$newValues['id'],
                'user_id'    => 0,
                'action'     => 'import',
                'old_values' => $oldValues === null
                    ? null
                    : json_encode($oldValues, JSON_UNESCAPED_UNICODE),
                'new_values' => json_encode($newValues, JSON_UNESCAPED_UNICODE),
                'reason'     => 'Импорт FCC12',
                'created_at' => date('Y-m-d H:i:s'),
            ])->execute();
        }
    }

    /**
     * Возвращает снимок FCC12-баланса по его уникальному ключу.
     *
     * @param array $key Значения составного ключа `uq_nbalance_entry`.
     * @param bool $forUpdate Заблокировать найденную строку до конца транзакции.
     * @return array|null
     */
    private function findBalanceSnapshot(array $key, bool $forUpdate = false): ?array
    {
        $sql = "SELECT id, company_id, account_id, ls_type, statement_number, currency,
                       value_date, opening_balance, opening_dc, closing_balance, closing_dc,
                       section, source, status, comment, extract_no, line_no, branch_code,
                       created_at, updated_at, batch_id
                  FROM {{%nostro_balance}}
                 WHERE account_id = :account_id
                   AND ls_type = :ls_type
                   AND currency = :currency
                   AND value_date = :value_date
                   AND section = :section
                   AND source = :source
                 ORDER BY id DESC
                 LIMIT 1";
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }

        $row = Yii::$app->db->createCommand($sql, [
            ':account_id' => $key['account_id'],
            ':ls_type'    => $key['ls_type'],
            ':currency'   => $key['currency'],
            ':value_date' => $key['value_date'],
            ':section'    => $key['section'],
            ':source'     => $key['source'],
        ])->queryOne();

        return $row === false ? null : $row;
    }

    /**
     * Извлекает уникальные `line_no` из буфера batchInsert.
     *
     * @param array $rows Строки batchInsert.
     * @param int $lineNoIndex Индекс колонки `line_no` в строке batch.
     * @return int[] Уникальные номера строк источника.
     */
    private function extractLineNos(array $rows, int $lineNoIndex): array
    {
        $lineNos = [];
        foreach ($rows as $row) {
            if (isset($row[$lineNoIndex])) {
                $lineNos[] = (int)$row[$lineNoIndex];
            }
        }

        return array_values(array_unique($lineNos));
    }

    /**
     * Преобразует FCC12 D/C-признак в значение `NostroEntry::dc`.
     *
     * @param string|null $drcrInd Значение `D` или `C` из источника.
     * @return string `Debit` или `Credit`.
     * @throws \RuntimeException Если признак не распознан.
     */
    private function mapDc(?string $drcrInd): string
    {
        $v = strtoupper((string)$drcrInd);
        if ($v === 'D') return 'Debit';
        if ($v === 'C') return 'Credit';
        throw new \RuntimeException("Некорректный drcr_ind: '{$drcrInd}'");
    }
}
