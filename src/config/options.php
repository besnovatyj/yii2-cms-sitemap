<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

use Besnovatyj\Sitemap\settings\HtmlVariantOptionItems;

/**
 * Опции модуля настроек `yii2-cms-config` для карты сайта.
 *
 * Пути указывают в `modules.Sitemap.params.*` — оттуда их читает
 * {@see \Besnovatyj\Sitemap\settings\SitemapSettingsFactory}.
 *
 * Список разделов здесь не перечисляется: он равен составу установленных контентных модулей,
 * поэтому исключения задаются строкой ключей, а сами ключи администратор видит на странице
 * состояния карты. Список вариантов представления, наоборот, собирается поставщиком
 * ({@see \Besnovatyj\Contracts\config\OptionItemsProvider}) в момент показа формы — он зависит от
 * активной темы, а файл опций обязан оставаться статичным: менеджер модулей считает от него
 * контрольную сумму манифеста, и «плавающий» список помечал бы модуль изменившимся при каждой
 * смене темы.
 *
 * Путь к `robots.txt` опцией не сделан намеренно: это инфраструктурный параметр развёртывания, а не
 * решение редактора сайта — он задаётся в конфигурации приложения.
 */
return [
    'sitemap_disabled_sections' => [
        'path'        => 'modules.Sitemap.params.disabledSections',
        'label'       => 'Исключённые разделы',
        'description' => 'Ключи разделов через запятую, напр.: blog.taxonomy, gallery.category',
        'category'    => 'Sitemap',
        'rules'       => [
            ['string', 'max' => 1000],
        ],
        'inputOptions' => [
            'type' => 'text',
        ],
    ],

    'sitemap_priorities' => [
        'path'        => 'modules.Sitemap.params.priorities',
        'label'       => 'Приоритеты разделов',
        'description' => 'Ключ: значение 0.0–1.0 через запятую, напр.: blog.post: 0.8, page.page: 0.6',
        'category'    => 'Sitemap',
        'rules'       => [
            ['string', 'max' => 1000],
        ],
        'inputOptions' => [
            'type' => 'text',
        ],
    ],

    'sitemap_change_frequencies' => [
        'path'        => 'modules.Sitemap.params.changeFrequencies',
        'label'       => 'Частота изменения разделов',
        'description' => 'Ключ: частота через запятую, напр.: blog.post: daily, page.page: monthly. '
            . 'Допустимо: always, hourly, daily, weekly, monthly, yearly, never',
        'category'    => 'Sitemap',
        'rules'       => [
            ['string', 'max' => 1000],
        ],
        'inputOptions' => [
            'type' => 'text',
        ],
    ],

    'sitemap_extra_urls' => [
        'path'        => 'modules.Sitemap.params.extraUrls',
        'label'       => 'Дополнительные адреса',
        'description' => 'По одному в строке: путь ; частота ; приоритет ; Заголовок. '
            . 'Обязателен только путь. Здесь объявляется главная страница и всё, у чего нет своего модуля',
        'category'    => 'Sitemap',
        'rules'       => [
            ['string', 'max' => 20000],
        ],
        'inputOptions' => [
            'type' => 'textarea',
        ],
    ],

    'sitemap_urls_per_file' => [
        'path'        => 'modules.Sitemap.params.urlsPerFile',
        'label'       => 'Адресов в одном файле',
        'description' => 'Предел протокола — 50 000; при переполнении раздел режется на части автоматически',
        'category'    => 'Sitemap',
        'rules'       => [
            ['required'],
            ['integer', 'min' => 100, 'max' => 50000],
        ],
        'inputOptions' => [
            'type' => 'number',
        ],
    ],

    'sitemap_ttl' => [
        'path'        => 'modules.Sitemap.params.ttl',
        'label'       => 'Срок годности карты, минут',
        'description' => 'После этого срока карта пересобирается сама при первом обращении. '
            . '0 — только вручную и по расписанию (рекомендуется, если настроен крон)',
        'category'    => 'Sitemap',
        'rules'       => [
            ['required'],
            ['integer', 'min' => 0, 'max' => 43200],
        ],
        'inputOptions' => [
            'type' => 'number',
        ],
    ],

    'sitemap_html_enabled' => [
        'path'        => 'modules.Sitemap.params.htmlEnabled',
        'label'       => 'Показывать карту сайта посетителям',
        'description' => 'Страница /sitemap с оглавлением сайта',
        'category'    => 'Sitemap',
        'rules'       => [
            ['boolean'],
        ],
        'inputOptions' => [
            'type' => 'checkbox',
        ],
    ],

    'sitemap_html_variant' => [
        'path'        => 'modules.Sitemap.params.htmlVariant',
        'label'       => 'Вариант оформления карты',
        'description' => 'Варианты предлагает активная тема; «—» — базовое представление модуля',
        'category'    => 'Sitemap',
        'inputOptions' => [
            'type'          => 'dropdown',
            'itemsProvider' => HtmlVariantOptionItems::class,
        ],
    ],
];
