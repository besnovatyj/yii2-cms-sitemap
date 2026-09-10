<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\Sitemap\settings;

use Besnovatyj\Contracts\config\OptionItemsProvider;
use Besnovatyj\Contracts\theme\ViewVariantCatalog;
use Besnovatyj\Sitemap\controllers\frontend\MapController;
use Yii;

/**
 * Список вариантов оформления HTML-карты для выпадающего поля в настройках.
 *
 * Варианты предлагает активная тема, поэтому их нельзя переписать в `options.php`: сменилась тема —
 * сменился набор. Заодно этот же список служит правилом валидации, см. {@see OptionItemsProvider}.
 *
 * Каталог резолвится защитно: пакета тем может не быть вовсе — тогда выбирать не из чего, и поле
 * остаётся с единственным значением «базовое представление».
 */
final class HtmlVariantOptionItems implements OptionItemsProvider
{
    /** Значение «вариант не выбран»: рендерится базовое представление модуля. */
    private const string NONE = '';

    /**
     * @return array<string, string>
     */
    public function items(): array
    {
        $items = [self::NONE => '— базовое оформление'];

        if (!Yii::$container->has(ViewVariantCatalog::class)) {
            return $items;
        }

        $catalog = Yii::$container->get(ViewVariantCatalog::class);

        foreach ($catalog->getVariants(MapController::VIEW_SLOT) as $key => $label) {
            $items[$key] = $label;
        }

        return $items;
    }
}
