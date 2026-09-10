<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\Sitemap\services;

use Besnovatyj\Sitemap\settings\SitemapSettings;
use Besnovatyj\Sitemap\storage\SitemapStorage;
use Yii;

/**
 * Проверка того, объявлена ли карта в `robots.txt`.
 *
 * Собранная, но никому не объявленная карта — самая частая причина «карта есть, а поисковик её не
 * видит»: адрес `/sitemap.xml` угадывается не всеми и не всегда, а строка `Sitemap:` в `robots.txt`
 * работает для всех роботов сразу.
 *
 * Модуль сознательно НЕ правит `robots.txt` сам: файл принадлежит скелету приложения, лежит в
 * каталоге раздачи и может содержать чужие правила, а домен в дефолты пакета попадать не должен.
 * Поэтому здесь только диагностика и готовая к вставке строка — решение остаётся за
 * администратором. Путь к файлу задаётся параметром модуля (`robotsFile`); пустое значение
 * выключает проверку — например, если `robots.txt` раздаётся не файлом.
 */
final readonly class RobotsInspector
{
    public function __construct(
        private SitemapSettings $settings,
        private UrlResolver $urls,
    ) {
    }

    public function check(): RobotsCheck
    {
        $expected = 'Sitemap: ' . $this->urls->forPath('/' . SitemapStorage::INDEX_FILE);
        $configured = $this->settings->robotsFile;

        if ($configured === '') {
            return new RobotsCheck('', false, false, false, $expected);
        }

        $path = (string)Yii::getAlias($configured, false);

        if ($path === '' || !is_file($path)) {
            return new RobotsCheck($configured, true, false, false, $expected);
        }

        $content = (string)file_get_contents($path);

        // Сравнение по адресу карты, а не по всей строке: регистр директивы и число пробелов
        // роботами не различаются, и придираться к ним значило бы пугать администратора зря.
        $declared = stripos($content, $this->urls->forPath('/' . SitemapStorage::INDEX_FILE)) !== false;

        return new RobotsCheck($path, true, true, $declared, $expected);
    }
}
