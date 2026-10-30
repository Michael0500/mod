<?php

namespace app\controllers;

use Yii;
use yii\web\Response;
use app\models\Currency;
use app\models\Country;

/**
 * Справочники: валюты и страны.
 *
 * Standalone-страница /references — управление общесистемными справочниками.
 * Данные не привязаны к компании (используются всеми компаниями).
 */
class ReferenceController extends BaseController
{
    /**
     * Отключает CSRF для JSON API справочников.
     *
     * @param \yii\base\Action $action Запускаемое действие.
     * @return bool Можно ли продолжать выполнение action.
     */
    public function beforeAction($action): bool
    {
        $this->enableCsrfValidation = false;
        return parent::beforeAction($action);
    }

    /**
     * Рендерит страницу общесистемных справочников.
     *
     * GET `/references`. Передаёт во Vue списки валют и стран.
     *
     * @return string HTML страницы справочников.
     */
    public function actionIndex()
    {
        $initData = [
            'currencies' => array_map([$this, 'serializeCurrency'], Currency::find()
                ->orderBy(['sort_order' => SORT_ASC, 'code' => SORT_ASC])
                ->all()),
            'countries'  => array_map([$this, 'serializeCountry'], Country::find()
                ->orderBy(['sort_order' => SORT_ASC, 'name' => SORT_ASC])
                ->all()),
        ];

        return $this->render('index', ['initData' => $initData]);
    }

    // ── JSON API: валюты ─────────────────────────────────────────

    /**
     * Возвращает список валют.
     *
     * @return array JSON со справочником валют.
     */
    public function actionCurrencies(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $rows = Currency::find()
            ->orderBy(['sort_order' => SORT_ASC, 'code' => SORT_ASC])
            ->all();
        return [
            'success' => true,
            'data'    => array_map([$this, 'serializeCurrency'], $rows),
        ];
    }

    /**
     * Создаёт валюту в общесистемном справочнике.
     *
     * @return array JSON с созданной валютой или ошибками валидации.
     */
    public function actionCurrencyCreate(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $r = Yii::$app->request;
        $m = new Currency();
        $m->code       = (string)$r->post('code', '');
        $m->name       = trim((string)$r->post('name', ''));
        $m->symbol     = ($s = trim((string)$r->post('symbol', ''))) !== '' ? $s : null;
        $m->is_active  = (string)$r->post('is_active', '1') === '1';
        $m->sort_order = (int)$r->post('sort_order', 0);
        $m->fractional_digit = (int)$r->post('fractional_digit', 2);

        if ($m->save()) {
            return ['success' => true, 'message' => 'Валюта создана', 'data' => $this->serializeCurrency($m)];
        }
        return ['success' => false, 'message' => $this->firstError($m), 'errors' => $m->errors];
    }

    /**
     * Обновляет валюту справочника.
     *
     * @return array JSON с обновлённой валютой или ошибкой.
     */
    public function actionCurrencyUpdate(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $r  = Yii::$app->request;
        $id = (int)$r->post('id');
        $m  = Currency::findOne($id);
        if (!$m) return ['success' => false, 'message' => 'Валюта не найдена'];

        $m->code       = (string)$r->post('code', $m->code);
        $m->name       = trim((string)$r->post('name', $m->name));
        $sym           = trim((string)$r->post('symbol', ''));
        $m->symbol     = $sym !== '' ? $sym : null;
        $m->is_active  = (string)$r->post('is_active', '1') === '1';
        $m->sort_order = (int)$r->post('sort_order', $m->sort_order);
        $m->fractional_digit = (int)$r->post('fractional_digit', $m->fractional_digit);

        if ($m->save()) {
            return ['success' => true, 'message' => 'Валюта обновлена', 'data' => $this->serializeCurrency($m)];
        }
        return ['success' => false, 'message' => $this->firstError($m), 'errors' => $m->errors];
    }

    /**
     * Удаляет валюту из справочника.
     *
     * @return array JSON-результат удаления.
     */
    public function actionCurrencyDelete(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $id = (int)Yii::$app->request->post('id');
        $m  = Currency::findOne($id);
        if (!$m) return ['success' => false, 'message' => 'Валюта не найдена'];

        $code = $m->code;
        if ($m->delete() !== false) {
            return ['success' => true, 'message' => "Валюта «{$code}» удалена"];
        }
        return ['success' => false, 'message' => 'Ошибка удаления'];
    }

    // ── JSON API: страны ─────────────────────────────────────────

    /**
     * Возвращает список стран.
     *
     * @return array JSON со справочником стран.
     */
    public function actionCountries(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $rows = Country::find()
            ->orderBy(['sort_order' => SORT_ASC, 'name' => SORT_ASC])
            ->all();
        return [
            'success' => true,
            'data'    => array_map([$this, 'serializeCountry'], $rows),
        ];
    }

    /**
     * Создаёт страну в общесистемном справочнике.
     *
     * @return array JSON с созданной страной или ошибками валидации.
     */
    public function actionCountryCreate(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $r = Yii::$app->request;
        $m = new Country();
        $m->code       = (string)$r->post('code', '');
        $m->code3      = ($c3 = trim((string)$r->post('code3', ''))) !== '' ? $c3 : null;
        $m->name       = trim((string)$r->post('name', ''));
        $m->is_active  = (string)$r->post('is_active', '1') === '1';
        $m->sort_order = (int)$r->post('sort_order', 0);

        if ($m->save()) {
            return ['success' => true, 'message' => 'Страна создана', 'data' => $this->serializeCountry($m)];
        }
        return ['success' => false, 'message' => $this->firstError($m), 'errors' => $m->errors];
    }

    /**
     * Обновляет страну справочника.
     *
     * @return array JSON с обновлённой страной или ошибкой.
     */
    public function actionCountryUpdate(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $r  = Yii::$app->request;
        $id = (int)$r->post('id');
        $m  = Country::findOne($id);
        if (!$m) return ['success' => false, 'message' => 'Страна не найдена'];

        $m->code       = (string)$r->post('code', $m->code);
        $c3            = trim((string)$r->post('code3', ''));
        $m->code3      = $c3 !== '' ? $c3 : null;
        $m->name       = trim((string)$r->post('name', $m->name));
        $m->is_active  = (string)$r->post('is_active', '1') === '1';
        $m->sort_order = (int)$r->post('sort_order', $m->sort_order);

        if ($m->save()) {
            return ['success' => true, 'message' => 'Страна обновлена', 'data' => $this->serializeCountry($m)];
        }
        return ['success' => false, 'message' => $this->firstError($m), 'errors' => $m->errors];
    }

    /**
     * Удаляет страну из справочника.
     *
     * @return array JSON-результат удаления.
     */
    public function actionCountryDelete(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $id = (int)Yii::$app->request->post('id');
        $m  = Country::findOne($id);
        if (!$m) return ['success' => false, 'message' => 'Страна не найдена'];

        $name = $m->name;
        if ($m->delete() !== false) {
            return ['success' => true, 'message' => "Страна «{$name}» удалена"];
        }
        return ['success' => false, 'message' => 'Ошибка удаления'];
    }

    // ── helpers ─────────────────────────────────────────────────

    /**
     * Сериализует валюту для JSON API.
     *
     * @param Currency $c Модель валюты.
     * @return array JSON-совместимые данные валюты.
     */
    private function serializeCurrency(Currency $c): array
    {
        return [
            'id'         => (int)$c->id,
            'code'       => $c->code,
            'name'       => $c->name,
            'symbol'     => $c->symbol,
            'fractional_digit' => (int)$c->fractional_digit,
            'is_active'  => (bool)$c->is_active,
            'sort_order' => (int)$c->sort_order,
        ];
    }

    /**
     * Сериализует страну для JSON API.
     *
     * @param Country $c Модель страны.
     * @return array JSON-совместимые данные страны.
     */
    private function serializeCountry(Country $c): array
    {
        return [
            'id'         => (int)$c->id,
            'code'       => $c->code,
            'code3'      => $c->code3,
            'name'       => $c->name,
            'is_active'  => (bool)$c->is_active,
            'sort_order' => (int)$c->sort_order,
        ];
    }

    /**
     * Возвращает первую ошибку валидации модели.
     *
     * @param \yii\base\Model $m Модель с ошибками.
     * @return string Текст первой ошибки или общий текст.
     */
    private function firstError(\yii\base\Model $m): string
    {
        foreach ($m->errors as $field => $messages) {
            foreach ($messages as $msg) {
                return $msg;
            }
        }
        return 'Ошибка сохранения';
    }
}
