/**
 * Mixin восстановления состояния сайдбара страницы выверки.
 *
 * Используется только `#entries-app`. Читает и сохраняет ширину сайдбара и
 * флаг сворачивания через `StateStorage`, чтобы настройки были персональными
 * для пользователя и не влияли на бизнес-данные выверки.
 */
var StatePersistenceMixin = {

    /**
     * Восстанавливает состояние сайдбара при создании Vue-инстанса.
     *
     * Побочные эффекты: изменяет `isSidebarCollapsed` и `sidebarWidth` текущего
     * Vue state; ширина применяется только в безопасном диапазоне 180-500px.
     *
     * @returns {void}
     */
    created: function () {
        var collapsed = StateStorage.get('sidebarCollapsed', false);
        this.isSidebarCollapsed = !!collapsed;

        var width = StateStorage.get('sidebarWidth', 240);
        if (typeof width === 'number' && width >= 180 && width <= 500) {
            this.sidebarWidth = width;
        }
    },

    watch: {
        /**
         * Сохраняет признак свёрнутого сайдбара.
         *
         * @param {boolean} val Новое состояние сайдбара.
         * @returns {void}
         */
        isSidebarCollapsed: function (val) {
            StateStorage.set('sidebarCollapsed', val);
        },
        /**
         * Сохраняет пользовательскую ширину сайдбара.
         *
         * @param {number} val Ширина сайдбара в пикселях.
         * @returns {void}
         */
        sidebarWidth: function (val) {
            StateStorage.set('sidebarWidth', val);
        }
    }
};
