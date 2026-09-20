<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\Sitemap\controllers\backend;

use Besnovatyj\Sitemap\services\BuildGuard;
use Besnovatyj\Sitemap\services\BuildInProgressException;
use Besnovatyj\Sitemap\services\RobotsInspector;
use Besnovatyj\Sitemap\services\SectionRegistry;
use Besnovatyj\Sitemap\services\SitemapBuilder;
use Besnovatyj\Sitemap\services\UrlResolver;
use Besnovatyj\Sitemap\settings\SitemapSettings;
use Besnovatyj\Sitemap\storage\SitemapStorage;
use Throwable;
use Yii;
use yii\filters\VerbFilter;
use yii\web\ConflictHttpException;
use yii\web\Controller;
use yii\web\Response;
use yii\web\ServerErrorHttpException;

/**
 * Состояние карты сайта и её пересборка из админки.
 *
 * Страница отвечает на вопрос «почему в карте нет моего раздела» до того, как он будет задан:
 * показывает все объявленные модулями разделы (включая исключённые администратором), сколько
 * адресов дал каждый, когда карта собрана и объявлена ли она в `robots.txt`.
 */
class IndexController extends Controller
{
    public function __construct(
        $id,
        $module,
        private readonly SitemapBuilder $builder,
        private readonly SitemapStorage $storage,
        private readonly SectionRegistry $registry,
        private readonly RobotsInspector $robots,
        private readonly UrlResolver $urls,
        private readonly BuildGuard $guard,
        private readonly SitemapSettings $settings,
        $config = [],
    ) {
        parent::__construct($id, $module, $config);
    }

    public function behaviors(): array
    {
        return [
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => [
                    'build' => ['POST'],
                ],
            ],
        ];
    }

    /**
     * Сводка: состояние сборки, разделы, файлы, `robots.txt`.
     */
    public function actionIndex(): string
    {
        $manifest = $this->storage->manifest();

        return $this->render('index', [
            'manifest' => $manifest,
            'stale' => $manifest !== null && $this->guard->isStale($manifest),
            'sections' => $this->registry->enabledSections(),
            'disabled' => $this->registry->disabledSections(),
            'robots' => $this->robots->check(),
            'settings' => $this->settings,
            'indexUrl' => $this->urls->forPath('/' . SitemapStorage::INDEX_FILE),
            'mapUrl' => $this->urls->forPath('/sitemap'),
        ]);
    }

    /**
     * Полная пересборка карты.
     *
     * Выполняется синхронно: для сайта в тысячи адресов это секунды, и синхронный ответ честнее
     * очереди — администратор сразу видит, сколько адресов дал каждый раздел. На заметно большем
     * объёме карту следует собирать консолью: там нет ни лимита времени веб-сервера, ни занятого
     * воркера PHP-FPM.
     */
    public function actionBuild(): Response|array
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(600);
        }

        if (Yii::$app->request->getIsAjax()) {
            return $this->buildAsJson();
        }

        try {
            $report = $this->builder->build();

            Yii::$app->session->setFlash('success', sprintf(
                'Карта собрана: %d адресов (в HTML-карте — %d) за %s с. Удалено устаревших файлов: %d.',
                $report->urls,
                $report->htmlUrls,
                $report->seconds,
                $report->removed,
            ));

            foreach ($report->failed() as $section) {
                Yii::$app->session->addFlash('warning', "Раздел «{$section->label}» не собран: {$section->error}");
            }
        } catch (BuildInProgressException $e) {
            // Карту уже собирает крон или соседняя вкладка — ждать нечего, но и ошибки нет.
            Yii::$app->session->setFlash('warning', $e->getMessage() . ' Обновите страницу через минуту.');
        } catch (Throwable $e) {
            Yii::error('Сборка карты сайта из админки не удалась: ' . $e->getMessage(), 'sitemap/build');
            Yii::$app->session->setFlash('error', 'Не удалось собрать карту: ' . $e->getMessage());
        }

        return $this->redirect(['index']);
    }

    /**
     * Та же сборка, но для плитки дашборда: ответ — обновлённые числа, а не редирект.
     *
     * Сделано ответвлением одного экшена, а не вторым: операция ровно та же, и раздваивать её
     * значило бы получить две точки, где однажды разойдутся таймаут, права и поведение.
     * Различается только конверт ответа.
     *
     * Занятый замок сборки — не ошибка, а состояние: карту в этот момент собирает крон или
     * соседняя вкладка. Отдаётся 409 с объяснением, и плитка показывает его как сообщение.
     * Числа при этом берутся из текущего манифеста — он мог обновиться тем самым процессом.
     *
     * @return array{urls:int,htmlUrls:int,seconds:float,built:string,warning:string|null}
     *
     * @throws ConflictHttpException     если сборку уже ведёт другой процесс
     * @throws ServerErrorHttpException  если сборка не удалась — причина уже в журнале
     */
    private function buildAsJson(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        try {
            $report = $this->builder->build();
        } catch (BuildInProgressException $e) {
            throw new ConflictHttpException($e->getMessage() . ' Обновите панель через минуту.', 0, $e);
        } catch (Throwable $e) {
            Yii::error('Сборка карты сайта из дашборда не удалась: ' . $e->getMessage(), 'sitemap/build');

            throw new ServerErrorHttpException('Не удалось собрать карту: ' . $e->getMessage(), 0, $e);
        }

        $failed = $report->failed();

        return [
            'urls' => $report->urls,
            'htmlUrls' => $report->htmlUrls,
            'seconds' => round($report->seconds, 1),
            'built' => 'Собрана ' . Yii::$app->formatter->asRelativeTime(time()),
            // Несобравшийся раздел не отменяет сборку, но молчать о нём нельзя: в карте не хватает
            // его адресов. Перечень разделов — на странице состояния, в плитке только счёт.
            'warning' => $failed === []
                ? null
                : 'Собрана, но разделов с ошибкой: ' . count($failed) . ' — подробности на странице карты',
        ];
    }
}
