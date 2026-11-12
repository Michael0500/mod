<?php

namespace app\models;

use yii\db\ActiveRecord;

/**
 * Архивная копия балансовой записи Ностро.
 *
 * @property int         $id
 * @property int         $original_id
 * @property int         $company_id
 * @property int|null    $batch_id
 * @property int         $account_id
 * @property string      $ls_type
 * @property string|null $statement_number
 * @property string      $currency
 * @property string      $value_date
 * @property string      $opening_balance
 * @property string      $opening_dc
 * @property string      $closing_balance
 * @property string      $closing_dc
 * @property string      $section
 * @property string      $source
 * @property string      $status
 * @property string|null $comment
 * @property string|null $branch_code
 * @property int|null    $extract_no
 * @property int|null    $line_no
 * @property string|null $stmt_id
 * @property string|null $edno
 * @property string|null $eddate
 * @property string|null $edauthor
 * @property int|null    $created_by
 * @property int|null    $updated_by
 * @property string      $archived_at
 * @property string      $expires_at
 * @property int|null    $archived_by
 * @property string|null $original_created_at
 * @property string|null $original_updated_at
 */
class NostroBalanceArchive extends ActiveRecord
{
    /**
     * Возвращает имя таблицы архива балансов.
     *
     * @return string Имя таблицы `nostro_balance_archive`.
     */
    public static function tableName(): string
    {
        return '{{%nostro_balance_archive}}';
    }

    /**
     * Описывает правила валидации архивной строки.
     *
     * @return array Правила Yii Validator.
     */
    public function rules(): array
    {
        return [
            [['original_id', 'company_id', 'account_id', 'ls_type', 'currency', 'value_date',
                'opening_balance', 'opening_dc', 'closing_balance', 'closing_dc', 'section',
                'source', 'status', 'archived_at', 'expires_at'], 'required'],
            [['original_id', 'company_id', 'account_id', 'line_no', 'created_by', 'updated_by', 'archived_by', 'batch_id'], 'integer'],
            [['opening_balance', 'closing_balance', 'stmt_id', 'edno'], 'number'],
            [['value_date', 'eddate', 'archived_at', 'expires_at', 'original_created_at', 'original_updated_at'], 'safe'],
            [['ls_type', 'opening_dc', 'closing_dc'], 'string', 'max' => 1],
            [['currency', 'section', 'branch_code'], 'string', 'max' => 3],
            [['statement_number'], 'string', 'max' => 35],
            [['source'], 'string', 'max' => 20],
            [['status', 'edauthor'], 'string', 'max' => 10],
            [['comment'], 'string', 'max' => 255],
            [['extract_no'], 'integer'],
        ];
    }

    /**
     * Возвращает связь с ностро-счётом.
     *
     * @return \yii\db\ActiveQuery
     */
    public function getAccount(): \yii\db\ActiveQuery
    {
        return $this->hasOne(Account::class, ['id' => 'account_id']);
    }

    /**
     * Возвращает дату валютирования для интерфейса.
     *
     * @return string
     */
    public function getValueDateFormatted(): string
    {
        return $this->value_date ? date('d.m.Y', strtotime($this->value_date)) : '';
    }

    /**
     * Преобразует архивную строку в JSON-структуру.
     *
     * @return array
     */
    public function toApiArray(): array
    {
        return [
            'id'                  => $this->id,
            'original_id'         => $this->original_id,
            'account_id'          => $this->account_id,
            'company_id'          => $this->company_id,
            'ls_type'             => $this->ls_type,
            'statement_number'    => $this->statement_number,
            'currency'            => $this->currency,
            'value_date'          => $this->value_date,
            'value_date_fmt'      => $this->getValueDateFormatted(),
            'opening_balance'     => $this->opening_balance,
            'opening_dc'          => $this->opening_dc,
            'closing_balance'     => $this->closing_balance,
            'closing_dc'          => $this->closing_dc,
            'section'             => $this->section,
            'source'              => $this->source,
            'status'              => $this->status,
            'comment'             => $this->comment,
            'branch_code'         => $this->branch_code,
            'extract_no'          => $this->extract_no,
            'line_no'             => $this->line_no,
            'stmt_id'             => $this->stmt_id,
            'edno'                => $this->edno,
            'eddate'              => $this->eddate,
            'edauthor'            => $this->edauthor,
            'archived_at'         => $this->archived_at,
            'expires_at'          => $this->expires_at,
            'archived_by'         => $this->archived_by,
            'batch_id'            => $this->batch_id,
            'original_created_at' => $this->original_created_at,
            'original_updated_at' => $this->original_updated_at,
        ];
    }
}
