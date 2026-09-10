<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\Sitemap\settings;

/**
 * Чтение `params` модуля с приведением к нужному типу.
 *
 * Модуль настроек `yii2-cms-config` умеет писать в `modules.<Id>.params.*` только скаляры, поэтому
 * всё, что по смыслу является списком или картой, хранится строкой: «blog.post, page.page»,
 * «blog.post: 0.8», по адресу в строке. Разбор этих строк собран здесь, в одном месте.
 *
 * Класс ничего не знает ни о Yii, ни о конкретных ключах: получает готовый массив параметров и
 * отдаёт типизированные значения. Кто и откуда взял массив — забота composition root
 * (`config/common.php`).
 *
 * Такой же по смыслу разборщик есть в пакете сквозного поиска. Зависеть от него ради шестидесяти
 * строк нельзя — пакеты живут в разных репозиториях и обновляются независимо, поэтому здесь
 * сознательная копия нужного минимума, а не общая зависимость.
 */
final readonly class ParamReader
{
    /**
     * @param array<string, mixed> $params `params` модуля
     */
    public function __construct(private array $params)
    {
    }

    /** Строковое значение с обрезанными пробелами по краям. */
    public function string(string $key, string $default = ''): string
    {
        $value = $this->params[$key] ?? null;

        return is_scalar($value) ? trim((string)$value) : $default;
    }

    /**
     * Целое значение, зажатое в допустимый диапазон.
     *
     * Границы применяются молча и намеренно: значение приходит из админки, и «адресов в файле = 0»
     * должен превращаться в разумный минимум, а не ронять сборку бесконечной нарезкой.
     */
    public function int(string $key, int $default, ?int $min = null, ?int $max = null): int
    {
        $value = $this->params[$key] ?? null;
        $result = is_scalar($value) && $value !== '' ? (int)$value : $default;

        if ($min !== null) {
            $result = max($min, $result);
        }

        if ($max !== null) {
            $result = min($max, $result);
        }

        return $result;
    }

    /**
     * Логическое значение.
     *
     * Галочка из админки приходит строкой «1»/«0», поэтому приведение идёт через int:
     * `(bool)'0'` в PHP равно false, но полагаться на это неявно не стоит.
     */
    public function bool(string $key, bool $default): bool
    {
        $value = $this->params[$key] ?? null;

        if ($value === null || $value === '' || !is_scalar($value)) {
            return $default;
        }

        return (bool)(int)$value;
    }

    /**
     * Список, записанный через запятую и/или переводы строк.
     *
     * @return list<string>
     */
    public function list(string $key): array
    {
        // Разделители перечислены поштучно (\r, \n), потому что здесь символьный класс: внутри
        // `[...]` escape-последовательность \R недопустима и роняет компиляцию шаблона. Отдельным
        // атомом \R работать не мешает ничто — так он и применён в {@see lines()}.
        $parts = preg_split('/[,\r\n]+/u', $this->string($key)) ?: [];

        return array_values(array_filter(
            array_map('trim', $parts),
            static fn (string $value): bool => $value !== '',
        ));
    }

    /**
     * Непустые строки значения — по одной на элемент. В отличие от {@see list()} запятая
     * разделителем НЕ считается: она встречается внутри строки (заголовок адреса).
     *
     * @return list<string>
     */
    public function lines(string $key): array
    {
        // Здесь \R уместен: он стоит самостоятельным атомом, а не внутри `[...]`, и покрывает
        // все переводы строк разом — включая одиночный \r из старых редакторов.
        $parts = preg_split('/\R/u', $this->string($key)) ?: [];

        return array_values(array_filter(
            array_map('trim', $parts),
            static fn (string $value): bool => $value !== '',
        ));
    }

    /**
     * Карта «ключ: число», записанная одной строкой: `blog.post: 0.8, page.page: 0.6`.
     *
     * Десятичный разделитель — только точка: запятая в этой строке разделяет элементы списка, и
     * «0,8» распалось бы на «blog.post: 0» и «8». Нечисловое значение пропускается — опечатка в
     * одной паре не должна отменять остальные.
     *
     * Ноль — допустимое значение: приоритет 0.0 по протоколу означает «самый низкий», а не
     * «не задан».
     *
     * @return array<string, float>
     */
    public function floatMap(string $key): array
    {
        $map = [];

        foreach ($this->pairs($key) as $name => $value) {
            if (is_numeric($value)) {
                $map[$name] = (float)$value;
            }
        }

        return $map;
    }

    /**
     * Карта «ключ: значение», записанная одной строкой: `blog.post: daily, page.page: monthly`.
     *
     * @return array<string, string>
     */
    public function stringMap(string $key): array
    {
        return $this->pairs($key);
    }

    /**
     * Разбор перечисления пар «имя: значение».
     *
     * @return array<string, string>
     */
    private function pairs(string $key): array
    {
        $map = [];

        foreach ($this->list($key) as $pair) {
            $parts = explode(':', $pair, 2);

            if (count($parts) !== 2) {
                continue;
            }

            $name = trim($parts[0]);
            $value = trim($parts[1]);

            if ($name !== '' && $value !== '') {
                $map[$name] = $value;
            }
        }

        return $map;
    }
}
