<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\Sitemap\writers;

use Besnovatyj\Sitemap\storage\SitemapStorage;
use RuntimeException;
use XMLWriter;

/**
 * Запись индекса карты — файла `/sitemap.xml`, перечисляющего файлы разделов.
 *
 * Индекс пишется всегда, даже когда раздел один: адрес `/sitemap.xml` остаётся единственной точкой
 * входа, которую администратор указывает в `robots.txt` и в панелях вебмастера, и не должен менять
 * смысл от того, разросся сайт или нет.
 *
 * `<lastmod>` берётся от самого файла раздела: это ровно то, что нужно краулеру — «стоит ли
 * перечитывать эту часть», и оно честно, потому что неизменившийся раздел при сборке не
 * перезаписывается (см. переиспользование в {@see \Besnovatyj\Sitemap\services\SitemapBuilder}).
 */
final readonly class XmlIndexWriter
{
    private const string NAMESPACE_URI = 'http://www.sitemaps.org/schemas/sitemap/0.9';

    public function __construct(private SitemapStorage $storage)
    {
    }

    /**
     * @param list<array{loc: string, lastmod: int|null}> $items
     */
    public function write(array $items): void
    {
        $name = SitemapStorage::INDEX_FILE;
        $temporary = $this->storage->begin($name);

        $writer = new XMLWriter();

        if (!$writer->openUri($temporary)) {
            throw new RuntimeException('Не удалось открыть индекс карты сайта для записи.');
        }

        $writer->startDocument('1.0', 'UTF-8');
        $writer->setIndent(true);
        $writer->startElement('sitemapindex');
        $writer->writeAttribute('xmlns', self::NAMESPACE_URI);

        foreach ($items as $item) {
            $writer->startElement('sitemap');
            $writer->writeElement('loc', $item['loc']);

            if ($item['lastmod'] !== null) {
                $writer->writeElement('lastmod', date('c', $item['lastmod']));
            }

            $writer->endElement();
        }

        $writer->endElement();
        $writer->endDocument();
        $writer->flush();

        $this->storage->commit($temporary, $name);
    }
}
