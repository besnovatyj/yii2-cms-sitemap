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
use yii\helpers\Json;
use yii\helpers\Url;
use yii\web\View;

/**
 * Плитка дашборда: когда собрана карта, сколько в ней адресов, объявлена ли она в `robots.txt`,
 * и кнопка пересборки.
 *
 * Рендерит только тело карточки — «каркас» рисует модуль дашборда. Сборка бьётся AJAX-POST'ом в
 * штатный эндпойнт модуля `/Sitemap/backend/index/build` (тот же, что у кнопки на странице
 * состояния) и возвращает обновлённые числа, которые плитка подставляет на месте: с панели никуда
 * не уходим, и плитка по-прежнему отвечает на вопрос «всё ли в порядке» одним взглядом. Разбивка
 * по разделам, файлы и подробности `robots.txt` остаются на странице состояния — в плитке им тесно.
 *
 * Отдельный случай — «карту уже собирает кто-то другой» (крон или соседняя вкладка): эндпойнт
 * отвечает 409, и плитка показывает это как обычное сообщение, а не как сбой.
 *
 * JS — самодостаточный инлайн (fetch) в POS_END: без внешних ассетов и без зависимости от jQuery,
 * что согласуется с тем, что ассеты в админке подключает пользователь сам.
 *
 * Сервисы берутся из контейнера прямо в `run()`: плитка инстанцируется дашбордом как обычный
 * виджет, без передачи зависимостей, и это единственное место, где их можно получить.
 */
class SitemapTile extends Widget
{
    public function run(): string
    {
        $manifest = Yii::$container->get(SitemapStorage::class)->manifest();

        $this->registerBuildJs();

        $counter = Html::tag(
            'div',
            Html::tag('span', (string)($manifest?->urls ?? 0), [
                'class' => 'display-6 fw-bold lh-1',
                'data-sitemap-urls' => true,
            ])
            . Html::tag('span', 'адресов', ['class' => 'text-muted ms-2']),
            ['class' => 'd-flex align-items-baseline'],
        );

        $built = Html::tag(
            'div',
            $manifest === null
                ? 'Карта сайта ещё не собиралась.'
                : 'Собрана ' . Yii::$app->formatter->asRelativeTime($manifest->builtAt),
            ['class' => 'text-muted small mt-1', 'data-sitemap-built' => true],
        );

        $robots = Yii::$container->get(RobotsInspector::class)->check();
        $warning = $robots->needsAttention()
            ? Html::tag(
                'div',
                '<i class="bi bi-exclamation-triangle me-1"></i>Карта не объявлена в robots.txt',
                ['class' => 'text-warning small mt-1'],
            )
            : '';

        return Html::tag(
            'div',
            $counter . $built . $warning . $this->actions(),
            ['id' => $this->rootId()],
        );
    }

    private function actions(): string
    {
        $button = Html::button('<i class="bi bi-arrow-repeat me-1"></i>Пересобрать', [
            'id' => $this->buildButtonId(),
            'type' => 'button',
            'class' => 'btn btn-sm btn-outline-primary',
        ]);

        $link = Html::a(
            '<i class="bi bi-diagram-3 me-1"></i>К карте сайта',
            Url::to(['/Sitemap/backend/index/index']),
            ['class' => 'btn btn-sm btn-outline-secondary'],
        );

        $status = Html::tag('span', '', ['data-sitemap-status' => true, 'class' => 'text-muted small']);

        return Html::tag(
            'div',
            $button . $link . $status,
            ['class' => 'd-flex align-items-center gap-2 flex-wrap mt-3'],
        );
    }

    private function rootId(): string
    {
        return 'dash-sitemap-' . $this->getId();
    }

    private function buildButtonId(): string
    {
        return 'dash-sitemap-build-' . $this->getId();
    }

    /**
     * Обработчик кнопки. Сборка синхронная и может занять заметное время, поэтому кнопка на это
     * время блокируется, а рядом висит «Собираю…»: без этого администратор жмёт второй раз, и
     * второй запрос упирается в замок сборки, уже занятый первым.
     *
     * Ошибку (и сообщение «карта уже собирается») берём из тела ответа: эндпойнт отдаёт нативный
     * JSON-конверт Yii (`{name, message, ...}`) с реальным HTTP-статусом.
     */
    private function registerBuildJs(): void
    {
        $rootId = $this->rootId();
        $buttonId = $this->buildButtonId();
        $endpoint = Json::encode(Url::to(['/Sitemap/backend/index/build']));
        $csrfHeader = Json::encode(Yii::$app->request->csrfHeader);
        $csrfToken = Json::encode(Yii::$app->request->getCsrfToken());

        $this->view->registerJs(
            <<<JS
            (function () {
                var root = document.getElementById('{$rootId}');
                var btn = document.getElementById('{$buttonId}');
                if (!root || !btn) { return; }
                var status = root.querySelector('[data-sitemap-status]');
                var urls = root.querySelector('[data-sitemap-urls]');
                var built = root.querySelector('[data-sitemap-built]');
                btn.addEventListener('click', function () {
                    btn.disabled = true;
                    status.textContent = 'Собираю…';
                    var headers = { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' };
                    headers[{$csrfHeader}] = {$csrfToken};
                    fetch({$endpoint}, { method: 'POST', headers: headers })
                        .then(function (r) {
                            return r.json().then(function (body) {
                                return r.ok ? body : Promise.reject(body);
                            });
                        })
                        .then(function (body) {
                            urls.textContent = body.urls;
                            built.textContent = body.built;
                            status.textContent = body.warning
                                ? body.warning
                                : 'Готово за ' + body.seconds + ' с';
                        })
                        .catch(function (body) {
                            status.textContent = (body && body.message) || 'Ошибка сборки';
                        })
                        .finally(function () { btn.disabled = false; });
                });
            })();
            JS,
            View::POS_END,
        );
    }
}
