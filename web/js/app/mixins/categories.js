/**
 * Mixin управления категориями страницы выверки.
 *
 * Категории являются верхним уровнем навигации сайдбара и группируют
 * ностро-банки (`AccountPool`) через `pool.category_id`. Mixin загружает дерево
 * категорий, восстанавливает раскрытые блоки сайдбара и выполняет CRUD
 * через API, где данные ограничиваются `company_id`.
 */
var CategoriesMixin = {
    methods: {

        /**
         * Загружает дерево категорий и ностро-банков для сайдбара.
         *
         * Читает `categoriesExpanded` из `StateStorage` и сортирует
         * ностро-банки по русской локали. Выбранный ностро-банк не
         * восстанавливается, чтобы фильтр страницы сбрасывался при переходах.
         *
         * @returns {void}
         */
        loadCategories: function () {
            var self = this;
            self.loadingCategories = true;
            SmartMatchApi.categories.list()
                .then(function (response) {
                    if (response.data.success) {
                        var savedExpanded   = StateStorage.get('categoriesExpanded', {});
                        StateStorage.remove('selectedCategoryId');
                        StateStorage.remove('selectedPoolId');

                        self.categories = response.data.data.map(function (category) {
                            var isExpanded = !!savedExpanded[category.id];
                            var sortedPools = (category.pools || []).slice().sort(function (a, b) {
                                return (a.name || '').localeCompare(b.name || '', 'ru', { sensitivity: 'base' });
                            });
                            return Object.assign({}, category, { pools: sortedPools, expanded: isExpanded });
                        });

                    } else {
                        Swal.fire('Ошибка', response.data.message || 'Не удалось загрузить категории', 'error');
                    }
                })
                .catch(function () {
                    Swal.fire('Ошибка', 'Не удалось загрузить категории', 'error');
                })
                .finally(function () {
                    self.loadingCategories = false;
                });
        },

        /**
         * Выбирает или сворачивает категорию в сайдбаре.
         *
         * Изменяет `selectedCategory`, при смене категории сбрасывает
         * `selectedPool` и сохраняет только раскрытые категории. Выбранная
         * категория не сохраняется между страницами, чтобы не восстанавливать
         * фильтр после возврата.
         *
         * @param {Object} category Категория из `categories`.
         * @returns {void}
         */
        toggleCategory: function (category) {
            var self = this;
            var index = self.categories.findIndex(function (c) { return c.id === category.id; });
            if (self.selectedCategory && self.selectedCategory.id === category.id) {
                if (index !== -1) self.categories[index].expanded = !self.categories[index].expanded;
            } else {
                self.selectedCategory = category;
                self.selectedPool = null;
                if (index !== -1) self.categories[index].expanded = true;
            }

            var expandedMap = {};
            self.categories.forEach(function (c) { expandedMap[c.id] = !!c.expanded; });
            StateStorage.set('categoriesExpanded', expandedMap);
            StateStorage.remove('selectedCategoryId');
            StateStorage.remove('selectedPoolId');
        },

        /**
         * Открывает модалку создания категории.
         *
         * Сбрасывает `newCategory` и показывает Bootstrap modal
         * `addCategoryModal`.
         *
         * @returns {void}
         */
        showAddCategoryModal: function () {
            this.newCategory = { name: '', description: '' };
            this._showModal('addCategoryModal');
        },

        /**
         * Создаёт категорию из формы сайдбара.
         *
         * Валидирует обязательное имя, вызывает POST `categoryCreate`, закрывает
         * модалку и перезагружает дерево категорий при успешном ответе API.
         *
         * @returns {void}
         */
        createCategory: function () {
            var self = this;
            if (!self.newCategory.name || !self.newCategory.name.trim()) {
                Swal.fire('Ошибка', 'Название категории обязательно', 'error');
                return;
            }
            SmartMatchApi.categories.create(self.newCategory)
                .then(function (response) {
                    if (response.data.success) {
                        Swal.fire('Успех', response.data.message, 'success');
                        self._forceCloseAddCategoryModal();
                        self.loadCategories();
                    } else {
                        Swal.fire('Ошибка', response.data.message || 'Не удалось создать категорию', 'error');
                    }
                })
                .catch(function () { Swal.fire('Ошибка', 'Не удалось создать категорию', 'error'); });
        },

        /**
         * Открывает модалку редактирования категории.
         *
         * Копирует данные строки в `editingCategory`, чтобы форма не меняла
         * список до успешного сохранения.
         *
         * @param {Object} category Категория из текущего дерева.
         * @returns {void}
         */
        editCategory: function (category) {
            this.editingCategory = { id: category.id, name: category.name, description: category.description || '' };
            this._showModal('editCategoryModal');
        },

        /**
         * Сохраняет изменения категории.
         *
         * Валидирует имя, вызывает POST `categoryUpdate`, закрывает модалку и
         * перезагружает дерево категорий. При ошибке API показывает SweetAlert.
         *
         * @returns {void}
         */
        updateCategory: function () {
            var self = this;
            if (!self.editingCategory.name || !self.editingCategory.name.trim()) {
                Swal.fire('Ошибка', 'Название категории обязательно', 'error');
                return;
            }
            SmartMatchApi.categories.update(self.editingCategory)
                .then(function (response) {
                    if (response.data.success) {
                        Swal.fire('Успех', response.data.message, 'success');
                        self._forceCloseEditCategoryModal();
                        self.loadCategories();
                    } else {
                        Swal.fire('Ошибка', response.data.message || 'Не удалось обновить категорию', 'error');
                    }
                })
                .catch(function () { Swal.fire('Ошибка', 'Не удалось обновить категорию', 'error'); });
        },

        /**
         * Удаляет категорию после подтверждения пользователя.
         *
         * Сервер отвязывает ностро-банки от категории, но не удаляет сами банки.
         * При удалении выбранной категории локально сбрасывает выбранный контекст
         * выверки.
         *
         * @param {Object} category Категория, которую нужно удалить.
         * @returns {void}
         */
        deleteCategory: function (category) {
            var self = this;
            Swal.fire({
                title: 'Вы уверены?',
                text: 'Удалить категорию "' + category.name + '"? Привязанные ностро-банки останутся, но категория у них очистится.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Да, удалить',
                cancelButtonText: 'Отмена'
            }).then(function (result) {
                if (!result.isConfirmed) return;
                SmartMatchApi.categories.delete(category.id)
                    .then(function (response) {
                        if (response.data.success) {
                            Swal.fire('Удалено', response.data.message, 'success');
                            self.loadCategories();
                            if (self.selectedCategory && self.selectedCategory.id === category.id) {
                                self.selectedCategory = null;
                                self.selectedPool     = null;
                            }
                        } else {
                            Swal.fire('Ошибка', response.data.message, 'error');
                        }
                    })
                    .catch(function () { Swal.fire('Ошибка', 'Не удалось удалить категорию', 'error'); });
            });
        }
    }
};
