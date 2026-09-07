<?php

namespace app\models;

use Yii;
use yii\db\ActiveRecord;

/**
 * Правило автоматического квитования.
 *
 * @property int $id
 * @property int $company_id
 * @property int|null $pool_id Legacy-поле для совместимости со старой схемой
 * @property string $name
 * @property string $section
 * @property string $pair_type
 * @property bool $match_dc
 * @property bool $match_amount
 * @property bool $match_value_date
 * @property bool $match_instruction_id
 * @property bool $match_end_to_end_id
 * @property bool $match_transaction_id
 * @property bool $match_message_id
 * @property string|null $reference_value
 * @property bool $cross_id_search
 * @property bool $id_prefix_match
 * @property int|null $id_prefix_length
 * @property bool $group_match_enabled
 * @property string|null $group_ls_type
 * @property bool $is_active
 * @property int $priority
 * @property string|null $description
 * @property string $created_at
 * @property string $updated_at
 */
class MatchingRule extends ActiveRecord
{
    /** @var int[] Список ностро-банков правила; пусто = общее правило. */
    public array $pool_ids = [];

    public const SECTION_NRE = 'NRE';
    public const SECTION_INV = 'INV';

    public const PAIR_LS = 'LS';
    public const PAIR_LL = 'LL';
    public const PAIR_SS = 'SS';

    public const GROUP_LS = 'LS';
    public const GROUP_L = 'L';
    public const GROUP_S = 'S';

    public static function tableName(): string
    {
        return '{{%matching_rules}}';
    }

    public function rules(): array
    {
        return [
            [['company_id', 'name', 'section', 'pair_type'], 'required'],
            [['company_id', 'priority', 'pool_id'], 'integer'],
            [['pool_id'], 'default', 'value' => null],
            [['pool_ids'], 'default', 'value' => []],
            [['pool_ids'], 'each', 'rule' => ['integer']],
            [['pool_ids'], 'validatePoolIds'],
            [['section'], 'in', 'range' => [self::SECTION_NRE, self::SECTION_INV]],
            [['pair_type'], 'in', 'range' => [self::PAIR_LS, self::PAIR_LL, self::PAIR_SS]],
            [[
                'match_dc',
                'match_amount',
                'match_value_date',
                'match_instruction_id',
                'match_end_to_end_id',
                'match_transaction_id',
                'match_message_id',
                'cross_id_search',
                'id_prefix_match',
                'group_match_enabled',
                'is_active',
            ], 'boolean'],
            [['reference_value'], 'trim'],
            [['reference_value'], 'default', 'value' => null],
            [['reference_value'], 'string', 'max' => 60],
            [['reference_value'], 'validateReferenceValueSettings'],
            [['id_prefix_length'], 'default', 'value' => null],
            [['id_prefix_length'], 'integer', 'min' => 1, 'max' => 60],
            [['id_prefix_match', 'id_prefix_length'], 'validateIdPrefixSettings'],
            [['group_ls_type'], 'default', 'value' => null],
            [['group_ls_type'], 'in', 'range' => [self::GROUP_LS, self::GROUP_L, self::GROUP_S]],
            [['group_match_enabled', 'group_ls_type'], 'validateGroupMatchSettings'],
            [['name'], 'string', 'max' => 100],
            [['description'], 'string'],
        ];
    }

    public function attributeLabels(): array
    {
        return [
            'id' => 'ID',
            'name' => 'Название правила',
            'pool_id' => 'Ностро-банк',
            'pool_ids' => 'Ностро-банки',
            'section' => 'Раздел',
            'pair_type' => 'Тип пары',
            'match_dc' => 'Противоположный Дебет/Кредит',
            'match_amount' => 'Совпадение суммы',
            'match_value_date' => 'Совпадение даты валютирования',
            'match_instruction_id' => 'Instruction ID',
            'match_end_to_end_id' => 'EndToEnd ID',
            'match_transaction_id' => 'Transaction ID',
            'match_message_id' => 'Message ID',
            'reference_value' => 'Значение референса',
            'cross_id_search' => 'Перекрёстный поиск ID',
            'id_prefix_match' => 'Сравнение идентификаторов по первым символам',
            'id_prefix_length' => 'Количество символов идентификатора',
            'group_match_enabled' => 'Групповое квитование',
            'group_ls_type' => 'Тип бух.проводки (группа)',
            'is_active' => 'Активно',
            'priority' => 'Приоритет',
            'description' => 'Описание',
        ];
    }

    public function beforeValidate(): bool
    {
        if (!parent::beforeValidate()) {
            return false;
        }

        $this->normalizePoolIds();
        if (is_string($this->group_ls_type)) {
            $this->group_ls_type = trim($this->group_ls_type);
        }

        if (empty($this->pool_ids) && $this->pool_id) {
            $this->pool_ids = [(int) $this->pool_id];
        }

        return true;
    }

    public function beforeSave($insert): bool
    {
        if (!parent::beforeSave($insert)) {
            return false;
        }

        $this->normalizePoolIds();
        $this->pool_id = !empty($this->pool_ids) ? (int) $this->pool_ids[0] : null;
        if (!$this->id_prefix_match) {
            $this->id_prefix_length = null;
        }
        if (!$this->group_match_enabled) {
            $this->group_ls_type = null;
        }
        $this->reference_value = $this->normalizeReferenceValue($this->reference_value);
        $this->updated_at = date('Y-m-d H:i:s');
        if ($insert) {
            $this->created_at = date('Y-m-d H:i:s');
        }

        return true;
    }

    public function afterFind(): void
    {
        parent::afterFind();

        $this->pool_ids = array_map('intval', Yii::$app->db->createCommand(
            'SELECT pool_id FROM {{%matching_rule_pools}} WHERE rule_id = :ruleId ORDER BY pool_id',
            [':ruleId' => $this->id]
        )->queryColumn());

        if (empty($this->pool_ids) && $this->pool_id) {
            $this->pool_ids = [(int) $this->pool_id];
        }
        if (is_string($this->group_ls_type)) {
            $this->group_ls_type = trim($this->group_ls_type);
        }
    }

    public function afterSave($insert, $changedAttributes): void
    {
        parent::afterSave($insert, $changedAttributes);
        $this->syncPoolAssignments();
    }

    public static function sectionList(): array
    {
        return [
            self::SECTION_NRE => 'NRE',
            self::SECTION_INV => 'INV',
        ];
    }

    public static function pairTypeList(): array
    {
        return [
            self::PAIR_LS => 'Ledger ↔ Statement',
            self::PAIR_LL => 'Ledger ↔ Ledger',
            self::PAIR_SS => 'Statement ↔ Statement',
        ];
    }

    public static function groupLsTypeList(): array
    {
        return [
            self::GROUP_LS => 'Ledger и Statement',
            self::GROUP_L => 'Только Ledger',
            self::GROUP_S => 'Только Statement',
        ];
    }

    public function getCompany()
    {
        return $this->hasOne(Company::class, ['id' => 'company_id']);
    }

    public function getPool()
    {
        return $this->hasOne(AccountPool::class, ['id' => 'pool_id']);
    }

    public function getPools()
    {
        return $this->hasMany(AccountPool::class, ['id' => 'pool_id'])
            ->viaTable('{{%matching_rule_pools}}', ['rule_id' => 'id']);
    }

    public function getConditionsSummary(): string
    {
        $parts = [];
        if ($this->match_dc) {
            $parts[] = 'D/C';
        }
        if ($this->match_amount) {
            $parts[] = 'Сумма';
        }
        if ($this->match_value_date) {
            $parts[] = 'Дата';
        }
        if ($this->match_instruction_id) {
            $parts[] = 'Instruction';
        }
        if ($this->match_end_to_end_id) {
            $parts[] = 'EndToEnd';
        }
        if ($this->match_transaction_id) {
            $parts[] = 'Transaction';
        }
        if ($this->match_message_id) {
            $parts[] = 'Message';
        }
        if ($this->reference_value !== null && $this->reference_value !== '') {
            $parts[] = 'Reference=' . $this->reference_value;
        }
        if ($this->id_prefix_match && $this->id_prefix_length) {
            $parts[] = 'ID prefix ' . $this->id_prefix_length;
        }
        if ($this->cross_id_search) {
            $parts[] = 'Перекрёстный';
        }
        if ($this->group_match_enabled) {
            $parts[] = 'Группа: ' . (self::groupLsTypeList()[$this->group_ls_type] ?? $this->group_ls_type);
        }

        return implode(', ', $parts) ?: '—';
    }

    /**
     * @return int[]
     */
    public function getEffectivePoolIds(): array
    {
        $this->normalizePoolIds();
        if (!empty($this->pool_ids)) {
            return $this->pool_ids;
        }

        return $this->pool_id ? [(int) $this->pool_id] : [];
    }

    /**
     * @return string[]
     */
    public function getPoolNames(): array
    {
        $ids = $this->getEffectivePoolIds();
        if (empty($ids)) {
            return [];
        }

        $namesById = AccountPool::find()
            ->select(['name', 'id'])
            ->where(['company_id' => $this->company_id, 'id' => $ids])
            ->indexBy('id')
            ->column();

        $result = [];
        foreach ($ids as $id) {
            if (isset($namesById[$id])) {
                $result[] = $namesById[$id];
            }
        }

        return $result;
    }

    public function validatePoolIds(string $attribute): void
    {
        $this->normalizePoolIds();
        if (empty($this->pool_ids)) {
            return;
        }

        $count = (int) AccountPool::find()
            ->where(['company_id' => $this->company_id, 'id' => $this->pool_ids])
            ->count();

        if ($count !== count($this->pool_ids)) {
            $this->addError($attribute, 'Один или несколько выбранных ностро-банков не найдены в этой компании');
        }
    }

    public function validateIdPrefixSettings(): void
    {
        if (!$this->id_prefix_match) {
            $this->id_prefix_length = null;
            return;
        }

        if (!$this->hasAnyMatchedIdField()) {
            $this->addError('id_prefix_match', 'Префиксное сравнение доступно только при включённом хотя бы одном ID-поле');
        }

        if ($this->id_prefix_length === null || $this->id_prefix_length === '') {
            $this->addError('id_prefix_length', 'Укажите количество символов для сравнения идентификаторов');
        }
    }

    public function hasAnyMatchedIdField(): bool
    {
        return $this->match_instruction_id
            || $this->match_end_to_end_id
            || $this->match_transaction_id
            || $this->match_message_id;
    }

    public function validateReferenceValueSettings(): void
    {
        $this->reference_value = $this->normalizeReferenceValue($this->reference_value);

        if ($this->reference_value === null) {
            return;
        }

        if (!$this->hasAnyMatchedIdField()) {
            $this->addError('reference_value', 'Значение референса можно искать только при включённом хотя бы одном ID-поле');
        }
    }

    public function validateGroupMatchSettings(): void
    {
        if (!$this->group_match_enabled) {
            $this->group_ls_type = null;
            return;
        }

        if ($this->group_ls_type === null || $this->group_ls_type === '') {
            $this->addError('group_ls_type', 'Укажите тип бух.проводки для группового квитования');
        }

        if (!$this->hasAnyMatchedIdField()) {
            $this->addError('group_match_enabled', 'Групповое квитование доступно только при включённом хотя бы одном ID-поле');
        }

        $pairTypes = $this->pairTypesAsList($this->pair_type);
        $groupTypes = $this->groupTypesAsList($this->group_ls_type);
        if (!array_intersect($pairTypes, $groupTypes)) {
            $this->addError('group_ls_type', 'Тип бух.проводки группы должен соответствовать типу пары');
        }
    }

    /**
     * @return string[]
     */
    public function getSelectedReferenceFields(): array
    {
        $fields = [];
        if ($this->match_instruction_id) {
            $fields[] = 'instruction_id';
        }
        if ($this->match_end_to_end_id) {
            $fields[] = 'end_to_end_id';
        }
        if ($this->match_transaction_id) {
            $fields[] = 'transaction_id';
        }
        if ($this->match_message_id) {
            $fields[] = 'message_id';
        }
        return $fields;
    }

    private function normalizePoolIds(): void
    {
        $raw = $this->pool_ids;
        if (!is_array($raw)) {
            $raw = ($raw === null || $raw === '') ? [] : [$raw];
        }

        $poolIds = [];
        foreach ($raw as $value) {
            if ($value === null || $value === '' || (int) $value === 0) {
                continue;
            }
            $poolIds[] = (int) $value;
        }

        $poolIds = array_values(array_unique($poolIds));
        sort($poolIds);
        $this->pool_ids = $poolIds;
    }

    private function normalizeReferenceValue($value): ?string
    {
        $value = is_string($value) ? trim($value) : $value;
        return ($value === null || $value === '') ? null : (string) $value;
    }

    private function pairTypesAsList(?string $pairType): array
    {
        switch ($pairType) {
            case self::PAIR_LL:
                return [self::GROUP_L];
            case self::PAIR_SS:
                return [self::GROUP_S];
            case self::PAIR_LS:
            default:
                return [self::GROUP_L, self::GROUP_S];
        }
    }

    private function groupTypesAsList(?string $groupType): array
    {
        switch ($groupType) {
            case self::GROUP_L:
                return [self::GROUP_L];
            case self::GROUP_S:
                return [self::GROUP_S];
            case self::GROUP_LS:
                return [self::GROUP_L, self::GROUP_S];
            default:
                return [];
        }
    }

    private function syncPoolAssignments(): void
    {
        $poolIds = $this->getEffectivePoolIds();

        Yii::$app->db->createCommand()
            ->delete('{{%matching_rule_pools}}', ['rule_id' => $this->id])
            ->execute();

        if (empty($poolIds)) {
            return;
        }

        $rows = [];
        foreach ($poolIds as $poolId) {
            $rows[] = [$this->id, $poolId];
        }

        Yii::$app->db->createCommand()
            ->batchInsert('{{%matching_rule_pools}}', ['rule_id', 'pool_id'], $rows)
            ->execute();
    }
}
