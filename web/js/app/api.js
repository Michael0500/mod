/**
 * Тонкий API-слой SmartMatch поверх axios.
 *
 * Все URL берутся из глобального объекта `window.AppRoutes`, который Yii
 * инициализирует в `_vue-scripts.php`. Обёртка фиксирует endpoint, HTTP-метод
 * и форму payload, но не дублирует серверные проверки: `company_id`, права,
 * денежная точность, статусы квитования и аудит контролируются backend-кодом.
 *
 * @type {{
 *   get: function(string, Object=): Promise,
 *   post: function(string, Object=): Promise<Object>,
 *   categories: Object,
 *   entries: Object
 * }}
 */
var SmartMatchApi = (function () {

    /**
     * Выполняет JSON POST-запрос через axios.
     *
     * @param {string} url Endpoint из `AppRoutes`.
     * @param {Object=} data Тело запроса.
     * @returns {Promise} Promise с полным axios response.
     */
    function post(url, data) {
        return axios.post(url, data);
    }

    /**
     * Выполняет GET-запрос с query-параметрами.
     *
     * @param {string} url Endpoint из `AppRoutes`.
     * @param {Object=} params Query-параметры запроса.
     * @returns {Promise} Promise с полным axios response.
     */
    function get(url, params) {
        return axios.get(url, { params: params || {} });
    }


    return {
        /**
         * Низкоуровневый GET для модулей, которым нужен полный axios response.
         *
         * @param {string} url Endpoint из `AppRoutes`.
         * @param {Object=} params Query-параметры запроса.
         * @returns {Promise} Promise с данными в `response.data`.
         */
        get:     get,
        /**
         * Низкоуровневый POST, возвращающий уже распакованное тело ответа API.
         *
         * @param {string} url Endpoint из `AppRoutes`.
         * @param {Object=} data Тело запроса.
         * @returns {Promise<Object>} JSON-ответ контроллера Yii.
         */
        post:    function (url, data) {
            return post(url, data).then(function (r) { return r.data; });
        },

        /**
         * API категорий сайдбара выверки.
         *
         * Операции работают с категориями текущей компании пользователя.
         *
         * @type {{
         *   list: function(): Promise,
         *   create: function(Object): Promise,
         *   update: function(Object): Promise,
         *   delete: function(number|string): Promise
         * }}
         */
        categories: {
            /**
             * Загружает дерево категорий и связанных ностро-банков.
             *
             * @returns {Promise} GET `AppRoutes.categoryGetCategories`.
             */
            list:   function ()       { return get(AppRoutes.categoryGetCategories); },
            /**
             * Создаёт категорию.
             *
             * @param {{name: string, description: string}} data Данные формы категории.
             * @returns {Promise} POST `AppRoutes.categoryCreate`.
             */
            create: function (data)   { return post(AppRoutes.categoryCreate, data); },
            /**
             * Обновляет категорию.
             *
             * @param {{id: number|string, name: string, description: string}} data Данные редактирования.
             * @returns {Promise} POST `AppRoutes.categoryUpdate`.
             */
            update: function (data)   { return post(AppRoutes.categoryUpdate, data); },
            /**
             * Удаляет категорию.
             *
             * @param {number|string} id Идентификатор категории.
             * @returns {Promise} POST `AppRoutes.categoryDelete`.
             */
            delete: function (id)     { return post(AppRoutes.categoryDelete, { id: id }); }
        },

        /**
         * API записей выверки NostroEntry.
         *
         * Сервер валидирует принадлежность счёта компании, decimal(26,5),
         * доступность операций для статусов квитования и аудит изменений.
         *
         * @type {{
         *   create: function(Object): Promise,
         *   update: function(Object): Promise,
         *   delete: function(number|string): Promise,
         *   updateComment: function(number|string, string): Promise
         * }}
         */
        entries: {
            /**
             * Создаёт новую запись выверки.
             *
             * @param {Object} data Форма NostroEntry: счёт, L/S, D/C, сумма, валюта, даты и ID-поля.
             * @returns {Promise} POST `AppRoutes.entryCreate`.
             */
            create:        function (data)         { return post(AppRoutes.entryCreate, data); },
            /**
             * Обновляет существующую запись выверки.
             *
             * @param {Object} data Форма NostroEntry с обязательным `id`.
             * @returns {Promise} POST `AppRoutes.entryUpdate`.
             */
            update:        function (data)         { return post(AppRoutes.entryUpdate, data); },
            /**
             * Удаляет запись выверки.
             *
             * @param {number|string} id Идентификатор NostroEntry.
             * @returns {Promise} POST `AppRoutes.entryDelete`.
             */
            delete:        function (id)           { return post(AppRoutes.entryDelete, { id: id }); },
            /**
             * Сохраняет inline-комментарий записи.
             *
             * @param {number|string} id Идентификатор NostroEntry.
             * @param {string} comment Новый комментарий.
             * @returns {Promise} POST `AppRoutes.entryUpdateComment`.
             */
            updateComment: function (id, comment)  {
                return post(AppRoutes.entryUpdateComment, { id: id, comment: comment });
            }
        }
    };
})();
