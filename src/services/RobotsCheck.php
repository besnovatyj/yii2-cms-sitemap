<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\Sitemap\services;

/**
 * Результат проверки `robots.txt` — читается страницей состояния и плиткой дашборда.
 */
final readonly class RobotsCheck
{
    /**
     * @param string $path         Проверенный файл (пустая строка — проверка отключена).
     * @param bool   $enabled      Проверка вообще выполнялась.
     * @param bool   $exists       Файл найден.
     * @param bool   $declared     В файле есть строка `Sitemap:` с нашим адресом.
     * @param string $expectedLine Строка, которую следует добавить.
     */
    public function __construct(
        public string $path,
        public bool $enabled,
        public bool $exists,
        public bool $declared,
        public string $expectedLine,
    ) {
    }

    /** Нужно ли показать администратору предупреждение. */
    public function needsAttention(): bool
    {
        return $this->enabled && !$this->declared;
    }
}
