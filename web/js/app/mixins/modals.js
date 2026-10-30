/**
 * Общие модальные окна и форматтеры истории.
 *
 * Mixin инкапсулирует открытие/закрытие Bootstrap 5 modal, подтверждение
 * отмены форм и отображение audit trail для активных и архивных записей.
 * Методы не обращаются к API напрямую, но меняют Vue state модалок и зависят
 * от формата истории, который возвращают `entryHistory` и `archiveHistory`.
 */
var ModalsMixin = {
    methods: {
        /**
         * Показывает Bootstrap modal по DOM id.
         *
         * @param {string} id DOM id модального окна.
         * @returns {void}
         */
        _showModal: function (id) {
            var el = document.getElementById(id);
            if (el) {
                var existing = bootstrap.Modal.getInstance(el);
                if (existing) {
                    existing.show();
                } else {
                    new bootstrap.Modal(el).show();
                }
            }
        },
        /**
         * Скрывает существующий экземпляр Bootstrap modal по DOM id.
         *
         * @param {string} id DOM id модального окна.
         * @returns {void}
         */
        _hideModal: function (id) {
            var el = document.getElementById(id);
            if (el) {
                var m = bootstrap.Modal.getInstance(el);
                if (m) m.hide();
            }
        },

        /**
         * Закрывает модалку создания категории без подтверждения.
         *
         * Используется после успешного сохранения, когда потеря введённых
         * данных уже неактуальна.
         *
         * @returns {void}
         */
        _forceCloseAddCategoryModal:  function () { this._hideModal('addCategoryModal'); },
        /**
         * Закрывает модалку редактирования категории без подтверждения.
         *
         * @returns {void}
         */
        _forceCloseEditCategoryModal: function () { this._hideModal('editCategoryModal'); },

        /**
         * Запрашивает подтверждение отмены создания категории.
         *
         * Побочный эффект: показывает SweetAlert и при подтверждении закрывает
         * `addCategoryModal`.
         *
         * @returns {void}
         */
        closeAddCategoryModal: function () {
            var self = this;
            Swal.fire({
                title: 'Отменить изменения?',
                text: 'Введённые данные будут потеряны.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Да, отменить',
                cancelButtonText: 'Нет, продолжить',
                confirmButtonColor: '#d33',
                cancelButtonColor: '#6c757d',
                reverseButtons: true
            }).then(function (result) {
                if (result.isConfirmed) self._forceCloseAddCategoryModal();
            });
        },
        /**
         * Запрашивает подтверждение отмены редактирования категории.
         *
         * @returns {void}
         */
        closeEditCategoryModal: function () {
            var self = this;
            Swal.fire({
                title: 'Отменить изменения?',
                text: 'Введённые данные будут потеряны.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Да, отменить',
                cancelButtonText: 'Нет, продолжить',
                confirmButtonColor: '#d33',
                cancelButtonColor: '#6c757d',
                reverseButtons: true
            }).then(function (result) {
                if (result.isConfirmed) self._forceCloseEditCategoryModal();
            });
        },

        /**
         * Закрывает модалку истории записи и очищает её состояние.
         *
         * @param {Event=} e Событие клика, если закрытие инициировано из DOM.
         * @returns {void}
         */
        closeEntryHistoryModal: function (e) {
            if (e && e.stopPropagation) e.stopPropagation();
            this._hideModal('entryHistoryModal');
            this.historyEntry = null;
            this.historyItems = [];
        },

        /**
         * Возвращает CSS-класс иконки для audit action.
         *
         * @param {string} action Код действия аудита: create, update, delete, archive, restore.
         * @returns {string} CSS-класс Font Awesome.
         */
        getHistoryIcon: function (action) {
            var icons = {
                'create':  'fas fa-plus-circle',
                'update':  'fas fa-edit',
                'delete':  'fas fa-trash-alt',
                'archive': 'fas fa-archive',
                'restore': 'fas fa-undo'
            };
            return icons[action] || 'fas fa-clock';
        },

        /**
         * Возвращает русскую метку действия истории.
         *
         * @param {string} action Код действия аудита.
         * @returns {string} Человекочитаемая метка действия.
         */
        getHistoryActionLabel: function (action) {
            var labels = {
                'create':  'Создано',
                'update':  'Изменено',
                'delete':  'Удалено',
                'archive': 'Заархивировано',
                'restore': 'Восстановлено'
            };
            return labels[action] || action;
        },

        /**
         * Форматирует дату/время события аудита.
         *
         * @param {string|null|undefined} dateStr Дата из API.
         * @returns {string} Дата в формате `DD.MM.YYYY HH:mm` либо `—`.
         */
        formatDate: function (dateStr) {
            if (!dateStr) return '—';
            var d = new Date(dateStr);
            var day = String(d.getDate()).padStart(2, '0');
            var month = String(d.getMonth() + 1).padStart(2, '0');
            var year = d.getFullYear();
            var hours = String(d.getHours()).padStart(2, '0');
            var mins = String(d.getMinutes()).padStart(2, '0');
            return day + '.' + month + '.' + year + ' ' + hours + ':' + mins;
        },

        /**
         * Возвращает русское название поля NostroEntry для истории.
         *
         * @param {string} field Имя поля из snapshot/changes.
         * @returns {string} Метка поля для таблицы истории.
         */
        getFieldLabel: function (field) {
            var labels = {
                'account_id':     'Счёт',
                'match_id':       'Match ID',
                'ls':             'L/S',
                'dc':             'D/C',
                'amount':         'Сумма',
                'currency':       'Валюта',
                'value_date':     'Дата валютирования',
                'post_date':      'Дата проводки',
                'instruction_id': 'Instruction ID',
                'end_to_end_id':  'EndToEnd ID',
                'transaction_id': 'Transaction ID',
                'message_id':     'Message ID',
                'comment':        'Комментарий',
                'match_status':   'Статус',
                'matched_at':     'Дата квитования',
                'source':         'Источник'
            };
            return labels[field] || field;
        },

        /**
         * Форматирует snapshot или набор changed values для отображения в истории.
         *
         * @param {Object|null|undefined} valuesObj Объект значений записи аудита.
         * @param {string=} changedField Поле изменения, зарезервировано для совместимости шаблона.
         * @returns {string} Многострочное описание значений записи.
         */
        formatValue: function (valuesObj, changedField) {
            if (!valuesObj) return '—';
            var lines = [];
            var self = this;
            Object.keys(valuesObj).forEach(function (key) {
                var val = valuesObj[key];
                var label = self.getFieldLabel(key);
                var formatted = val;
                if (key === 'amount') {
                    formatted = parseFloat(val).toLocaleString('en-US', {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2
                    }) + ' ' + (valuesObj.currency || '');
                } else if (key === 'dc') {
                    formatted = val === 'Debit' ? 'Debit (D)' : 'Credit (C)';
                } else if (key === 'ls') {
                    formatted = val === 'L' ? 'Ledger (L)' : 'Statement (S)';
                } else if (key === 'match_status') {
                    var statusLabels = { 'U': 'Unmatched', 'M': 'Matched', 'I': 'Ignored', 'A': 'Archived' };
                    formatted = statusLabels[val] || val;
                } else if (key === 'matched_at') {
                    formatted = self.formatDate(val);
                }
                lines.push(label + ': ' + (formatted === null || formatted === '' ? '—' : formatted));
            });
            return lines.join('\n');
        },
        /**
         * Возвращает значение поля из snapshot записи аудита.
         *
         * @param {Object} item Элемент audit trail.
         * @param {string} field Имя поля.
         * @returns {*} Значение поля или `null`.
         */
        getSnapVal: function (item, field) {
            if (!item || !item.snapshot) return null;
            var v = item.snapshot[field];
            return (v === undefined || v === null) ? null : v;
        },

        /**
         * Возвращает старое значение поля из блока changes.
         *
         * @param {Object} item Элемент audit trail.
         * @param {string} field Имя поля.
         * @returns {*} Старое значение или `null`.
         */
        getOldVal: function (item, field) {
            if (!item || !item.changes || !item.changes[field]) return null;
            if (field === 'account_id') {
                var accountName = item.changes[field]['old_name'];
                if (accountName !== undefined && accountName !== null) return accountName;
            }
            var v = item.changes[field]['old'];
            return (v === undefined || v === null) ? null : v;
        },

        /**
         * Проверяет, изменялось ли поле в конкретном событии аудита.
         *
         * @param {Object} item Элемент audit trail.
         * @param {string} field Имя поля.
         * @returns {boolean} `true`, если поле входит в `changed_fields`.
         */
        isChanged: function (item, field) {
            if (!item) return false;
            if (!item.changed_fields || !item.changed_fields.length) return false;
            return item.changed_fields.indexOf(field) !== -1;
        },

        /**
         * Возвращает CSS-класс ячейки истории с подсветкой изменений.
         *
         * @param {Object} item Элемент audit trail.
         * @param {string} field Имя поля.
         * @returns {string} CSS-класс для ячейки истории.
         */
        histCellClass: function (item, field) {
            var base = 'hist-td';
            if (this.isChanged(item, field)) {
                return base + ' hist-td-changed';
            }
            if (item && item.action === 'create') {
                var snap = item.snapshot || {};
                if (snap[field] !== undefined && snap[field] !== null && snap[field] !== '') {
                    return base + ' hist-td-created';
                }
            }
            return base;
        },

        /**
         * Возвращает короткую русскую метку статуса квитования.
         *
         * @param {string|null|undefined} code Код статуса `U`, `M`, `I` или `A`.
         * @returns {string} Метка статуса для истории.
         */
        histStatusLabel: function (code) {
            if (!code) return '—';
            var map = { 'U': 'Ожидает', 'M': 'Сквит.', 'I': 'Игнор', 'A': 'Архив' };
            return map[code] || code;
        },
    }
};
