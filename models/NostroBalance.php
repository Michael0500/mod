<?php

namespace app\models;

use Yii;
use yii\db\ActiveRecord;
use yii\behaviors\BlameableBehavior;
use yii\behaviors\TimestampBehavior;
use yii\db\Expression;

/**
 * Модель баланса Ностро счёта (Opening / Closing Balance).
 *
 * @property int         $id
 * @property int         $company_id
 * @property int         $account_id
 * @property string      $ls_type           L|S
 * @property string|null $statement_number  Только для S
 * @property string      $currency          ISO 4217
 * @property string      $value_date        DATE (YYYY-MM-DD в БД)
 * @property float       $opening_balance
 * @property string      $opening_dc        D|C
 * @property float       $closing_balance
 * @property string      $closing_dc        D|C
 * @property string      $section           NRE|INV
 * @property string      $source
 * @property string      $status            normal|error|confirmed
 * @property string|null $comment
 * @property string|null $branch_code
 * @property string|null $stmt_seq_no       Номер выписки для группировки страниц (MT950/camt)
 * @property int|null    $page_no           Порядковый номер страницы выписки
 * @property bool        $is_intermediate   Промежуточный баланс (M/INTM), скрыт из интерфейса
 * @property int|null    $created_by
 * @property int|null    $updated_by
 * @property string      $created_at
 * @property string      $updated_at
 *
 * @property Account     $account
 * @property Company     $company
 */
class NostroBalance extends ActiveRecord
{
    const MONEY_MAX_INTEGER_DIGITS = 18;
    const MONEY_SCALE = 5;

    // ls_type
    const LS_LEDGER    = 'L';
    const LS_STATEMENT = 'S';

    // D/C
    const DC_DEBIT  = 'D';
    const DC_CREDIT = 'C';

    // Статусы
    const STATUS_NORMAL    = 'normal';
    const STATUS_ERROR     = 'error';
    const STATUS_CONFIRMED = 'confirmed';

    // Разделы
    const SECTION_NRE = 'NRE';
    const SECTION_INV = 'INV';

    // Источники
    const SOURCE_BND     = 'BND';
    const SOURCE_ASB     = 'ASB';
    const SOURCE_MT950   = 'MT950';
    const SOURCE_CAMT    = 'CAMT';
    const SOURCE_ED211   = 'ED211';
    const SOURCE_FCC12   = 'FCC12';
    const SOURCE_BARS_GL = 'BARS_GL';
    const SOURCE_DWH     = 'DWH';
    const SOURCE_MANUAL  = 'MANUAL';

    /**
     * Возвращает имя таблицы остатков по ностро-счетам.
     *
     * @return string Имя таблицы `nostro_balance` с учётом префикса Yii.
     */
    public static function tableName(): string
    {
        return '{{%nostro_balance}}';
    }

    /**
     * Подключает автоматическое заполнение времени и пользователя.
     *
     * `TimestampBehavior` использует `NOW()` PostgreSQL, а `BlameableBehavior`
     * фиксирует пользователя, создавшего или изменившего баланс.
     *
     * @return array Конфигурация Yii behaviors.
     */
    public function behaviors(): array
    {
        return [
            [
                'class'              => TimestampBehavior::class,
                'value'              => new Expression('NOW()'),
                'createdAtAttribute' => 'created_at',
                'updatedAtAttribute' => 'updated_at',
            ],
            [
                'class'              => BlameableBehavior::class,
                'createdByAttribute' => 'created_by',
                'updatedByAttribute' => 'updated_by',
            ],
        ];
    }

    /**
     * Описывает правила валидации балансовой записи.
     *
     * Валидируются обязательные реквизиты, тип L/S, D/C-знаки остатков,
     * раздел NRE/INV, источник загрузки и денежная точность `decimal(26,5)`.
     * Для Statement-записей номер выписки обязателен.
     *
     * @return array Правила Yii Validator.
     */
    public function rules(): array
    {
        return [
            // Обязательные
            [['company_id', 'account_id', 'ls_type', 'currency', 'value_date',
                'opening_balance', 'opening_dc', 'closing_balance', 'closing_dc',
                'section', 'source'], 'required'],

            // Числа
            [['company_id', 'account_id', 'created_by', 'updated_by'], 'integer'],
            [['opening_balance', 'closing_balance'], 'validateMoneyAmount'],

            // Строки
            [['ls_type', 'opening_dc', 'closing_dc'], 'string', 'max' => 1],
            [['currency'], 'string', 'max' => 3],
            [['section'], 'string', 'max' => 3],
            [['source'], 'string', 'max' => 20],
            [['status'], 'string', 'max' => 10],
            [['statement_number'], 'string', 'max' => 35],
            [['branch_code'], 'string', 'max' => 3],
            [['comment'], 'string', 'max' => 255],
            [['stmt_seq_no'], 'string', 'max' => 5],

            // Постраничные поля выписки (Признак 1 / промежуточные балансы)
            [['page_no'], 'integer'],
            [['is_intermediate'], 'boolean'],
            [['is_intermediate'], 'default', 'value' => false],

            // Даты
            [['value_date'], 'date', 'format' => 'php:Y-m-d'],
            [['created_at', 'updated_at'], 'safe'],

            // Enum-проверки
            [['ls_type'],    'in', 'range' => [self::LS_LEDGER, self::LS_STATEMENT]],
            [['opening_dc', 'closing_dc'], 'in', 'range' => [self::DC_DEBIT, self::DC_CREDIT]],
            [['status'],     'in', 'range' => [self::STATUS_NORMAL, self::STATUS_ERROR, self::STATUS_CONFIRMED]],
            [['section'],    'in', 'range' => [self::SECTION_NRE, self::SECTION_INV]],

            // statement_number обязателен для S
            [['statement_number'], 'required', 'when' => function ($model) {
                return $model->ls_type === self::LS_STATEMENT;
            }, 'whenClient' => "function(attribute, value) { return $('#ls_type').val() === 'S'; }"],

            // Defaults
            [['status'], 'default', 'value' => self::STATUS_NORMAL],

            // FK
            [['account_id'], 'exist', 'targetClass' => Account::class,
                'targetAttribute' => ['account_id' => 'id']],
            [['company_id'], 'exist', 'targetClass' => Company::class,
                'targetAttribute' => ['company_id' => 'id']],
        ];
    }

    /**
     * Проверяет денежное поле баланса на формат `decimal(26,5)` и шкалу валюты.
     *
     * В отличие от операций выверки, баланс может быть отрицательным.
     * D/C-признак хранится отдельно, но импортированные остатки всё равно
     * проверяются на допустимый размер и шкалу.
     *
     * @param string $attribute Имя атрибута баланса.
     * @return void
     */
    public function validateMoneyAmount($attribute): void
    {
        $value = trim((string)$this->$attribute);
        if (!preg_match('/^-?\d+(?:\.\d{1,5})?$/', $value)) {
            $this->addError($attribute, 'Сумма должна быть числом с максимум 5 знаками после запятой.');
            return;
        }

        $scale = Currency::fractionalDigitsForCode($this->currency);
        $fraction = explode('.', $value, 2)[1] ?? '';
        if (trim(substr($fraction, $scale), '0') !== '') {
            $this->addError($attribute, "Для валюты {$this->currency} допустимо максимум {$scale} знаков после запятой.");
            return;
        }

        [$integerPart] = explode('.', ltrim($value, '-'), 2);
        $integerPart = ltrim($integerPart, '0');
        if (strlen($integerPart) > self::MONEY_MAX_INTEGER_DIGITS) {
            $this->addError($attribute, 'Сумма слишком большая: максимум 18 цифр до запятой и 5 после.');
        }
    }

    /**
     * Возвращает подписи атрибутов баланса для форм, таблиц и ошибок.
     *
     * @return array Массив `attribute => label`.
     */
    public function attributeLabels(): array
    {
        return [
            'id'               => 'ID',
            'company_id'       => 'Компания',
            'account_id'       => 'Ностро счёт',
            'ls_type'          => 'Тип (L/S)',
            'statement_number' => 'Номер выписки',
            'currency'         => 'Валюта',
            'value_date'       => 'Дата валютирования',
            'opening_balance'  => 'Opening Balance',
            'opening_dc'       => 'D/C Opening',
            'closing_balance'  => 'Closing Balance',
            'closing_dc'       => 'D/C Closing',
            'section'          => 'Раздел',
            'source'           => 'Источник',
            'status'           => 'Статус',
            'comment'          => 'Комментарий',
            'branch_code'      => 'Код филиала',
            'created_by'       => 'Создал',
            'updated_by'       => 'Изменил',
            'created_at'       => 'Создано',
            'updated_at'       => 'Изменено',
        ];
    }

    // ─── Отношения ────────────────────────────────────────────────

    /**
     * Возвращает связь балансовой записи с ностро-счётом.
     *
     * @return \yii\db\ActiveQuery Запрос связи `account_id -> accounts.id`.
     */
    public function getAccount(): \yii\db\ActiveQuery
    {
        return $this->hasOne(Account::class, ['id' => 'account_id']);
    }

    /**
     * Возвращает связь балансовой записи с компанией.
     *
     * @return \yii\db\ActiveQuery Запрос связи `company_id -> company.id`.
     */
    public function getCompany(): \yii\db\ActiveQuery
    {
        return $this->hasOne(Company::class, ['id' => 'company_id']);
    }

    /**
     * Возвращает события аудита баланса.
     *
     * @return \yii\db\ActiveQuery Запрос к `nostro_balance_audit`, отсортированный от новых к старым.
     */
    public function getAuditLogs(): \yii\db\ActiveQuery
    {
        return $this->hasMany(NostroBalanceAudit::class, ['balance_id' => 'id'])
            ->orderBy(['created_at' => SORT_DESC]);
    }

    // ─── Хелперы ──────────────────────────────────────────────────

    /**
     * Возвращает дату валютирования в формате для интерфейса.
     *
     * @return string Дата `d.m.Y` или пустая строка, если дата не задана.
     */
    public function getValueDateFormatted(): string
    {
        if (!$this->value_date) return '';
        return date('d.m.Y', strtotime($this->value_date));
    }

    /**
     * Возвращает визуальный маркер статуса баланса.
     *
     * @return string Символ статуса для таблицы балансов.
     */
    public function getStatusIcon(): string
    {
        if ($this->status === self::STATUS_ERROR)     return '🔴';
        if ($this->status === self::STATUS_CONFIRMED) return '⚫';
        return '⚪';
    }

    /**
     * Возвращает список допустимых источников балансов.
     *
     * @return array Карта `код источника => название для формы`.
     */
    public static function sourceList(): array
    {
        return [
            self::SOURCE_BND     => 'Банк-клиент БНД',
            self::SOURCE_ASB     => 'Банк-клиент АСБ',
            self::SOURCE_MT950   => 'MT950',
            self::SOURCE_CAMT    => 'camt.053',
            self::SOURCE_ED211   => 'ED211',
            self::SOURCE_FCC12   => 'FCC12',
            self::SOURCE_BARS_GL => 'BARS GL',
            self::SOURCE_DWH     => 'DWH',
            self::SOURCE_MANUAL  => 'Ручной ввод',
        ];
    }

    /**
     * Преобразует баланс в структуру JSON API.
     *
     * Формат используется страницей балансов и импортом, поэтому включает
     * исходные значения остатков, D/C-признаки, статус проверки и служебные даты.
     *
     * @return array Сериализованные данные баланса.
     */
    public function toApiArray(): array
    {
        return [
            'id'               => $this->id,
            'account_id'       => $this->account_id,
            'account_name'     => $this->account ? $this->account->name : '—',
            'ls_type'          => $this->ls_type,
            'statement_number' => $this->statement_number,
            'currency'         => $this->currency,
            'value_date'       => $this->value_date,
            'value_date_fmt'   => $this->getValueDateFormatted(),
            'opening_balance'  => $this->opening_balance,
            'opening_dc'       => $this->opening_dc,
            'closing_balance'  => $this->closing_balance,
            'closing_dc'       => $this->closing_dc,
            'section'          => $this->section,
            'source'           => $this->source,
            'status'           => $this->status,
            'comment'          => $this->comment,
            'branch_code'      => $this->branch_code,
            'stmt_seq_no'      => $this->stmt_seq_no,
            'page_no'          => $this->page_no,
            'is_intermediate'  => (bool)$this->is_intermediate,
            'created_at'       => $this->created_at,
            'updated_at'       => $this->updated_at,
        ];
    }

    // ─── Валидация последовательности и балансов ─────────────────

    /**
     * Проверяет непрерывность остатков Statement-выписок.
     *
     * Для Statement-записи сравнивает signed opening текущей выписки с signed
     * closing предыдущей по тому же счёту, валюте и разделу. Ledger-балансы
     * не проверяются этим правилом.
     *
     * @param float $tolerance Допустимое абсолютное расхождение.
     * @return string|null Текст ошибки или `null`, если расхождений нет.
     */
    public function checkBalanceContinuity(float $tolerance = 0.01): ?string
    {
        if ($this->ls_type !== self::LS_STATEMENT) {
            return null; // для Ledger не проверяем
        }

        $prev = static::find()
            ->where([
                'company_id'      => $this->company_id,
                'account_id'      => $this->account_id,
                'currency'        => $this->currency,
                'section'         => $this->section,
                'ls_type'         => self::LS_STATEMENT,
                'is_intermediate' => false,
            ])
            ->andWhere(['<', 'value_date', $this->value_date])
            ->andWhere(['!=', 'id', $this->id ?? 0])
            ->orderBy(['value_date' => SORT_DESC])
            ->one();

        if (!$prev) return null;

        // Сравниваем со знаком: D = отрицательный, C = положительный
        $prevClosing  = $this->signedAmount($prev->closing_balance,  $prev->closing_dc);
        $thisOpening  = $this->signedAmount($this->opening_balance, $this->opening_dc);

        if (abs($prevClosing - $thisOpening) > $tolerance) {
            return sprintf(
                'Opening Balance (%.2f %s) не совпадает с Closing Balance предыдущей выписки (%.2f %s) за %s',
                $this->opening_balance,
                $this->opening_dc,
                $prev->closing_balance,
                $prev->closing_dc,
                $prev->getValueDateFormatted()
            );
        }

        return null;
    }

    /**
     * Проверяет последовательность номера Statement-выписки.
     *
     * Для текущей выписки ищет предыдущую Statement-запись по тому же счёту,
     * валюте и разделу. Если у номера есть числовой хвост, ожидаемый номер
     * строится как номер предыдущей выписки + 1 с сохранением префикса.
     * Дополнительно ловит дубликаты, когда предыдущую запись определить нельзя.
     *
     * @return string|null Текст ошибки или `null`, если номер допустим.
     */
    public function checkStatementSequence(): ?string
    {
        if ($this->ls_type !== self::LS_STATEMENT || !$this->statement_number) {
            return null;
        }

        $prev = static::find()
            ->where([
                'company_id'      => $this->company_id,
                'account_id'      => $this->account_id,
                'currency'        => $this->currency,
                'section'         => $this->section,
                'ls_type'         => self::LS_STATEMENT,
                'is_intermediate' => false,
            ])
            ->andWhere(['<', 'value_date', $this->value_date])
            ->andWhere(['!=', 'id', $this->id ?? 0])
            ->orderBy(['value_date' => SORT_DESC, 'id' => SORT_DESC])
            ->one();

        if ($prev && $prev->statement_number) {
            $prevParts = $this->statementOrdinalParts((string)$prev->statement_number);
            if ($prevParts !== null) {
                [$prefix, $number] = $prevParts;
                $expected = $prefix . $this->incrementNumericString($number);
                if (trim((string)$this->statement_number) !== $expected) {
                    return sprintf(
                        'Проверка не пройдена. Порядковый номер "%s" должен быть "%s" после предыдущей выписки "%s" за %s',
                        $this->statement_number,
                        $expected,
                        $prev->statement_number,
                        $prev->getValueDateFormatted()
                    );
                }
            }
        }

        // Дубликат тоже считается нарушением последовательности.
        $dup = static::find()
            ->where([
                'company_id'       => $this->company_id,
                'account_id'       => $this->account_id,
                'currency'         => $this->currency,
                'section'          => $this->section,
                'ls_type'          => self::LS_STATEMENT,
                'statement_number' => $this->statement_number,
            ])
            ->andWhere(['!=', 'id', $this->id ?? 0])
            ->exists();

        if ($dup) {
            return "Проверка не пройдена. Порядковый номер \"{$this->statement_number}\" уже существует";
        }

        return null;
    }

    /**
     * Запускает проверки качества баланса и обновляет `status/comment`.
     *
     * Настройки позволяют отключить проверку номеров или непрерывности остатков
     * и задать допустимую погрешность. Метод меняет только объект модели;
     * сохранение в БД выполняет вызывающий код.
     *
     * @param array $settings Настройки `enable_sequence_check`, `enable_balance_check`,
     *                        `enable_turnover_check`, `balance_tolerance`.
     * @param array $entries Проводки этой выписки для сверки оборотов (Признак 3):
     *                       `[['amount' => string, 'dc' => 'Debit'|'Credit'], ...]`.
     * @return void
     */
    public function runValidations(array $settings = [], array $entries = []): void
    {
        $enableSeq      = $settings['enable_sequence_check'] ?? true;
        $enableBal      = $settings['enable_balance_check']  ?? true;
        $enableTurnover = $settings['enable_turnover_check'] ?? true;
        $tolerance      = (float)($settings['balance_tolerance'] ?? 0.01);

        $errors = [];

        if ($enableSeq) {
            $e = $this->checkStatementSequence();
            if ($e) $errors[] = $e;
        }

        if ($enableBal) {
            $e = $this->checkBalanceContinuity($tolerance);
            if ($e) $errors[] = $e;
        }

        // Признак 3 — сверка оборотов с балансом выписки (только при наличии проводок).
        if ($enableTurnover && !empty($entries)) {
            $validator = new \app\services\StatementSequenceValidator($tolerance);
            $e = $validator->checkTurnover(
                (string)$this->opening_balance,
                (string)$this->opening_dc,
                (string)$this->closing_balance,
                (string)$this->closing_dc,
                $entries
            );
            if ($e) $errors[] = $e;
        }

        if (!empty($errors)) {
            $this->status  = self::STATUS_ERROR;
            $this->comment = mb_substr(implode('; ', $errors), 0, 255);
        } elseif ($this->status === self::STATUS_ERROR) {
            // Если ошибок больше нет — сбрасываем в normal
            $this->status  = self::STATUS_NORMAL;
            $this->comment = null;
        }
    }

    // ─── Приватные хелперы ────────────────────────────────────────

    /**
     * Преобразует сумму и D/C-признак в знаковое значение.
     *
     * @param float $amount Абсолютная сумма.
     * @param string $dc Признак `D` или `C`.
     * @return float Отрицательное значение для Debit и положительное для Credit.
     */
    private function signedAmount(float $amount, string $dc): float
    {
        return $dc === self::DC_DEBIT ? -$amount : $amount;
    }

    /**
     * Разбирает номер выписки на префикс и числовую порядковую часть.
     *
     * @param string $statementNumber Номер выписки.
     * @return array|null `[prefix, numericPart]` или `null`, если номера нет.
     */
    private function statementOrdinalParts(string $statementNumber): ?array
    {
        if (!preg_match('/^(.*?)(\d+)$/u', trim($statementNumber), $m)) {
            return null;
        }

        return [$m[1], $m[2]];
    }

    /**
     * Увеличивает произвольно длинную числовую строку на единицу.
     *
     * @param string $number Числовая строка.
     * @return string Следующее число с сохранением ведущих нулей, если длина не выросла.
     */
    private function incrementNumericString(string $number): string
    {
        $chars = str_split($number);
        $carry = 1;

        for ($i = count($chars) - 1; $i >= 0; $i--) {
            $digit = (int)$chars[$i] + $carry;
            if ($digit === 10) {
                $chars[$i] = '0';
                $carry = 1;
            } else {
                $chars[$i] = (string)$digit;
                $carry = 0;
                break;
            }
        }

        if ($carry) {
            array_unshift($chars, '1');
        }

        return implode('', $chars);
    }
}
