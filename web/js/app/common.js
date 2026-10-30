/**
 * Глобальные Vue-хелперы, доступные во всех Vue-инстансах проекта.
 *
 * Модуль регистрирует `Vue.mixin()` с общими справочниками и форматтерами,
 * которые используются на страницах выверки, балансов и архива. Справочники
 * приходят из серверного контекста страницы и уже ограничены доступами текущей
 * компании пользователя.
 */
(function () {
    if (!window.Vue) return;

    Vue.mixin({
        computed: {
            /**
             * Возвращает валюты из глобального серверного словаря.
             *
             * @returns {Array<Object>} Список валют для форм и фильтров.
             */
            dictCurrencies: function () {
                return (window.AppDictionaries && window.AppDictionaries.currencies) || [];
            },
            /**
             * Возвращает страны из глобального серверного словаря.
             *
             * @returns {Array<Object>} Список стран для форм и фильтров.
             */
            dictCountries: function () {
                return (window.AppDictionaries && window.AppDictionaries.countries) || [];
            },
            /**
             * Формирует список кодов валют для простых выпадающих списков.
             *
             * @returns {string[]} Коды валют, например `USD` или `EUR`.
             */
            dictCurrencyCodes: function () {
                return ((window.AppDictionaries && window.AppDictionaries.currencies) || []).map(function (c) {
                    return c.code;
                });
            },
        },
        methods: {
            /**
             * Подбирает русскую форму слова "запись" для счётчиков таблиц.
             *
             * @param {number} count Количество записей.
             * @returns {string} Подходящая форма: `запись`, `записи` или `записей`.
             */
            recordText: function (count) {
                var n  = Math.abs(count) % 100;
                var n1 = n % 10;
                if (n > 10 && n < 20) return 'записей';
                if (n1 > 1 && n1 < 5)  return 'записи';
                if (n1 === 1)          return 'запись';
                return 'записей';
            },

            /**
             * Форматирует денежное значение без изменения исходной точности.
             *
             * Используется для сумм NostroEntry и архивных строк. Пустые или
             * некорректные значения отображаются как прочерк, чтобы UI не
             * интерпретировал их как нулевую сумму.
             *
             * @param {string|number|null|undefined} val Значение суммы из API или формы.
             * @returns {string} Сумма в формате `1,234.00` либо `—`.
             */
            currencyFractionalDigit: function (currency) {
                var code = String(currency || '').toUpperCase();
                var currencies = (window.AppDictionaries && window.AppDictionaries.currencies) || [];
                for (var i = 0; i < currencies.length; i++) {
                    if (String(currencies[i].code || '').toUpperCase() === code) {
                        var digit = parseInt(currencies[i].fractional_digit, 10);
                        return digit >= 0 && digit <= 5 ? digit : 2;
                    }
                }
                return 2;
            },

            formatAmount: function (val, currency) {
                if (val === null || val === undefined || val === '') return '—';
                var s = String(val).trim();
                var sign = '';
                if (s.charAt(0) === '-') {
                    sign = '-';
                    s = s.slice(1);
                }
                s = s.replace(/\s/g, '').replace(/,/g, '');
                if (!/^\d+(\.\d+)?$/.test(s)) return '—';
                var parts = s.split('.');
                var intPart = parts[0].replace(/^0+(?=\d)/, '').replace(/\B(?=(\d{3})+(?!\d))/g, ',');
                var scale = this.currencyFractionalDigit(currency);
                var decPart = ((parts[1] || '') + '00000').slice(0, scale);
                return sign + intPart + '.' + decPart;
            }
        }
    });
})();
