<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\Sitemap\settings;

use Besnovatyj\Contracts\sitemap\ChangeFrequency;

/**
 * Адрес, вписанный администратором вручную в настройках карты.
 *
 * Нужен для страниц, у которых нет модуля-владельца: главная страница живёт в скелете приложения
 * (`site/index`), и объявить её через {@see \Besnovatyj\Contracts\sitemap\SitemapProvider} некому.
 *
 * Отличается от {@see \Besnovatyj\Contracts\sitemap\SitemapUrl} тем, что несёт ГОТОВЫЙ путь, а не
 * роут: администратор вводит то, что видит в адресной строке. Проверять существование страницы
 * модуль не берётся — это ручной ввод, ответственность за него на том, кто его сделал.
 */
final readonly class ExtraUrl
{
    /**
     * @param string               $path            Путь от корня сайта, начиная со слэша.
     * @param ChangeFrequency|null $changeFrequency Частота изменения; null — элемент не пишется.
     * @param float|null           $priority        Приоритет 0.0…1.0; null — элемент не пишется.
     * @param string|null          $title           Заголовок для HTML-карты; null — только в XML.
     */
    public function __construct(
        public string $path,
        public ?ChangeFrequency $changeFrequency = null,
        public ?float $priority = null,
        public ?string $title = null,
    ) {
    }

    /**
     * Разбор строки настроек: `путь ; частота ; приоритет ; Заголовок`.
     *
     * Обязателен только путь; хвост необязателен целиком и по частям. Строка без ведущего слэша
     * считается путём от корня — «about» и «/about» вводят одинаково часто. Битая строка даёт
     * `null`: ручной ввод не должен ронять сборку карты целиком.
     */
    public static function parse(string $line): ?self
    {
        $parts = array_map('trim', explode(';', $line));
        $path = $parts[0] ?? '';

        if ($path === '' || str_contains($path, ' ')) {
            return null;
        }

        $priority = isset($parts[2]) && is_numeric($parts[2])
            ? (float)$parts[2]
            : null;

        return new self(
            path: '/' . ltrim($path, '/'),
            changeFrequency: ChangeFrequency::parse($parts[1] ?? null),
            priority: $priority !== null ? max(0.0, min(1.0, $priority)) : null,
            title: ($parts[3] ?? '') !== '' ? $parts[3] : null,
        );
    }
}
