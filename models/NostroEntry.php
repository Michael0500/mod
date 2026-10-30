<?php

namespace app\models;

use Yii;
use yii\db\ActiveRecord;

/**
 * Запись (данные) для выверки Ностро.
 *
 * @property int         $id
 * @property int         $account_id
 * @property int         $company_id
 * @property int|null    $posting_id
 * @property string|null $match_id
 * @property string      $ls           L=Ledger, S=Statement
 * @property string      $dc           Debit|Credit
 * @property float       $amount
 * @property string      $currency
 * @property string|null $value_date
 * @property string|null $post_date
 * @property string|null $instruction_id
 * @property string|null $end_to_end_id
 * @property string|null $transaction_id
 * @property string|null $message_id
 * @property string|null $statement_number
 * @property string|null $other_id
 * @property string|null $comment
 * @property string|null $source
 * @property string|null $branch_code
 * @property string      $match_status  U/M/I
 * @property string|null $matched_at
 * @property int|null    $created_by
 * @property int|null    $updated_by
 * @property string      $created_at
 * @property string      $updated_at
 *
 * @property Account     $account
 * @property Company     $company
 * @property NostroEntryAudit[] $audits
 */
class NostroEntry extends ActiveRecord
{
    public bool $skipAudit = false;

    const MONEY_MAX_INTEGER_DIGITS = 18;
    const MONEY_SCALE = 5;

    // Типы записей
    const LS_LEDGER    = 'L';
    const LS_STATEMENT = 'S';

    // Тип операции
    const DC_DEBIT  = 'Debit';
    const DC_CREDIT = 'Credit';

    // Статусы квитования
    const STATUS_UNMATCHED = 'U';
    const STATUS_MATCHED   = 'M';
    const STATUS_IGNORED   = 'I';

    /**
     * Возвращает имя таблицы активных записей выверки.
     *
     * Таблица хранит рабочий набор Ledger/Statement операций до их переноса
     * в архив. Все выборки прикладного уровня должны дополнительно скоупиться
     * по `company_id`.
     *
     * @return string Имя таблицы `nostro_entries` с учётом префикса Yii.
     */
    public static function tableName(): string
    {
        return '{{%nostro_entries}}';
    }

    /**
     * Описывает правила валидации записи выверки.
     *
     * Валидация закрепляет обязательные поля операции, допустимые значения
     * `ls`, `dc`, `match_status`, связи со счётом и компанией, а также
     * денежную точность `decimal(26,5)` без приведения пользовательского
     * ввода к `float`.
     *
     * @return array Правила Yii Validator для формы и ActiveRecord.
     */
    public function rules(): array
    {
        return [
            [['account_id', 'company_id', 'ls', 'dc', 'amount', 'currency'], 'required'],
            [['account_id', 'company_id', 'posting_id', 'created_by', 'updated_by'], 'integer'],
            [['amount'], 'validateMoneyAmount'],
            [['value_date', 'post_date', 'matched_at', 'created_at', 'updated_at'], 'safe'],
            [['ls'], 'string', 'max' => 1],
            [['ls'], 'in', 'range' => [self::LS_LEDGER, self::LS_STATEMENT]],
            [['dc'], 'string', 'max' => 6],
            [['dc'], 'in', 'range' => [self::DC_DEBIT, self::DC_CREDIT]],
            [['currency'], 'string', 'max' => 3],
            [['match_id'], 'string', 'max' => 255],
            [['instruction_id', 'message_id', 'end_to_end_id', 'other_id'], 'string', 'max' => 40],
            [['statement_number'], 'string', 'max' => 35],
            [['transaction_id'], 'string', 'max' => 60],
            [['comment'], 'string', 'max' => 40],
            [['source'], 'string', 'max' => 20],
            [['branch_code'], 'string', 'max' => 3],
            [['match_status'], 'string', 'max' => 1],
            [['match_status'], 'in', 'range' => [self::STATUS_UNMATCHED, self::STATUS_MATCHED, self::STATUS_IGNORED]],
            [['match_status'], 'default', 'value' => self::STATUS_UNMATCHED],
            [['account_id'], 'exist', 'targetClass' => Account::class, 'targetAttribute' => ['account_id' => 'id']],
            [['company_id'], 'exist', 'targetClass' => Company::class, 'targetAttribute' => ['company_id' => 'id']],
        ];
    }

    /**
     * Проверяет денежное поле на формат `decimal(26,5)` и шкалу валюты.
     *
     * Для операций выверки допустимы только положительные суммы: знак проводки
     * хранится отдельно в `dc`. Ограничение в 18 цифр до десятичного разделителя
     * является прикладным ограничением для колонки `amount decimal(26,5)`.
     *
     * @param string $attribute Имя проверяемого атрибута суммы.
     * @return void
     */
    public function validateMoneyAmount($attribute): void
    {
        $value = trim((string)$this->$attribute);
        if (!preg_match('/^\d+(?:\.\d{1,5})?$/', $value)) {
            $this->addError($attribute, 'Сумма должна быть положительным числом с максимум 5 знаками после точки.');
            return;
        }

        $scale = Currency::fractionalDigitsForCode($this->currency);
        $fraction = explode('.', $value, 2)[1] ?? '';
        if (trim(substr($fraction, $scale), '0') !== '') {
            $this->addError($attribute, "Для валюты {$this->currency} допустимо максимум {$scale} знаков после точки.");
            return;
        }

        [$integerPart] = explode('.', $value, 2);
        $integerPart = ltrim($integerPart, '0');
        if (strlen($integerPart) > self::MONEY_MAX_INTEGER_DIGITS) {
            $this->addError($attribute, 'Сумма слишком большая: максимум 18 цифр до точки и 5 после.');
        }
    }

    /**
     * Возвращает человекочитаемые подписи атрибутов для форм и ошибок.
     *
     * @return array Массив `attribute => label`.
     */
    public function attributeLabels(): array
    {
        return [
            'id'             => 'ID',
            'account_id'     => 'Ностро банк',
            'company_id'     => 'Компания',
            'posting_id'     => 'Posting ID',
            'match_id'       => 'Match ID',
            'ls'             => 'L/S',
            'dc'             => 'D/C',
            'amount'         => 'Сумма',
            'currency'       => 'Валюта',
            'value_date'     => 'Дата валютирования',
            'post_date'      => 'Дата проводки',
            'instruction_id' => 'Instruction ID',
            'end_to_end_id'  => 'EndToEnd ID',
            'transaction_id' => 'Transaction ID',
            'message_id'     => 'Message ID',
            'statement_number' => 'Номер выписки',
            'other_id'       => 'Other ID',
            'comment'        => 'Комментарий',
            'source'         => 'Источник',
            'branch_code'    => 'Код филиала',
            'match_status'   => 'Статус квитования',
            'matched_at'     => 'Дата квитования',
            'created_by'     => 'Создал',
            'updated_by'     => 'Обновил',
            'created_at'     => 'Создано',
            'updated_at'     => 'Обновлено',
        ];
    }

    // -----------------------------------------------------------------
    // Связи
    // -----------------------------------------------------------------

    /**
     * Возвращает связь с ностро-счётом операции.
     *
     * @return \yii\db\ActiveQuery Запрос связи `nostro_entries.account_id -> accounts.id`.
     */
    public function getAccount(): \yii\db\ActiveQuery
    {
        return $this->hasOne(Account::class, ['id' => 'account_id']);
    }

    /**
     * Возвращает связь с компанией-владельцем операции.
     *
     * @return \yii\db\ActiveQuery Запрос связи `nostro_entries.company_id -> company.id`.
     */
    public function getCompany(): \yii\db\ActiveQuery
    {
        return $this->hasOne(Company::class, ['id' => 'company_id']);
    }

    // -----------------------------------------------------------------
    // Хелперы
    // -----------------------------------------------------------------

    /**
     * Возвращает пользовательские названия статусов квитования.
     *
     * @return array Карта `код статуса => русская метка`.
     */
    public static function matchStatusLabels(): array
    {
        return [
            self::STATUS_UNMATCHED => 'Не квитовано',
            self::STATUS_MATCHED   => 'Квитовано',
            self::STATUS_IGNORED   => 'Игнорировано',
        ];
    }

    /**
     * Подбирает Bootstrap-класс бейджа для текущего статуса квитования.
     *
     * @return string CSS-модификатор бейджа.
     */
    public function matchStatusBadge(): string
    {
        $map = [self::STATUS_MATCHED => 'success', self::STATUS_IGNORED => 'secondary'];
        return $map[$this->match_status] ?? 'warning';
    }

    /**
     * Форматирует сумму операции для вывода в интерфейсе.
     *
     * @return string Сумма с двумя знаками и разделителями тысяч.
     */
    public function formattedAmount(): string
    {
        $scale = Currency::fractionalDigitsForCode($this->currency);
        [$integer, $fraction] = array_pad(explode('.', (string)$this->amount, 2), 2, '');
        $integer = preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $integer);
        return $integer . '.' . substr($fraction . '00000', 0, $scale);
    }

    // -----------------------------------------------------------------
    // Хуки
    // -----------------------------------------------------------------

    /**
     * Заполняет служебные поля перед сохранением записи.
     *
     * При создании фиксирует автора и дату создания, при любом сохранении
     * обновляет `updated_at/updated_by`. Для статуса `M` автоматически
     * проставляет `matched_at`, а для остальных статусов очищает дату квитования.
     *
     * @param bool $insert Признак создания новой строки.
     * @return bool Можно ли продолжать сохранение.
     */
    public function beforeSave($insert): bool
    {
        if (!parent::beforeSave($insert)) {
            return false;
        }

        $now = date('Y-m-d H:i:s');

        if ($insert) {
            $this->created_at = $now;
            $this->created_by = Yii::$app->user->id ?? null;
        }
        if ($this->match_status === self::STATUS_MATCHED) {
            $this->matched_at = $this->matched_at ?: $now;
        } else {
            $this->matched_at = null;
        }

        $this->updated_at = $now;
        $this->updated_by = Yii::$app->user->id ?? null;

        return true;
    }

    /**
     * Пишет аудит создания и изменения записи после успешного сохранения.
     *
     * Технические изменения `updated_at` и `updated_by` не логируются отдельно.
     * При восстановлении из архива или массовом импорте аудит может быть
     * подавлен через `skipAudit`, если вызывающий код пишет специальные события.
     *
     * @param bool $insert Признак создания новой строки.
     * @param array $changedAttributes Старые значения изменённых атрибутов.
     * @return void
     */
    public function afterSave($insert, $changedAttributes)
    {
        parent::afterSave($insert, $changedAttributes);

        if ($this->skipAudit) {
            return;
        }

        if ($insert) {
            // Логирование создания
            NostroEntryAudit::log(
                $this->id,
                NostroEntryAudit::ACTION_CREATE,
                null,
                $this->getAttributesForAudit()
            );
        } else {
            // Логирование обновлений (только изменённые поля)
            foreach ($changedAttributes as $field => $oldValue) {
                // Пропускаем служебные поля.updated_at и updated_by
                if (in_array($field, ['updated_at', 'updated_by'])) {
                    continue;
                }

                $newValue = $this->$field;
                if ($oldValue !== $newValue) {
                    NostroEntryAudit::log(
                        $this->id,
                        NostroEntryAudit::ACTION_UPDATE,
                        [$field => $oldValue],
                        [$field => $newValue],
                        $field
                    );
                }
            }
        }
    }

    /**
     * Пишет аудит удаления до физического удаления активной записи.
     *
     * Событие создаётся заранее, чтобы `entry_id` ещё ссылался на исходную
     * запись и историю можно было восстановить после удаления или архивации.
     *
     * @return bool Можно ли продолжать удаление.
     */
    public function beforeDelete()
    {
        // Логирование удаления (ДО фактического удаления, чтобы entry_id ещё существовал)
        NostroEntryAudit::log(
            $this->id,
            NostroEntryAudit::ACTION_DELETE,
            $this->getAttributesForAudit(),
            null,
            null,
            null,
            'Запись удалена'
        );

        return parent::beforeDelete();
    }

    /**
     * Выполняет стандартную обработку Yii после удаления записи.
     *
     * Сейчас дополнительной бизнес-логики нет, но метод оставлен как точка
     * расширения для операций после удаления.
     *
     * @return void
     */
    public function afterDelete()
    {
        parent::afterDelete();
    }

    /**
     * Возвращает историю изменений активной записи.
     *
     * @return \yii\db\ActiveQuery Запрос к событиям аудита, отсортированный от новых к старым.
     */
    public function getAudits(): \yii\db\ActiveQuery
    {
        return $this->hasMany(NostroEntryAudit::class, ['entry_id' => 'id'])
            ->orderBy(['created_at' => SORT_DESC]);
    }

    /**
     * Логирует перенос активной записи в архив.
     *
     * Метод вызывается процессом архивирования после создания строки в
     * `nostro_entries_archive`, чтобы аудит сохранил связь активной записи
     * с архивной копией.
     *
     * @param int $archivedId ID созданной архивной записи
     * @return void
     */
    public function logArchive(int $archivedId): void
    {
        NostroEntryAudit::log(
            $this->id,
            NostroEntryAudit::ACTION_ARCHIVE,
            $this->getAttributesForAudit(),
            null,
            null,
            $archivedId,
            'Запись заархивирована'
        );
    }

    /**
     * Возвращает снимок атрибутов записи для сохранения в аудите.
     *
     * @return array Текущие значения атрибутов ActiveRecord.
     */
    private function getAttributesForAudit(): array
    {
        $attrs = $this->getAttributes();
        // Можно исключить служебные поля при необходимости
        return $attrs;
    }
}
