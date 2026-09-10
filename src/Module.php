<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\Sitemap;

use Besnovatyj\Contracts\dashboard\DashboardWidgetDescriptor;
use Besnovatyj\Contracts\dashboard\ProvidesDashboardWidgets;
use Besnovatyj\Contracts\menu\MenuTarget;
use Besnovatyj\Contracts\menu\MenuTargetProvider;
use Besnovatyj\Contracts\module\DeclaresModule;
use Besnovatyj\Contracts\module\ProvidesAdminMenu;
use Besnovatyj\Contracts\module\ProvidesDependencies;
use Besnovatyj\Contracts\module\ProvidesDirectories;
use Besnovatyj\Contracts\module\ProvidesOptions;
use Besnovatyj\Kernel\module\CmsModule;
use Besnovatyj\Sitemap\settings\SitemapSettings;
use Besnovatyj\Sitemap\storage\SitemapStorage;
use Besnovatyj\Sitemap\widgets\dashboard\SitemapTile;
use Yii;

/**
 * Модуль карты сайта: XML-карта для поисковых систем и HTML-карта для посетителя.
 *
 * Сам контента не имеет и не знает ни одного контентного модуля поимённо: адреса приходят от
 * модулей, реализовавших {@see \Besnovatyj\Contracts\sitemap\SitemapProvider}, и собираются в
 * реестре по `instanceof` — тем же приёмом, что источники сквозного поиска и цели меню. Выключенный
 * в менеджере модулей поставщик исчезает из конфига приложения, а значит и из карты, без единой
 * правки здесь.
 *
 * Модулем (а не библиотекой) оформлен намеренно: так параметрами карты управляет модуль настроек
 * `yii2-cms-config` (он умеет писать только в `modules.<Id>.params.*`), а страница состояния и пункт
 * меню появляются в админке штатным образом.
 *
 * Миграций у модуля нет: единственное состояние — файловые артефакты сборки в `@runtime/sitemap`,
 * которые полностью восстанавливаются командой `php yii Sitemap/build/run`.
 */
class Module extends CmsModule implements
    DeclaresModule,
    ProvidesAdminMenu,
    ProvidesDashboardWidgets,
    ProvidesDependencies,
    ProvidesDirectories,
    ProvidesOptions,
    MenuTargetProvider
{
    public const bool EDITABLE = true;
    public const string VERSION = '1.0.0';
    public const string MODULE_ID = 'Sitemap';

    /**
     * Единственный «кандидат» карты для каталога целей меню.
     *
     * У модуля меню всё адресуется парой «цель + slug», а у карты сайта адрес один и параметров не
     * имеет. Значение отдаётся как slug и поглощается URL-правилом с тем же `defaults`
     * (см. `config/common.php`), поэтому в меню попадает чистый `/sitemap`.
     */
    public const string MENU_SLUG = 'sitemap';

    public static function moduleId(): string { return self::MODULE_ID; }
    public static function moduleVersion(): string { return self::VERSION; }
    public static function isEditable(): bool { return self::EDITABLE; }
    public static function adminMenu(): array { return require __DIR__ . '/config/adminMenu.php'; }
    public static function moduleConfig(): array { return require __DIR__ . '/config/config.php'; }
    public static function options(): array { return require __DIR__ . '/config/options.php'; }
    public static function dependencies(): array { return require __DIR__ . '/config/dependencies.php'; }

    /**
     * Каталог артефактов сборки. Создаётся менеджером модулей при установке, чтобы первая же
     * сборка (в том числе автоматическая, из запроса краулера) не упиралась в отсутствующий путь.
     *
     * @return list<string>
     */
    public static function directories(): array
    {
        return [SitemapStorage::DIRECTORY];
    }

    /**
     * Цель для построения пункта меню — сама карта сайта.
     *
     * Контракт {@see MenuTargetProvider} рассчитан на модули с множеством адресуемых по slug
     * сущностей («раздел страниц», «таксономия блога»), а у карты адрес ровно один. Вместо того
     * чтобы расширять общий контракт ради единственного исключения, модуль отвечает по нему
     * честно, но вырожденно: одна цель и один кандидат {@see MENU_SLUG}. Администратор при этом
     * работает привычным каскадом «модуль → цель → кандидат» и получает готовую ссылку `/sitemap`,
     * а не вводит адрес руками.
     *
     * Параметр `slug`, который модуль меню подставит в ссылку, поглощается URL-правилом с тем же
     * `defaults` — Yii не выносит в query-строку значение, совпадающее с умолчанием правила.
     *
     * Если человеческая карта отключена в настройках, целей нет вовсе: предлагать пункт меню,
     * ведущий на 404, нельзя.
     *
     * @return MenuTarget[]
     */
    public function menuTargets(): array
    {
        return Yii::$container->get(SitemapSettings::class)->htmlEnabled
            ? [new MenuTarget('/Sitemap/map/index', 'Карта сайта')]
            : [];
    }

    /**
     * {@inheritdoc}
     *
     * @return array<string,string>
     */
    public function menuCandidates(string $route): array
    {
        return match (ltrim($route, '/')) {
            'Sitemap/map/index' => [self::MENU_SLUG => 'Карта сайта'],
            default => [],
        };
    }

    /**
     * Плитка дашборда: когда карта собрана, сколько в ней адресов, кнопка пересборки.
     *
     * @return DashboardWidgetDescriptor[]
     */
    public static function dashboardWidgets(): array
    {
        return [
            new DashboardWidgetDescriptor(
                id: self::MODULE_ID . '.state',
                title: 'Карта сайта',
                tileClass: SitemapTile::class,
                iconClass: 'bi bi-diagram-3',
                priority: 400,
            ),
        ];
    }
}
