<?php /** @var yii\web\View $this */ ?>

<div id="sidebar"
     :class="{ 'collapsed': isSidebarCollapsed, 'resizing': isResizingSidebar }"
     :style="sidebarStyle">

    <!-- Тоггл -->
    <div id="sidebar-toggle" @click="toggleSidebar" title="Свернуть/развернуть">
        <i class="fas fa-chevron-left"></i>
    </div>

    <!-- Ресайз-хэндл -->
    <div class="sidebar-resize-handle"
         v-if="!isSidebarCollapsed"
         @mousedown.prevent="startSidebarResize">
    </div>

    <!-- Заголовок (скрывается при collapsed) -->
    <div class="sidebar-head">
        <h6><i class="fas fa-layer-group me-1"></i> Категории</h6>
    </div>

    <!-- Дерево -->
    <div class="sidebar-scroll">

        <div v-if="loadingCategories" style="text-align:center;padding:20px">
            <div class="spinner-border spinner-border-sm" style="color:#6366f1;width:18px;height:18px;border-width:2px"></div>
        </div>

        <div v-else-if="categories.length === 0" style="padding:20px 8px;text-align:center;color:#9ca3af;font-size:12px">
            <i class="fas fa-folder-open" style="font-size:22px;margin-bottom:8px;display:block;opacity:.3"></i>
            Нет категорий
        </div>

        <!-- Категории -->
        <div v-for="category in categories" :key="category.id" class="tree-group">

            <div class="tree-group-row"
                 :class="{
                     active: selectedCategory && selectedCategory.id === category.id,
                     'has-active-pool': selectedPool && category.pools &&
                         category.pools.some(function(p){ return p.id === selectedPool.id; })
                 }"
                 :data-name="category.name"
                 @click="toggleCategory(category)"
                 @mouseenter="onCategoryHover(category, $event)"
                 @mouseleave="onCategoryLeave(category)">

                <!-- Стрелка (скрыта в collapsed) -->
                <span class="tree-chevron" :class="{ open: category.expanded }">
                    <i class="fas fa-chevron-right"></i>
                </span>

                <!-- Иконка (в collapsed — единственное что видно) -->
                <span class="tree-group-icon">
                    <i :class="category.expanded ? 'fas fa-folder-open' : 'fas fa-folder'"></i>
                </span>

                <!-- Название + счётчик (скрыты в collapsed) -->
                <span class="tree-group-name" :title="category.name">{{ category.name }}</span>
                <span class="tree-group-count" v-if="category.pools && category.pools.length">
                    {{ category.pools.length }}
                </span>

                <!-- Кнопки (скрыты в collapsed) -->
                <span class="tree-actions" @click.stop>
                    <button class="tree-btn primary" @click.stop="editCategory(category)" title="Редактировать">
                        <i class="fas fa-pen"></i>
                    </button>
                    <div class="row-actions-dropdown">
                        <button class="tree-btn" @click.stop="toggleRowMenu('cat', category.id, $event)" title="Ещё">
                            <i class="fas fa-ellipsis-v"></i>
                        </button>
                        <div v-if="openRowMenu==='cat-'+category.id" class="row-actions-menu" :style="rowMenuStyle">
                            <button class="row-actions-menu-item danger" @click.stop="deleteCategory(category); openRowMenu=null">
                                <i class="fas fa-trash"></i> Удалить
                            </button>
                        </div>
                    </div>
                </span>
            </div>

            <!-- Flyout-подменю (collapsed mode) -->
            <div v-if="isSidebarCollapsed && flyoutCategory && flyoutCategory.id === category.id"
                 class="sidebar-flyout"
                 :style="flyoutStyle"
                 @mouseenter="onFlyoutEnter"
                 @mouseleave="onFlyoutLeave">

                <div class="flyout-header">{{ category.name }}</div>

                <div v-if="!category.pools || category.pools.length === 0"
                     class="flyout-empty">Нет ностро-банков</div>

                <div v-for="pool in category.pools" :key="pool.id"
                     class="flyout-item"
                     :class="{ active: selectedPool && selectedPool.id === pool.id }"
                     @click.stop="selectPool(pool, category); closeFlyout()">
                    <span class="flyout-dot"></span>
                    <span class="flyout-item-name">{{ pool.name }}</span>
                </div>

                <button class="flyout-add" @click.stop="showAddPoolModal(category); closeFlyout()">
                    <i class="fas fa-plus" style="font-size:9px"></i>
                    Добавить ностро-банк
                </button>
            </div>

            <!-- Ностро-банки — только в развёрнутом виде -->
            <div class="tree-pools" v-show="category.expanded">
                <div v-if="!category.pools || category.pools.length === 0"
                     style="font-size:11px;color:#9ca3af;padding:3px 6px 3px 4px">
                    Нет ностро-банков
                </div>

                <div v-for="pool in category.pools" :key="pool.id"
                     class="tree-pool-row"
                     :class="{ active: selectedPool && selectedPool.id === pool.id }"
                     @click.stop="selectPool(pool, category)">

                    <span class="tree-pool-dot"></span>
                    <span class="tree-pool-name" :title="pool.name">{{ pool.name }}</span>

                    <span class="tree-actions" @click.stop>
                        <button class="tree-btn primary" @click.stop="showMovePoolModal(pool, category)" title="Переместить в другую категорию">
                            <i class="fas fa-arrows-alt"></i>
                        </button>
                        <div class="row-actions-dropdown">
                            <button class="tree-btn" @click.stop="toggleRowMenu('pool', pool.id, $event)" title="Ещё">
                                <i class="fas fa-ellipsis-v"></i>
                            </button>
                            <div v-if="openRowMenu==='pool-'+pool.id" class="row-actions-menu" :style="rowMenuStyle">
                                <button class="row-actions-menu-item" @click.stop="showMovePoolModal(pool, category); openRowMenu=null">
                                    <i class="fas fa-arrows-alt"></i> Переместить
                                </button>
                                <button class="row-actions-menu-item" @click.stop="detachPoolFromCategory(pool, category); openRowMenu=null">
                                    <i class="fas fa-unlink"></i> Открепить от категории
                                </button>
                            </div>
                        </div>
                    </span>
                </div>

                <!-- Добавить ностро-банк -->
                <button class="tree-add-pool" @click.stop="showAddPoolModal(category)">
                    <i class="fas fa-plus" style="font-size:9px"></i>
                    Добавить ностро-банк
                </button>
            </div>

        </div>
        <!-- /v-for category -->

    </div><!-- /sidebar-scroll -->

    <!-- Кнопка «Добавить категорию» -->
    <div class="sidebar-footer">
        <button class="btn-add-group" @click="showAddCategoryModal" title="Добавить категорию">
            <i class="fas fa-plus" style="font-size:11px"></i>
            <span>Добавить категорию</span>
        </button>
    </div>

</div>
