<?php

namespace app\controllers;

use app\models\MatchingRule;
use app\models\NostroEntry;
use app\models\User;
use app\services\MatchingService;
use Yii;
use yii\web\Response;

/**
 * JSON API контроллер квитования и правил автоквитования.
 */
class MatchingController extends BaseController
{
    public function beforeAction($action): bool
    {
        $this->enableCsrfValidation = false;
        return parent::beforeAction($action);
    }

    private function service(): MatchingService
    {
        return new MatchingService();
    }

    private function companyId(): ?int
    {
        $user = User::findOne(Yii::$app->user->id);
        return $user ? $user->company_id : null;
    }

    private function companySection(): ?string
    {
        $user = User::findOne(Yii::$app->user->id);
        if (!$user || !$user->company_id) {
            return null;
        }

        $company = \app\models\Company::findOne($user->company_id);
        if (!$company) {
            return null;
        }

        $code = strtoupper((string) $company->code);
        return in_array($code, [MatchingRule::SECTION_NRE, MatchingRule::SECTION_INV], true) ? $code : null;
    }

    private function postBool(string $name, bool $default = false): bool
    {
        $value = Yii::$app->request->post($name, $default);
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @return int[]
     */
    private function requestPoolIds(): array
    {
        $poolIdsRaw = Yii::$app->request->post('pool_ids');
        if (!is_array($poolIdsRaw)) {
            $legacyPoolId = Yii::$app->request->post('pool_id');
            $poolIdsRaw = ($legacyPoolId === null || $legacyPoolId === '' || (int) $legacyPoolId === 0)
                ? []
                : [(int) $legacyPoolId];
        }

        $poolIds = [];
        foreach ($poolIdsRaw as $poolId) {
            if ($poolId === null || $poolId === '' || (int) $poolId === 0) {
                continue;
            }
            $poolIds[] = (int) $poolId;
        }

        $poolIds = array_values(array_unique($poolIds));
        sort($poolIds);
        return $poolIds;
    }

    public function actionMatchManual(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $ids = Yii::$app->request->post('ids');
        $section = Yii::$app->request->post('section') ?: $this->companySection();

        if (!is_array($ids) || count($ids) < 1) {
            return ['success' => false, 'message' => 'Выберите записи для квитования'];
        }

        $companyId = $this->companyId();
        if (!$companyId) {
            return ['success' => false, 'message' => 'Компания не определена'];
        }

        return $this->service()->matchManual(array_map('intval', $ids), $section, $companyId);
    }

    public function actionUnmatch(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $matchId = Yii::$app->request->post('match_id');
        if (!$matchId) {
            return ['success' => false, 'message' => 'Не указан Match ID'];
        }

        $companyId = $this->companyId();
        if (!$companyId) {
            return ['success' => false, 'message' => 'Компания не определена'];
        }

        return $this->service()->unmatch((string) $matchId, $companyId);
    }

    public function actionCalcSummary(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $ids = Yii::$app->request->post('ids');
        if (!is_array($ids)) {
            return ['success' => false, 'message' => 'Нет данных'];
        }

        $companyId = $this->companyId();
        if (!$companyId) {
            return ['success' => false, 'message' => 'Компания не определена'];
        }

        return [
            'success' => true,
            'data' => $this->service()->calcSummary(array_map('intval', $ids), $companyId),
        ];
    }

    public function actionAutoMatch(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $companyId = $this->companyId();
        if (!$companyId) {
            return ['success' => false, 'message' => 'Компания не определена'];
        }

        $accountId = Yii::$app->request->post('account_id')
            ? (int) Yii::$app->request->post('account_id')
            : null;
        $section = Yii::$app->request->post('section') ?: $this->companySection();

        return $this->service()->autoMatch($companyId, $accountId, null, $section);
    }

    public function actionAutoMatchStart(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $companyId = $this->companyId();
        if (!$companyId) {
            return ['success' => false, 'message' => 'Компания не определена'];
        }

        $accountId = Yii::$app->request->post('account_id')
            ? (int) Yii::$app->request->post('account_id')
            : null;
        $section = Yii::$app->request->post('section') ?: $this->companySection();
        $scopeType = Yii::$app->request->post('scope_type') ?: 'all';
        $scopeId = Yii::$app->request->post('scope_id')
            ? (int) Yii::$app->request->post('scope_id')
            : null;

        return $this->service()->autoMatchStart($companyId, $accountId, $section, $scopeType, $scopeId);
    }

    public function actionAutoMatchStep(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $jobId = Yii::$app->request->post('job_id');
        if (!$jobId) {
            return ['success' => false, 'message' => 'Не указан job_id'];
        }

        return $this->service()->autoMatchStep((string) $jobId);
    }

    public function actionAutoMatchCancel(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $jobId = Yii::$app->request->post('job_id');
        if (!$jobId) {
            return ['success' => false, 'message' => 'Не указан job_id'];
        }

        return $this->service()->autoMatchCancel((string) $jobId, $this->companyId());
    }

    public function actionMatchGroup(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $matchId = Yii::$app->request->get('match_id');
        if (!$matchId) {
            return ['success' => false, 'message' => 'Не указан Match ID'];
        }

        $companyId = $this->companyId();
        $entries = NostroEntry::find()
            ->alias('e')
            ->select([
                'e.id',
                'e.account_id',
                'e.ls',
                'e.dc',
                'e.amount',
                'e.currency',
                'e.value_date',
                'e.post_date',
                'e.instruction_id',
                'e.end_to_end_id',
                'e.transaction_id',
                'e.message_id',
                'e.match_id',
                'e.match_status',
                'e.comment',
                'a.name AS account_name',
            ])
            ->leftJoin('accounts a', 'a.id = e.account_id')
            ->where(['e.match_id' => $matchId, 'e.company_id' => $companyId])
            ->orderBy(['e.ls' => SORT_ASC, 'e.dc' => SORT_ASC, 'e.amount' => SORT_DESC])
            ->asArray()
            ->all();

        if (empty($entries)) {
            return ['success' => false, 'message' => 'Записи не найдены'];
        }

        return ['success' => true, 'data' => $entries, 'match_id' => $matchId];
    }

    public function actionGetRules(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $companyId = $this->companyId();
        $rules = MatchingRule::find()
            ->with('pools')
            ->where(['company_id' => $companyId])
            ->orderBy(['priority' => SORT_ASC, 'section' => SORT_ASC])
            ->all();

        $data = array_map(static function (MatchingRule $rule) {
            $poolIds = $rule->getEffectivePoolIds();
            $poolNames = $rule->getPoolNames();

            return [
                'id' => $rule->id,
                'name' => $rule->name,
                'pool_id' => !empty($poolIds) ? (int) $poolIds[0] : null,
                'pool_name' => !empty($poolNames) ? implode(', ', $poolNames) : null,
                'pool_ids' => $poolIds,
                'pool_names' => $poolNames,
                'pool_scope_label' => empty($poolNames) ? 'Все ностро-банки' : implode(', ', $poolNames),
                'section' => $rule->section,
                'pair_type' => $rule->pair_type,
                'pair_type_label' => MatchingRule::pairTypeList()[$rule->pair_type] ?? $rule->pair_type,
                'match_dc' => (bool) $rule->match_dc,
                'match_amount' => (bool) $rule->match_amount,
                'match_value_date' => (bool) $rule->match_value_date,
                'match_instruction_id' => (bool) $rule->match_instruction_id,
                'match_end_to_end_id' => (bool) $rule->match_end_to_end_id,
                'match_transaction_id' => (bool) $rule->match_transaction_id,
                'match_message_id' => (bool) $rule->match_message_id,
                'reference_value' => $rule->reference_value,
                'cross_id_search' => (bool) $rule->cross_id_search,
                'id_prefix_match' => (bool) $rule->id_prefix_match,
                'id_prefix_length' => $rule->id_prefix_length !== null ? (int) $rule->id_prefix_length : null,
                'is_active' => (bool) $rule->is_active,
                'priority' => (int) $rule->priority,
                'description' => $rule->description,
                'conditions_summary' => $rule->getConditionsSummary(),
            ];
        }, $rules);

        return ['success' => true, 'data' => $data];
    }

    public function actionSaveRule(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $companyId = $this->companyId();
        $id = (int) Yii::$app->request->post('id');

        $rule = $id
            ? MatchingRule::findOne(['id' => $id, 'company_id' => $companyId])
            : new MatchingRule();

        if (!$rule) {
            return ['success' => false, 'message' => 'Правило не найдено'];
        }

        $rule->company_id = $companyId;
        $rule->pool_ids = $this->requestPoolIds();
        $rule->name = (string) Yii::$app->request->post('name');
        $rule->section = (string) Yii::$app->request->post('section');
        $rule->pair_type = (string) Yii::$app->request->post('pair_type', MatchingRule::PAIR_LS);
        $rule->match_dc = $this->postBool('match_dc');
        $rule->match_amount = $this->postBool('match_amount');
        $rule->match_value_date = $this->postBool('match_value_date');
        $rule->match_instruction_id = $this->postBool('match_instruction_id');
        $rule->match_end_to_end_id = $this->postBool('match_end_to_end_id');
        $rule->match_transaction_id = $this->postBool('match_transaction_id');
        $rule->match_message_id = $this->postBool('match_message_id');
        $referenceValue = Yii::$app->request->post('reference_value');
        $rule->reference_value = ($referenceValue === null || $referenceValue === '') ? null : (string) $referenceValue;
        $rule->cross_id_search = $this->postBool('cross_id_search');
        $rule->id_prefix_match = $this->postBool('id_prefix_match');
        $prefixLength = Yii::$app->request->post('id_prefix_length');
        $rule->id_prefix_length = ($prefixLength === null || $prefixLength === '') ? null : (int) $prefixLength;
        $rule->is_active = $this->postBool('is_active', true);
        $rule->priority = (int) Yii::$app->request->post('priority', 100);
        $rule->description = (string) Yii::$app->request->post('description', '');

        if ($rule->save()) {
            return [
                'success' => true,
                'message' => $id ? 'Правило обновлено' : 'Правило создано',
                'id' => $rule->id,
            ];
        }

        $errors = $rule->errors;
        if (isset($errors['pool_ids']) && !isset($errors['pool_id'])) {
            $errors['pool_id'] = $errors['pool_ids'];
        }

        return ['success' => false, 'errors' => $errors];
    }

    public function actionDeleteRule(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $companyId = $this->companyId();
        $id = (int) Yii::$app->request->post('id');
        $rule = MatchingRule::findOne(['id' => $id, 'company_id' => $companyId]);

        if (!$rule) {
            return ['success' => false, 'message' => 'Правило не найдено'];
        }

        $rule->delete();
        return ['success' => true, 'message' => 'Правило удалено'];
    }
}
