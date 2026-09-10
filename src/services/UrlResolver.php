<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\Sitemap\services;

use Besnovatyj\Contracts\sitemap\SitemapUrl;
use yii\base\InvalidConfigException;
use yii\web\UrlManager;

/**
 * Превращение объявленного модулем адреса в абсолютный URL сайта.
 *
 * Работает поверх `frontendUrlManager` — того же компонента, которым сайт строит свои ссылки.
 * Поэтому карта не может разойтись с реальностью: правило модуля алиасов, правило-класс дерева
 * таксономий, суффиксы — всё применяется само собой, а результат совпадает с тем, что посетитель
 * видит в адресной строке.
 *
 * Собственного построения URL из строк здесь нет и быть не должно: именно так в прежнем модуле
 * появились литералы вроде `/blog`, переставшие соответствовать настройкам сайта.
 *
 * Отдельно важно, что резолвер работает из консоли: своего `request` там нет, а `hostInfo` задан
 * компоненту в конфигурации — значит, крон соберёт те же абсолютные адреса, что и веб-запрос.
 */
final readonly class UrlResolver
{
    public function __construct(private UrlManager $manager)
    {
    }

    /** Абсолютный URL адреса, объявленного модулем-провайдером. */
    public function forUrl(SitemapUrl $url): string
    {
        return $this->manager->createAbsoluteUrl(
            array_merge([$url->route], $url->params),
        );
    }

    /** Абсолютный URL пути, введённого администратором вручную.
     * @throws InvalidConfigException
     */
    public function forPath(string $path): string
    {
        return rtrim($this->host(), '/') . '/' . ltrim($path, '/');
    }

    /** Схема и домен фронтенда — попадают в манифест, чтобы заметить смену домена.
     * @throws InvalidConfigException
     */
    public function host(): string
    {
        return (string)$this->manager->getHostInfo();
    }
}
