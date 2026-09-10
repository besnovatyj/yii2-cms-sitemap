<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\Sitemap\controllers\frontend;

use Besnovatyj\Contracts\theme\ViewVariantCatalog;
use Besnovatyj\Contracts\theme\ViewVariantsManifest;
use Besnovatyj\Sitemap\services\BuildGuard;
use Besnovatyj\Sitemap\settings\SitemapSettings;
use Besnovatyj\Sitemap\storage\SitemapStorage;
use Yii;
use yii\web\Controller;
use yii\web\NotFoundHttpException;

/**
 * Человеческая карта сайта — страница `/sitemap`.
 *
 * Читает готовое дерево из артефакта сборки: ни одного запроса к базе, ни одного обращения к
 * контентным модулям. Прежний модуль собирал эту страницу отдельным кодом и кэшировал её на одну
 * секунду — то есть фактически строил заново на каждый заход.
 *
 * Страница индексируется как обычная (в отличие от выдачи поиска): для посетителя это оглавление
 * сайта, и запрещать её роботам смысла нет.
 */
class MapController extends Controller
{
    /** Точка выбора варианта представления в активной теме. */
    public const string VIEW_SLOT = 'Sitemap:frontend/map/index';

    public function __construct(
        $id,
        $module,
        private readonly BuildGuard $guard,
        private readonly SitemapStorage $storage,
        private readonly SitemapSettings $settings,
        $config = [],
    ) {
        parent::__construct($id, $module, $config);
    }

    /**
     * @throws NotFoundHttpException страница выключена в настройках
     */
    public function actionIndex(): string
    {
        if (!$this->settings->htmlEnabled) {
            // Не «пустая страница», а 404: выключенная карта не должна существовать как адрес.
            throw new NotFoundHttpException('Карта сайта отключена.');
        }

        $this->guard->ensure();

        $this->view->title = 'Карта сайта';
        $this->view->params['breadcrumbs'][] = $this->view->title;

        return $this->render($this->resolveView(), [
            'sections' => $this->storage->htmlMap(),
            'builtAt' => $this->storage->manifest()?->builtAt,
        ]);
    }

    /**
     * Имя представления с учётом выбранного в настройках варианта темы.
     *
     * Базовое `index` либо `index.variants/{ключ}` — но только если активная тема действительно
     * предлагает этот вариант для слота {@see VIEW_SLOT} (сохранённый ключ мог протухнуть после
     * смены темы). Каталог резолвится защитно: без пакета тем биндинг отсутствует — рендерим
     * базовое представление.
     */
    private function resolveView(): string
    {
        $variant = $this->settings->htmlVariant;

        if ($variant === '' || !Yii::$container->has(ViewVariantCatalog::class)) {
            return 'index';
        }

        $catalog = Yii::$container->get(ViewVariantCatalog::class);

        return $catalog->hasVariant(self::VIEW_SLOT, $variant)
            ? 'index' . ViewVariantsManifest::VARIANTS_DIR_SUFFIX . '/' . $variant
            : 'index';
    }
}
