<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\Sitemap\results;

/**
 * Что случилось с одним разделом в ходе сборки.
 *
 * Нужен, чтобы и консоль, и админка показывали одинаково подробную картину: какой раздел сколько
 * адресов дал, какой был переиспользован без чтения базы, а какой не собрался и почему. Без этого
 * ответ на вопрос «почему в карте нет статей» сводится к чтению журнала.
 */
final readonly class SectionReport
{
    /**
     * @param string      $key    Ключ раздела.
     * @param string      $label  Подпись раздела.
     * @param int         $urls   Сколько адресов в разделе.
     * @param int         $files  Из скольких файлов состоит раздел.
     * @param int         $bytes  Суммарный размер файлов.
     * @param bool        $reused Раздел не перечитывался: данные не менялись с прошлой сборки.
     * @param string|null $error  Причина, по которой раздел не собран (null — всё в порядке).
     */
    public function __construct(
        public string $key,
        public string $label,
        public int $urls,
        public int $files,
        public int $bytes,
        public bool $reused = false,
        public ?string $error = null,
    ) {
    }
}
