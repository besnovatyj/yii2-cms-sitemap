<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\Sitemap;

use Besnovatyj\Contracts\dashboard\DashboardWidgetDescriptor;
use Besnovatyj\Contracts\dashboard\ProvidesDashboardWidgets;
use Besnovatyj\Contracts\module\DeclaresModule;
use Besnovatyj\Contracts\module\ProvidesAdminMenu;
use Besnovatyj\Contracts\module\ProvidesDependencies;
use Besnovatyj\Contracts\module\ProvidesDirectories;
use Besnovatyj\Contracts\module\ProvidesOptions;
use Besnovatyj\Kernel\module\CmsModule;
use Besnovatyj\Sitemap\storage\SitemapStorage;
use Besnovatyj\Sitemap\widgets\dashboard\SitemapTile;

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
    ProvidesOptions
{
    public const bool EDITABLE = true;
    public const string VERSION = '1.0.0';
    public const string MODULE_ID = 'Sitemap';

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
