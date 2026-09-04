<?php

namespace app\models;

use yii\db\ActiveRecord;

/**
 * Журнал ошибок проверок TDS-выписок.
 */
class TdsValidationError extends ActiveRecord
{
    public const ERROR_PAGE_SEQUENCE = 'page_sequence';
    public const ERROR_INTERMEDIATE_CONTINUITY = 'intermediate_continuity';
    public const ERROR_BALANCE_CONTINUITY = 'balance_continuity';
    public const ERROR_TURNOVER = 'turnover_mismatch';

    public static function tableName()
    {
        return '{{%tds_validation_errors}}';
    }

    public function rules()
    {
        return [
            [['company_id', 'batch_id', 'statement_type', 'error_code', 'account_no', 'error_message'], 'required'],
            [['company_id', 'batch_id'], 'integer'],
            [['statement_date', 'statement_time', 'created_at'], 'safe'],
            [['error_message'], 'string'],
            [['statement_type'], 'string', 'max' => 10],
            [['error_code'], 'string', 'max' => 50],
            [['account_no', 'statement_number'], 'string', 'max' => 35],
        ];
    }

    public function attributeLabels()
    {
        return [
            'id' => 'ID',
            'company_id' => 'Компания',
            'batch_id' => 'Пачка импорта',
            'statement_type' => 'Тип выписки',
            'error_code' => 'Признак ошибки',
            'statement_date' => 'Дата выписки',
            'statement_time' => 'Время выписки',
            'account_no' => 'Счет выписки',
            'statement_number' => 'Номер выписки',
            'error_message' => 'Причина ошибки',
            'created_at' => 'Дата и время лога',
        ];
    }

    public static function errorCodeLabels(): array
    {
        return [
            self::ERROR_PAGE_SEQUENCE => 'Ошибка номера выписки / порядка страниц',
            self::ERROR_INTERMEDIATE_CONTINUITY => 'Ошибка непрерывности промежуточных балансов',
            self::ERROR_BALANCE_CONTINUITY => 'Ошибка баланса на открытие / закрытие дня',
            self::ERROR_TURNOVER => 'Ошибка оборотов относительно баланса выписки',
        ];
    }

    public function getErrorLabel(): string
    {
        return static::errorCodeLabels()[$this->error_code] ?? $this->error_code;
    }
}
