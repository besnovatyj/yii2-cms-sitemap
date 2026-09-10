<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\Sitemap\writers;

use Besnovatyj\Contracts\sitemap\ChangeFrequency;
use Besnovatyj\Sitemap\storage\SitemapStorage;
use RuntimeException;
use XMLWriter;

/**
 * Потоковая запись файлов `<urlset>` одного раздела с нарезкой по лимитам протокола.
 *
 * Пишет сразу в файл (`XMLWriter::openUri`), а не собирает строку в памяти, как это делал прежний
 * модуль: раздел на десятки тысяч адресов иначе упирается в `memory_limit` ровно тогда, когда сайт
 * дорос до того, что карта ему действительно нужна.
 *
 * Протокол sitemaps.org ограничивает файл 50 000 адресами и 50 МБ в распакованном виде. Оба предела
 * writer держит сам: при достижении любого из них текущий файл закрывается и начинается следующая
 * часть — `sitemap-blog-post-2.xml`. Поэтому ни провайдер, ни сборщик о нарезке не думают вовсе.
 *
 * Байтовый предел взят с запасом (45 МБ): длина считается по факту сброса буфера, то есть с
 * небольшим отставанием, и упереться в точные 50 МБ значило бы иногда его превышать.
 */
final class XmlUrlSetWriter
{
    private const string NAMESPACE_URI = 'http://www.sitemaps.org/schemas/sitemap/0.9';

    /** Предел адресов протокола; настройка администратора может быть только ниже. */
    public const int URLS_HARD_LIMIT = 50000;

    /** Предел размера файла с запасом к протокольным 50 МБ. */
    private const int BYTES_LIMIT = 45 * 1024 * 1024;

    /** Как часто сбрасывать буфер: реже — меньше системных вызовов, чаще — точнее счётчик байт. */
    private const int FLUSH_EVERY = 500;

    private ?XMLWriter $writer = null;

    private string $temporaryPath = '';

    private string $currentName = '';

    private int $part = 0;

    private int $urlsInPart = 0;

    private int $bytesInPart = 0;

    /** @var list<string> */
    private array $files = [];

    private int $totalUrls = 0;

    private int $totalBytes = 0;

    /**
     * @param string $slug        ключ раздела в виде для имени файла (`blog-post`)
     * @param int    $urlsPerFile предел адресов в одном файле из настроек
     */
    public function __construct(
        private readonly SitemapStorage $storage,
        private readonly string $slug,
        private readonly int $urlsPerFile,
    ) {
    }

    /**
     * Добавить адрес. Файл открывается лениво — раздел, не отдавший ни одного адреса, не оставляет
     * после себя пустого `<urlset>`.
     */
    public function add(
        string $location,
        ?int $lastModified = null,
        ?ChangeFrequency $changeFrequency = null,
        ?float $priority = null,
    ): void {
        if ($this->writer === null) {
            $this->openPart();
        }

        $writer = $this->writer;
        $writer->startElement('url');
        $writer->writeElement('loc', $location);

        if ($lastModified !== null) {
            $writer->writeElement('lastmod', date('c', $lastModified));
        }

        if ($changeFrequency !== null) {
            $writer->writeElement('changefreq', $changeFrequency->value);
        }

        if ($priority !== null) {
            $writer->writeElement('priority', number_format($priority, 1, '.', ''));
        }

        $writer->endElement();

        $this->urlsInPart++;
        $this->totalUrls++;

        if ($this->urlsInPart % self::FLUSH_EVERY === 0) {
            $this->flush();
        }

        if ($this->urlsInPart >= $this->limit() || $this->bytesInPart >= self::BYTES_LIMIT) {
            $this->closePart();
        }
    }

    /** Завершить раздел: дописать последний файл и отдать итог. */
    public function finish(): WriteResult
    {
        $this->closePart();

        return new WriteResult($this->files, $this->totalUrls, $this->totalBytes);
    }

    /** Сколько адресов пустить в один файл: настройка, но не выше предела протокола. */
    private function limit(): int
    {
        return min(max(1, $this->urlsPerFile), self::URLS_HARD_LIMIT);
    }

    private function openPart(): void
    {
        $this->part++;
        $this->currentName = sprintf('sitemap-%s-%d.xml', $this->slug, $this->part);
        $this->temporaryPath = $this->storage->begin($this->currentName);

        $writer = new XMLWriter();

        if (!$writer->openUri($this->temporaryPath)) {
            throw new RuntimeException("Не удалось открыть файл карты сайта «{$this->currentName}» для записи.");
        }

        $writer->startDocument('1.0', 'UTF-8');
        $writer->setIndent(true);
        $writer->startElement('urlset');
        $writer->writeAttribute('xmlns', self::NAMESPACE_URI);

        $this->writer = $writer;
        $this->urlsInPart = 0;
        $this->bytesInPart = 0;
    }

    private function closePart(): void
    {
        if ($this->writer === null) {
            return;
        }

        $this->writer->endElement();
        $this->writer->endDocument();
        $this->flush();
        $this->writer = null;

        $this->storage->commit($this->temporaryPath, $this->currentName);

        $this->files[] = $this->currentName;
        $this->totalBytes += $this->bytesInPart;
    }

    /**
     * Сброс буфера на диск. При записи в файл `flush()` возвращает число записанных байт —
     * этим и считается размер части, без обращения к файловой системе.
     */
    private function flush(): void
    {
        $written = $this->writer?->flush(true);

        if (is_int($written)) {
            $this->bytesInPart += $written;
        }
    }
}
