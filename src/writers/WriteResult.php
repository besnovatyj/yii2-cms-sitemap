<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\Sitemap\writers;

/**
 * Итог записи одного раздела: какие файлы получились, сколько в них адресов и байт.
 *
 * Отдельный тип, а не массив из трёх элементов: значения попадают в манифест, в отчёт сборки и на
 * страницу состояния, и перепутать местами `urls` и `bytes` в позиционном массиве слишком легко.
 */
final readonly class WriteResult
{
    /**
     * @param list<string> $files Имена файлов раздела в порядке следования в индексе.
     * @param int          $urls  Сколько адресов записано.
     * @param int          $bytes Суммарный размер файлов.
     */
    public function __construct(
        public array $files,
        public int $urls,
        public int $bytes,
    ) {
    }
}
