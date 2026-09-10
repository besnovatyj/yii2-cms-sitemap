<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

use Besnovatyj\Sitemap\Module;
use Besnovatyj\Sitemap\services\BuildGuard;
use Besnovatyj\Sitemap\services\SectionRegistry;
use Besnovatyj\Sitemap\services\SitemapBuilder;
use Besnovatyj\Sitemap\services\UrlResolver;
use Besnovatyj\Sitemap\settings\SitemapSettings;
use Besnovatyj\Sitemap\settings\SitemapSettingsFactory;
use Besnovatyj\Sitemap\storage\SitemapStorage;
use yii\di\Container;
use yii\web\UrlManager;

/**
 * Yii2-конфиг модуля для движка yiisoft/config (группа `common` — общий для всех приложений).
 *
 * Объявляется через `extra.config-plugin`, собирается modman в merge-plan и мёржится в рантайме.
 * Это единственный composition root пакета: регистрация модуля, публичные адреса карты и DI-проводка.
 * Файла `config/container.php` у модуля намеренно нет — он выполняется только при инициализации
 * модуля, а сборку карты запускают и консоль, и админка, и фронт, поэтому проводка обязана
 * существовать независимо от того, зашёл ли запрос в модуль.
 *
 * Группа `common`, а не `app-frontend`: компонент `frontendUrlManager` определён в обоих
 * приложениях (админка строит фронтовые ссылки), а сборка карты из консоли обязана уметь строить
 * абсолютные URL — там своего `request` нет вовсе, и адреса берутся из `hostInfo` этого компонента.
 *
 * Правила встают перед catch-all ядра и снимаются вместе с модулем, если тот деактивирован в modman.
 * Публичные адреса (левая часть) остаются строчными, роут капитализирован под реальный id модуля.
 */
return [
    'modules' => [
        Module::moduleId() => array_merge(
            ['class' => Module::class],
            Module::moduleConfig(),
            ['version' => Module::moduleVersion()],
        ),
    ],
    'components' => [
        'frontendUrlManager' => [
            'rules' => [
                // `/sitemap.xml` — индекс карты; `/sitemap-blog-post-1.xml` — файл раздела.
                // Суффикс задан правилу, а не менеджеру: остальные адреса сайта остаются без него.
                ['pattern' => 'sitemap', 'route' => 'Sitemap/xml/index', 'suffix' => '.xml'],
                ['pattern' => 'sitemap-<name:[a-z0-9-]+>', 'route' => 'Sitemap/xml/section', 'suffix' => '.xml'],

                // `/sitemap` — человеческая карта. С правилами выше не конфликтует: там другой
                // pathInfo (`sitemap.xml`), и суффиксные правила проверяются раньше.
                //
                // Правил два, и первое существует ради каталога целей меню. Модуль меню строит
                // адрес единообразно — `createUrl([$route, $slugParam => $slug])` (см.
                // `MenuTargetRegistry::buildUrl()`), — а у карты сайта параметров нет вовсе.
                // Правило с `defaults` этот параметр поглощает: значение, совпадающее с умолчанием,
                // Yii в query-строку не выносит, и в меню попадает чистый `/sitemap` вместо
                // `/sitemap?slug=sitemap`. Второе правило обслуживает обычные ссылки — без `slug`
                // первое возвращает false и уступает ему.
                [
                    'pattern' => 'sitemap',
                    'route' => 'Sitemap/map/index',
                    'defaults' => ['slug' => Module::MENU_SLUG],
                ],
                'sitemap' => 'Sitemap/map/index',
            ],
        ],
    ],
    'container' => [
        'singletons' => [
            /**
             * Настройки собираются один раз за запрос из `params` модуля.
             *
             * Замыкание — единственное место пакета, которое знает о `Yii::$app`: модуль настроек
             * `yii2-cms-config` применяет сохранённые значения прямо к объекту модуля, поэтому
             * читать их можно только после подъёма приложения. Ленивость это и обеспечивает.
             */
            SitemapSettings::class => static fn (Container $c): SitemapSettings => $c
                ->get(SitemapSettingsFactory::class)
                ->create((array)(Yii::$app->getModule(Module::MODULE_ID)?->params ?? [])),

            /**
             * Резолвер адресов работает поверх `frontendUrlManager`: именно он знает и правила
             * модулей, и правила модуля алиасов, и `hostInfo` фронтового домена. Собственного
             * построения URL в пакете нет — иначе карта расходилась бы с реальными ссылками сайта.
             */
            UrlResolver::class => static function (Container $c): UrlResolver {
                $manager = Yii::$app->get('frontendUrlManager');
                if (!$manager instanceof UrlManager) {
                    throw new \yii\base\InvalidConfigException(
                        'Компонент frontendUrlManager не настроен: карта сайта не может строить адреса.',
                    );
                }

                return new UrlResolver($manager);
            },

            /**
             * Синглтоны — там, где важна общая на запрос память: реестр кэширует найденных
             * провайдеров, хранилище — прочитанный манифест, страж сборки — однократное решение
             * «пересобирать или нет». Сборщик держит состояние одной сборки, но живёт ровно один
             * вызов, поэтому синглтон ему не мешает.
             */
            SitemapSettingsFactory::class => SitemapSettingsFactory::class,
            SectionRegistry::class => SectionRegistry::class,
            SitemapStorage::class => SitemapStorage::class,
            SitemapBuilder::class => SitemapBuilder::class,
            BuildGuard::class => BuildGuard::class,
        ],
    ],
];
