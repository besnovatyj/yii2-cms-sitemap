<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

return [
    // Состояние карты сайта и её пересборка
    [
        'label'     => 'Карта сайта',
        'iconClass' => 'bi bi-diagram-3 me-1',
        'url'       => ['/Sitemap/backend/index/index'],
        'active'    => static function () {
            return str_contains(\Yii::$app->request->url, 'Sitemap/backend/index');
        },
        '_meta' => [
            'placements' => [
                [
                    'location'      => 'left-sidebar',
                    'group'         => 'SEO',
                    'groupIcon'     => 'bi bi-graph-up-arrow',
                    'priority'      => 100,
                    'groupPriority' => 710,
                ],
            ],
        ],
    ],
];
