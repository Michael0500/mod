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

            /**
             * Форматирует сохранённую decimal-сумму для редактируемого input.
             *
             * PostgreSQL возвращает decimal(26,5) с пятью знаками после точки,
             * даже если валюта допускает меньше. Метод работает со строкой и не
             * преобразует сумму в Number, поэтому сохраняет точность больших
             * значений. Некорректную строку оставляет без изменений, чтобы
             * валидатор формы мог показать ошибку пользователю.
             *
             * @param {string|number|null|undefined} amount Сумма из API.
             * @param {string} currency Код валюты.
             * @returns {string} Значение с `fractional_digit` знаками после точки.
             */
            formatMoneyInputValue: function (amount, currency) {
                if (amount === null || amount === undefined || amount === '') return '';

                var s = String(amount).trim().replace(/\s/g, '');
                var sign = '';
                if (s.charAt(0) === '-') {
                    sign = '-';
                    s = s.slice(1);
                }
                if (!/^\d+(?:\.\d+)?$/.test(s)) return String(amount).trim();

                var parts = s.split('.');
                var scale = this.currencyFractionalDigit(currency);
                if (scale === 0) return sign + parts[0];

                var fraction = ((parts[1] || '') + '00000').slice(0, scale);
                return sign + parts[0] + '.' + fraction;
            },

            /**
             * Возвращает placeholder денежного поля в точности выбранной валюты.
             *
             * @param {string} currency Код валюты.
             * @returns {string} Например, `0`, `0.00` или `0.000`.
             */
            moneyInputPlaceholder: function (currency) {
                var scale = this.currencyFractionalDigit(currency);
                return scale > 0 ? '0.' + new Array(scale + 1).join('0') : '0';
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
