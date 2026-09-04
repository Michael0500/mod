<?php

namespace app\controllers;

use Yii;
use app\models\TdsValidationError;
use app\models\TdsValidationErrorSearch;

/**
 * Просмотр журнала ошибок проверок TDS-выписок.
 */
class TdsValidationErrorController extends BaseController
{
    private function cid(): ?int
    {
        $u = Yii::$app->user->identity;
        return ($u && $u->company_id) ? (int)$u->company_id : null;
    }

    public function actionIndex()
    {
        $cid = $this->cid();
        if (!$cid) {
            Yii::$app->session->setFlash('warning', 'Выберите компанию.');
            return $this->redirect(['/site/index']);
        }

        $searchModel = new TdsValidationErrorSearch();
        $dataProvider = $searchModel->search(Yii::$app->request->queryParams, $cid);

        $this->view->title = 'Ошибки загрузки выписок';

        return $this->render('index', [
            'searchModel' => $searchModel,
            'dataProvider' => $dataProvider,
            'typeOptions' => [
                'CAMT053' => 'CAMT053',
                'MT950' => 'MT950',
            ],
            'errorOptions' => TdsValidationError::errorCodeLabels(),
        ]);
    }
}
