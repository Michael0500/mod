<?php

namespace app\assets;

use yii\web\AssetBundle;
use yii\web\View;

/**
 * Основной asset bundle веб-приложения.
 *
 * Регистрирует CSS/JS Bootstrap-страниц, Vue 2, axios, datepicker, Select2,
 * SweetAlert2 и стартеры Vue-страниц SmartMatch.
 */
class AppAsset extends AssetBundle
{
    public $basePath = '@webroot';
    public $baseUrl  = '@web';

    public $css = [
        // Select2 — должны быть файлы в web/css/
        'css/select2.min.css',
        'css/select2-bootstrap-5-theme.min.css',
        'css/sweetalert2.min.css',
        'css/flatpickr.min.css',
        'css/app.css?v21',
        'fontawesome/css/all.min.css',
    ];

    public $js = [
        // Порядок важен:
        // 1. jQuery (нужен для Select2)
        'js/jquery.min.js',
        // 2. Bootstrap bundle (нужен для модалок)
        // 3. Select2 (зависит от jQuery)
        'js/select2.min.js',
        // 4. SweetAlert2
        'js/sweetalert2.all.min.js',
        // 5. Vue 2
        'js/vue.min.js',
        // 6. Axios
        'js/axios.min.js',
        // 7. Flatpickr
        'js/flatpickr.min.js',
        'js/flatpickr.ru.js',
        // 8. Общие модули приложения
        'js/app/common.js?v3',
        'js/app/api.js',
        'js/app/state-storage.js?v2',
        'js/app/datepicker.js',
        'js/app/mixins/modals.js?v5',
        'js/app/mixins/categories.js?v3',
        'js/app/mixins/pools.js?v4',
        'js/app/mixins/entries.js?v28',
        'js/app/mixins/matching.js?v17',
        'js/app/mixins/balance.js?v17',
        'js/app/mixins/balance-archive.js?v5',
        'js/app/mixins/archive.js?v13',
        'js/app/mixins/state-persistence.js?v2',
        // 9. Стартеры Vue — каждый активируется по наличию своего корневого элемента
        'js/app/page-entries.js?v2',
        'js/app/page-balance.js?v3',
        'js/app/page-bank-balance.js?v2',
        'js/app/page-archive.js?v4',
        'js/app/page-balance-archive.js?v1',
        'js/app/page-all-nostro.js?v3',
    ];

    // POS_END чтобы скрипты грузились после DOM
    public $jsOptions = ['position' => View::POS_END];

    public $depends = [
        //
    ];
}
