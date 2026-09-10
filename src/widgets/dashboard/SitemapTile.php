<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\Sitemap\widgets\dashboard;

use Besnovatyj\Sitemap\services\RobotsInspector;
use Besnovatyj\Sitemap\storage\SitemapStorage;
use Yii;
use yii\base\Widget;
use yii\helpers\Html;
use yii\helpers\Url;

/**
 * Плитка дашборда: когда собрана карта, сколько в ней адресов и объявлена ли она в `robots.txt`.
 *
 * Рендерит только тело карточки — «каркас» рисует модуль дашборда. Кнопки сборки здесь нет
 * намеренно: сборка уводит со страницы, а плитка должна отвечать на вопрос «всё ли в порядке»
 * одним взглядом. Действия — на странице состояния.
 *
 * Сервисы берутся из контейнера прямо в `run()`: плитка инстанцируется дашбордом как обычный
 * виджет, без передачи зависимостей, и это единственное место, где их можно получить.
 */
class SitemapTile extends Widget
{
    public function run(): string
    {
        $storage = Yii::$container->get(SitemapStorage::class);
        $manifest = $storage->manifest();

        if ($manifest === null) {
            return Html::tag('div', 'Карта сайта ещё не собиралась.', ['class' => 'text-muted small mb-2'])
                . $this->link();
        }

        $counter = Html::tag(
            'div',
            Html::tag('span', (string)$manifest->urls, ['class' => 'display-6 fw-bold lh-1'])
            . Html::tag('span', 'адресов', ['class' => 'text-muted ms-2']),
            ['class' => 'd-flex align-items-baseline'],
        );

        $built = Html::tag(
            'div',
            'Собрана ' . Yii::$app->formatter->asRelativeTime($manifest->builtAt),
            ['class' => 'text-muted small mt-1'],
        );

        $robots = Yii::$container->get(RobotsInspector::class)->check();
        $warning = $robots->needsAttention()
            ? Html::tag(
                'div',
                '<i class="bi bi-exclamation-triangle me-1"></i>Карта не объявлена в robots.txt',
                ['class' => 'text-warning small mt-1'],
            )
            : '';

        return $counter . $built . $warning . $this->link();
    }

    private function link(): string
    {
        return Html::a(
            '<i class="bi bi-diagram-3 me-1"></i>К карте сайта',
            Url::to(['/Sitemap/backend/index/index']),
            ['class' => 'btn btn-sm btn-outline-primary mt-3'],
        );
    }
}
