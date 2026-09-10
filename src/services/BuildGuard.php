<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\Sitemap\services;

use Besnovatyj\Sitemap\settings\SitemapSettings;
use Besnovatyj\Sitemap\storage\Manifest;
use Besnovatyj\Sitemap\storage\SitemapStorage;
use Throwable;
use Yii;

/**
 * Самолечение карты: если файлов нет или они устарели, собрать их прямо в запросе.
 *
 * Основной способ сборки — консоль по крону: там нет ни лимита времени веб-сервера, ни занятого
 * воркера PHP-FPM. Этот страж — страховка на случаи, когда крон не настроен, каталог `@runtime`
 * вычищен или модуль только что установлен: карта не должна отдавать 404 из-за того, что никто не
 * нажал кнопку.
 *
 * Два свойства, ради которых это отдельный класс:
 *
 * 1. **Один сборщик на сайт.** Замок держит сам {@see SitemapBuilder}, поэтому десять
 *    одновременных запросов краулера не запустят десять сборок: девять получат отказ и отдадут то,
 *    что уже лежит на диске. Ждать освобождения было бы хуже — очередь запросов повисла бы на всё
 *    время сборки.
 * 2. **Отказ не ломает выдачу.** Если сборка не удалась, а прошлая карта на диске есть — отдаётся
 *    она. Устаревшая карта полезнее пятисотой ошибки.
 */
final class BuildGuard
{
    private bool $checked = false;

    public function __construct(
        private readonly SitemapStorage $storage,
        private readonly SitemapBuilder $builder,
        private readonly SitemapSettings $settings,
    ) {
    }

    /**
     * Убедиться, что на диске есть карта, и пересобрать её, если она устарела.
     *
     * @return bool есть ли карта, которую можно отдать (пусть даже устаревшая)
     */
    public function ensure(): bool
    {
        if ($this->checked) {
            return $this->storage->manifest() !== null;
        }

        $this->checked = true;
        $manifest = $this->storage->manifest();

        if ($manifest !== null && !$this->isStale($manifest)) {
            return true;
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(600);
        }

        try {
            $this->builder->build();

            return true;
        } catch (BuildInProgressException) {
            // Сборку ведёт другой процесс — это не сбой: отдаём то, что уже есть, и молчим.
            // Запись в журнал ошибок здесь означала бы «ошибка» при каждом совпадении крона
            // с заходом краулера.
            $this->storage->forget();

            return $this->storage->manifest() !== null;
        } catch (Throwable $e) {
            Yii::error('Автоматическая сборка карты сайта не удалась: ' . $e->getMessage(), 'sitemap/guard');

            return $this->storage->manifest() !== null;
        }
    }

    /**
     * Устарела ли карта. `ttl = 0` — не устаревает никогда: администратор осознанно выбрал
     * собирать её только консолью и кнопкой.
     */
    public function isStale(Manifest $manifest): bool
    {
        return $this->settings->ttlMinutes > 0
            && $manifest->age() > $this->settings->ttlMinutes * 60;
    }
}
