<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\Sitemap\results;

/**
 * Итог одной сборки карты — для сообщения в консоли и для флеша в админке.
 */
final readonly class BuildReport
{
    /**
     * @param int                 $urls     Всего адресов в XML-карте.
     * @param int                 $htmlUrls Всего адресов в HTML-карте.
     * @param list<SectionReport> $sections Разбивка по разделам.
     * @param float               $seconds  Длительность сборки.
     * @param int                 $removed  Сколько осиротевших файлов удалено.
     */
    public function __construct(
        public int $urls,
        public int $htmlUrls,
        public array $sections,
        public float $seconds,
        public int $removed,
    ) {
    }

    /**
     * Разделы, которые не собрались. Пустой список — сборка прошла целиком.
     *
     * @return list<SectionReport>
     */
    public function failed(): array
    {
        return array_values(array_filter(
            $this->sections,
            static fn (SectionReport $section): bool => $section->error !== null,
        ));
    }
}
