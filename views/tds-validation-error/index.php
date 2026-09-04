<?php

use app\models\TdsValidationError;
use yii\grid\GridView;
use yii\helpers\Html;
use yii\widgets\ActiveForm;
use yii\widgets\Pjax;

/** @var yii\web\View $this */
/** @var app\models\TdsValidationErrorSearch $searchModel */
/** @var yii\data\ActiveDataProvider $dataProvider */
/** @var array $typeOptions */
/** @var array $errorOptions */

$this->params['breadcrumbs'][] = $this->title;
?>
<div class="tds-validation-error-index">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
        <div>
            <h1 class="h3 mb-1"><?= Html::encode($this->title) ?></h1>
            <div class="text-muted">Журнал причин, по которым TDS-выписки не были загружены в баланс и выверку.</div>
        </div>
    </div>

    <div class="card shadow-sm border-0 mb-3">
        <div class="card-body">
            <?php $form = ActiveForm::begin([
                'method' => 'get',
                'action' => ['index'],
                'options' => ['class' => 'row g-3 align-items-end'],
            ]); ?>
            <div class="col-md-2">
                <?= $form->field($searchModel, 'statement_type')->dropDownList($typeOptions, ['prompt' => 'Все']) ?>
            </div>
            <div class="col-md-3">
                <?= $form->field($searchModel, 'error_code')->dropDownList($errorOptions, ['prompt' => 'Все']) ?>
            </div>
            <div class="col-md-2">
                <?= $form->field($searchModel, 'account_no') ?>
            </div>
            <div class="col-md-2">
                <?= $form->field($searchModel, 'statement_number') ?>
            </div>
            <div class="col-md-1">
                <?= $form->field($searchModel, 'date_from')->input('date') ?>
            </div>
            <div class="col-md-1">
                <?= $form->field($searchModel, 'date_to')->input('date') ?>
            </div>
            <div class="col-md-1">
                <?= Html::submitButton('Найти', ['class' => 'btn btn-primary w-100']) ?>
            </div>
            <div class="col-md-1">
                <?= Html::a('Сбросить', ['index'], ['class' => 'btn btn-outline-secondary w-100']) ?>
            </div>
            <?php ActiveForm::end(); ?>
        </div>
    </div>

    <?php Pjax::begin(); ?>
    <?= GridView::widget([
        'dataProvider' => $dataProvider,
        'filterModel' => null,
        'tableOptions' => ['class' => 'table table-striped table-bordered align-middle'],
        'columns' => [
            [
                'attribute' => 'created_at',
                'label' => 'Дата и время лога',
                'format' => 'datetime',
                'contentOptions' => ['style' => 'white-space:nowrap;'],
            ],
            [
                'attribute' => 'statement_type',
                'label' => 'Тип',
                'contentOptions' => ['style' => 'white-space:nowrap;'],
            ],
            [
                'attribute' => 'error_code',
                'label' => 'Признак ошибки',
                'value' => static function (TdsValidationError $model): string {
                    return $model->getErrorLabel();
                },
                'contentOptions' => ['style' => 'min-width:260px;'],
            ],
            [
                'attribute' => 'statement_date',
                'label' => 'Дата выписки',
                'contentOptions' => ['style' => 'white-space:nowrap;'],
            ],
            [
                'attribute' => 'statement_time',
                'label' => 'Время выписки',
                'contentOptions' => ['style' => 'white-space:nowrap;'],
            ],
            [
                'attribute' => 'account_no',
                'label' => 'Счет выписки',
                'contentOptions' => ['style' => 'white-space:nowrap;'],
            ],
            [
                'attribute' => 'statement_number',
                'label' => 'Номер выписки',
                'value' => static function (TdsValidationError $model): string {
                    return (string)($model->statement_number ?: '—');
                },
                'contentOptions' => ['style' => 'white-space:nowrap;'],
            ],
            [
                'attribute' => 'error_message',
                'label' => 'Причина ошибки',
                'format' => 'ntext',
                'contentOptions' => ['style' => 'min-width:460px;'],
            ],
            [
                'attribute' => 'batch_id',
                'label' => 'Пачка',
                'contentOptions' => ['style' => 'white-space:nowrap;'],
            ],
        ],
        'emptyText' => 'Ошибок загрузки выписок не найдено.',
        'summary' => 'Показаны записи {begin}–{end} из {totalCount}',
    ]); ?>
    <?php Pjax::end(); ?>
</div>
