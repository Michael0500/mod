<?php

namespace app\models;

use yii\base\Model;
use yii\data\ActiveDataProvider;

/**
 * Поиск по журналу ошибок проверок TDS-выписок.
 */
class TdsValidationErrorSearch extends TdsValidationError
{
    public ?string $date_from = null;
    public ?string $date_to = null;

    public function rules()
    {
        return [
            [['id', 'company_id', 'batch_id'], 'integer'],
            [['statement_type', 'error_code', 'statement_date', 'statement_time', 'account_no', 'statement_number', 'error_message', 'created_at', 'date_from', 'date_to'], 'safe'],
        ];
    }

    public function scenarios()
    {
        return Model::scenarios();
    }

    public function search(array $params, int $companyId): ActiveDataProvider
    {
        $query = TdsValidationError::find()
            ->where(['company_id' => $companyId])
            ->orderBy(['created_at' => SORT_DESC, 'id' => SORT_DESC]);

        $dataProvider = new ActiveDataProvider([
            'query' => $query,
            'sort' => ['defaultOrder' => ['created_at' => SORT_DESC, 'id' => SORT_DESC]],
            'pagination' => ['pageSize' => 50],
        ]);

        $this->load($params);

        if (!$this->validate()) {
            return $dataProvider;
        }

        $query->andFilterWhere([
            'id' => $this->id,
            'batch_id' => $this->batch_id,
            'statement_type' => $this->statement_type,
            'error_code' => $this->error_code,
            'statement_date' => $this->statement_date,
            'statement_time' => $this->statement_time,
        ]);

        $query->andFilterWhere(['>=', 'statement_date', $this->date_from])
            ->andFilterWhere(['<=', 'statement_date', $this->date_to])
            ->andFilterWhere(['ilike', 'account_no', $this->account_no])
            ->andFilterWhere(['ilike', 'statement_number', $this->statement_number])
            ->andFilterWhere(['ilike', 'error_message', $this->error_message]);

        return $dataProvider;
    }
}
