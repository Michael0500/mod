<?php

namespace app\models;

use yii\db\ActiveRecord;

/**
 * Справочник валют (общесистемный).
 *
 * @property int    $id
 * @property string $code        ISO 4217 (USD, EUR, RUB...)
 * @property string $name
 * @property string|null $symbol
 * @property int    $fractional_digit
 * @property bool   $is_active
 * @property int    $sort_order
 * @property string $created_at
 * @property string $updated_at
 */
class Currency extends ActiveRecord
{
    /**
     * Возвращает имя таблицы справочника валют.
     *
     * @return string Имя таблицы `currencies` с учётом префикса Yii.
     */
    public static function tableName(): string
    {
        return '{{%currencies}}';
    }

    /**
     * Описывает правила валидации валюты.
     *
     * Код валюты хранится в формате ISO 4217 и должен быть уникальным.
     *
     * @return array Правила Yii Validator.
     */
    public function rules(): array
    {
        return [
            [['code', 'name'], 'required'],
            [['code'], 'string', 'max' => 3],
            [['code'], 'match', 'pattern' => '/^[A-Z]{3}$/', 'message' => 'Код должен состоять из 3 заглавных латинских букв'],
            [['code'], 'unique'],
            [['name'], 'string', 'max' => 100],
            [['symbol'], 'string', 'max' => 8],
            [['is_active'], 'boolean'],
            [['sort_order', 'fractional_digit'], 'integer'],
            [['fractional_digit'], 'in', 'range' => [0, 1, 2, 3, 4, 5]],
            [['is_active'], 'default', 'value' => true],
            [['sort_order'], 'default', 'value' => 0],
            [['fractional_digit'], 'default', 'value' => 2],
        ];
    }

    /**
     * Возвращает подписи атрибутов валюты.
     *
     * @return array Массив `attribute => label`.
     */
    public function attributeLabels(): array
    {
        return [
            'id'         => 'ID',
            'code'       => 'Код',
            'name'       => 'Название',
            'symbol'     => 'Символ',
            'fractional_digit' => 'FRACTIONAL DIGIT',
            'is_active'  => 'Активна',
            'sort_order' => 'Порядок',
            'created_at' => 'Создано',
            'updated_at' => 'Обновлено',
        ];
    }

    /**
     * Нормализует код валюты до запуска валидаторов.
     *
     * @return bool Можно ли продолжать валидацию.
     */
    public function beforeValidate(): bool
    {
        if (!parent::beforeValidate()) {
            return false;
        }
        $this->code = strtoupper(trim((string)$this->code));
        return true;
    }

    /**
     * Нормализует код валюты и обновляет служебные даты.
     *
     * @param bool $insert Признак создания новой валюты.
     * @return bool Можно ли продолжать сохранение.
     */
    public function beforeSave($insert): bool
    {
        if (!parent::beforeSave($insert)) {
            return false;
        }
        $this->code = strtoupper(trim($this->code));
        if ($insert && empty($this->created_at)) {
            $this->created_at = date('Y-m-d H:i:s');
        }
        $this->updated_at = date('Y-m-d H:i:s');
        return true;
    }

    /**
     * Возвращает список активных валют для справочников и форм.
     *
     * @return self[]
     */
    public static function activeList(): array
    {
        return self::find()
            ->where(['is_active' => true])
            ->orderBy(['sort_order' => SORT_ASC, 'code' => SORT_ASC])
            ->all();
    }

    /**
     * Возвращает коды активных валют для выпадающих списков.
     *
     * @return string[]
     */
    public static function activeCodes(): array
    {
        return self::find()
            ->select('code')
            ->where(['is_active' => true])
            ->orderBy(['sort_order' => SORT_ASC, 'code' => SORT_ASC])
            ->column();
    }

    /**
     * Возвращает размерность дробной части для кода валюты.
     * Неизвестная валюта получает историческое значение 2.
     */
    public static function fractionalDigitsForCode(?string $code): int
    {
        $code = strtoupper(trim((string)$code));
        if ($code === '') {
            return 2;
        }

        $value = self::find()->select('fractional_digit')->where(['code' => $code])->scalar();
        return $value === false ? 2 : max(0, min(5, (int)$value));
    }
}
