<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\Sitemap\controllers\frontend;

use Besnovatyj\Sitemap\services\BuildGuard;
use Besnovatyj\Sitemap\storage\SitemapStorage;
use Yii;
use yii\web\Controller;
use yii\web\HttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Отдача XML-карты: `/sitemap.xml` и файлы разделов `/sitemap-<раздел>-<N>.xml`.
 *
 * Контроллер ничего не собирает и ничего не знает о разделах — он отдаёт готовый файл. К базе за
 * время запроса не происходит ни одного обращения: карта уже лежит на диске, собранная консолью или
 * стражем {@see BuildGuard}. Это ровно то, чего не хватало прежнему модулю, где каждый заход
 * краулера превращался в десяток запросов к базе.
 *
 * Имя файла раздела приходит из URL, поэтому проверяется по манифесту — отдать можно только то, что
 * перечислено в собранной карте. Одной проверки символов имени было бы мало: осиротевший файл от
 * прошлой сборки не должен оставаться доступным.
 */
class XmlController extends Controller
{
    public function __construct(
        $id,
        $module,
        private readonly BuildGuard $guard,
        private readonly SitemapStorage $storage,
        $config = [],
    ) {
        parent::__construct($id, $module, $config);
    }

    /** Индекс карты — единственная точка входа, которую указывают в `robots.txt`. */
    public function actionIndex(): Response
    {
        return $this->sendMap(SitemapStorage::INDEX_FILE);
    }

    /**
     * Файл одного раздела.
     *
     * @param string $name часть имени файла между `sitemap-` и `.xml` (напр. `blog-post-1`)
     * @throws NotFoundHttpException|HttpException
     */
    public function actionSection(string $name): Response
    {
        $file = 'sitemap-' . $name . '.xml';

        if (!in_array($file, $this->storage->manifest()?->files() ?? [], true)) {
            throw new NotFoundHttpException('Такой части карты сайта нет.');
        }

        return $this->sendMap($file);
    }

    /**
     * Отдать файл карты, предварительно убедившись, что карта вообще собрана.
     *
     * `Last-Modified` проставляется явно: краулеры ходят за картой часто и умеют условный запрос —
     * без этого заголовка каждый заход означает полную перекачку файла.
     *
     * @throws NotFoundHttpException|HttpException
     */
    private function sendMap(string $file): Response
    {
        $this->guard->ensure();

        if (!$this->storage->exists($file)) {
            // Карты нет и собрать её сейчас не вышло: либо сборку прямо сейчас ведёт другой
            // процесс, либо она упала. 503 честнее 404 — адрес существует, содержимое временно нет,
            // и краулер вернётся, а не выбросит его из индекса.
            throw new HttpException(503, 'Карта сайта ещё не собрана, попробуйте позже.');
        }

        $path = $this->storage->path($file);
        $modified = @filemtime($path);

        $response = Yii::$app->response;

        if ($modified !== false) {
            $response->headers->set('Last-Modified', gmdate('D, d M Y H:i:s', $modified) . ' GMT');
        }

        return $response->sendFile($path, $file, [
            'mimeType' => 'application/xml',
            'inline' => true,
        ]);
    }
}
