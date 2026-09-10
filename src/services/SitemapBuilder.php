<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\Sitemap\services;

use Besnovatyj\Contracts\sitemap\SitemapSection;
use Besnovatyj\Contracts\sitemap\SitemapUrl;
use Besnovatyj\Sitemap\results\BuildReport;
use Besnovatyj\Sitemap\results\SectionReport;
use Besnovatyj\Sitemap\settings\ExtraUrl;
use Besnovatyj\Sitemap\settings\SitemapSettings;
use Besnovatyj\Sitemap\storage\Manifest;
use Besnovatyj\Sitemap\storage\SectionState;
use Besnovatyj\Sitemap\storage\SitemapStorage;
use Besnovatyj\Sitemap\writers\WriteResult;
use Besnovatyj\Sitemap\writers\XmlIndexWriter;
use Besnovatyj\Sitemap\writers\XmlUrlSetWriter;
use Throwable;
use Yii;
use yii\mutex\FileMutex;

/**
 * Сборка карты сайта: разделы модулей → файлы `<urlset>` → индекс → дерево HTML-карты → манифест.
 *
 * Один проход даёт обе карты. Это главное отличие от прежнего модуля, где XML и HTML собирались
 * двумя независимыми кусками кода и неизбежно расходились: здесь адрес приходит от провайдера
 * ровно один раз, и обе карты — два способа его записать.
 *
 * Порядок записи выбран так, чтобы читатель никогда не увидел полусобранного состояния: сначала
 * файлы разделов (каждый — атомарно), затем индекс, затем HTML-дерево, и только последним —
 * манифест. До манифеста для контроллеров и админки действует предыдущая сборка целиком.
 *
 * Раздел, чей провайдер сообщает отпечаток ({@see \Besnovatyj\Contracts\sitemap\SitemapFreshness})
 * и не изменился с прошлой сборки, не перечитывается: его файлы остаются на месте, а блок HTML-карты
 * переносится из прошлого дерева. Переиспользование выключается целиком, если сменились настройки
 * или домен, — иначе половина карты жила бы по старым правилам.
 */
final class SitemapBuilder
{
    /** Псевдораздел для адресов, вписанных администратором вручную. */
    public const string EXTRA_SECTION = 'sitemap.extra';

    /** Каталог файлового замка сборки. */
    private const string LOCK_PATH = '@runtime/sitemap_mutex';

    /** Имя замка сборки. */
    private const string LOCK_NAME = 'sitemap-build';

    public function __construct(
        private readonly SectionRegistry $registry,
        private readonly SitemapStorage $storage,
        private readonly UrlResolver $urls,
        private readonly SitemapSettings $settings,
    ) {
    }

    /**
     * Собрать карту заново.
     *
     * Сборка эксклюзивна: она берёт файловый мьютекс БЕЗ ожидания и отказывается работать, если
     * сборку уже ведёт другой процесс. Замок лежит здесь, а не у вызывающих, потому что запускать
     * сборку умеют трое — крон, кнопка в админке и самолечение, — и совпасть во времени они могут
     * все сразу. Две одновременные сборки удаляли бы файлы друг друга (см. {@see SitemapStorage::prune()}).
     *
     * @param callable(string):void|null $progress получатель сообщений о ходе работы (консоль)
     * @throws BuildInProgressException сборку ведёт другой процесс
     */
    public function build(?callable $progress = null): BuildReport
    {
        $mutex = new FileMutex(['mutexPath' => Yii::getAlias(self::LOCK_PATH)]);

        if (!$mutex->acquire(self::LOCK_NAME)) {
            throw new BuildInProgressException('Сборка карты сайта уже выполняется.');
        }

        try {
            return $this->buildLocked($progress);
        } finally {
            $mutex->release(self::LOCK_NAME);
        }
    }

    /**
     * Тело сборки под уже взятым замком.
     *
     * @param callable(string):void|null $progress
     */
    private function buildLocked(?callable $progress): BuildReport
    {
        $started = microtime(true);
        $report = static function (string $message) use ($progress): void {
            if ($progress !== null) {
                $progress($message);
            }
        };

        $host = $this->urls->host();
        $fingerprint = $this->settings->fingerprint();
        $previous = $this->storage->manifest();
        $reusable = $previous !== null
            && $previous->fingerprint === $fingerprint
            && $previous->host === $host;

        $previousHtml = $reusable ? $this->indexHtmlByKey($this->storage->htmlMap()) : [];

        /** @var array<string, SectionState> $states */
        $states = [];
        /** @var list<SectionReport> $reports */
        $reports = [];
        /** @var list<array<string, mixed>> $htmlBlocks */
        $htmlBlocks = [];
        $usedSlugs = [];
        $totalUrls = 0;
        $htmlUrls = 0;

        foreach ($this->registry->enabledSections() as $key => $section) {
            $slug = $this->uniqueSlug($key, $usedSlugs);
            $revision = $this->registry->revision($key);
            $old = $previous?->sections[$key] ?? null;

            if ($old !== null && $this->canReuse($reusable, $section, $old, $revision, $slug, $previousHtml)) {
                $states[$key] = $old;
                $reports[] = new SectionReport($key, $section->label, $old->urls, count($old->files), $old->bytes, reused: true);
                $totalUrls += $old->urls;

                if (isset($previousHtml[$key])) {
                    $htmlBlocks[] = $previousHtml[$key];
                    $htmlUrls += count((array)($previousHtml[$key]['items'] ?? []));
                }

                $report("Раздел «{$section->label}»: без изменений, {$old->urls} адресов.");
                continue;
            }

            try {
                [$result, $items] = $this->writeSection($key, $section, $slug);
            } catch (Throwable $e) {
                // Падение одного провайдера не должно оставлять сайт без карты: раздел
                // пропускается, остальные собираются, а причина видна и в журнале, и в отчёте.
                $owner = $this->registry->ownerOf($key);
                Yii::error(
                    "Раздел карты «{$key}» (модуль «{$owner}») не собран: {$e->getMessage()}",
                    'sitemap/build',
                );
                $reports[] = new SectionReport($key, $section->label, 0, 0, 0, error: $e->getMessage());
                $report("Раздел «{$section->label}»: ОШИБКА — {$e->getMessage()}");
                continue;
            }

            $states[$key] = new SectionState(
                key: $key,
                label: $section->label,
                slug: $slug,
                urls: $result->urls,
                files: $result->files,
                revision: $revision,
                bytes: $result->bytes,
            );
            $reports[] = new SectionReport($key, $section->label, $result->urls, count($result->files), $result->bytes);
            $totalUrls += $result->urls;

            if ($items !== []) {
                $htmlBlocks[] = $this->htmlBlock($section, $items);
                $htmlUrls += count($items);
            }

            $report("Раздел «{$section->label}»: {$result->urls} адресов, файлов: " . count($result->files) . '.');
        }

        [$extraState, $extraReport, $extraHtml] = $this->writeExtraUrls($usedSlugs);

        if ($extraState !== null) {
            $states[self::EXTRA_SECTION] = $extraState;
            $reports[] = $extraReport;
            $totalUrls += $extraState->urls;

            if ($extraHtml !== null) {
                array_unshift($htmlBlocks, $extraHtml);
                $htmlUrls += count((array)$extraHtml['items']);
            }
        }

        $this->writeIndex($states);
        $this->storage->saveHtmlMap($this->settings->htmlEnabled ? $htmlBlocks : []);

        $manifest = new Manifest(
            builtAt: time(),
            seconds: round(microtime(true) - $started, 2),
            urls: $totalUrls,
            fingerprint: $fingerprint,
            host: $host,
            sections: $states,
            htmlUrls: $this->settings->htmlEnabled ? $htmlUrls : 0,
        );

        // Манифест записывается ПЕРВЫМ, и только потом удаляются осиротевшие части. Обратный
        // порядок означал бы окно, в котором читатель ещё работает по старому манифесту, а файл,
        // на который тот ссылается, уже удалён. После записи манифеста лишние файлы и так
        // недостижимы: контроллер отдаёт только то, что в нём перечислено.
        $this->storage->saveManifest($manifest);
        $removed = $this->storage->prune($manifest->files());

        return new BuildReport(
            urls: $totalUrls,
            htmlUrls: $manifest->htmlUrls,
            sections: $reports,
            seconds: $manifest->seconds,
            removed: $removed,
        );
    }

    /**
     * Можно ли оставить файлы раздела с прошлой сборки.
     *
     * Условий четыре, и все обязательны: сборка вообще переиспользуемая (настройки и домен те же),
     * провайдер сообщил отпечаток и он не изменился, файлы физически на месте, а если раздел
     * участвует в HTML-карте — для него есть готовый блок прошлого дерева.
     *
     * @param array<string, array<string, mixed>> $previousHtml блоки прошлого дерева по ключу раздела
     */
    private function canReuse(
        bool $reusable,
        SitemapSection $section,
        SectionState $old,
        ?string $revision,
        string $slug,
        array $previousHtml,
    ): bool {
        if (!$reusable || $revision === null || $old->revision !== $revision) {
            return false;
        }

        if ($old->slug !== $slug || !$this->filesExist($old->files)) {
            return false;
        }

        if ($this->settings->htmlEnabled && $section->inHtmlMap && $old->urls > 0) {
            return isset($previousHtml[$section->key]);
        }

        return true;
    }

    /**
     * Запись одного раздела: поток адресов провайдера → файлы `<urlset>` + элементы HTML-карты.
     *
     * @return array{0: WriteResult, 1: list<array<string, mixed>>}
     */
    private function writeSection(string $key, SitemapSection $section, string $slug): array
    {
        $provider = $this->registry->providerFor($key);
        $writer = new XmlUrlSetWriter($this->storage, $slug, $this->settings->urlsPerFile);
        $items = [];
        $collectHtml = $this->settings->htmlEnabled && $section->inHtmlMap;

        /** @var SitemapUrl $url */
        foreach ($provider?->sitemapUrls($key) ?? [] as $url) {
            $location = $this->urls->forUrl($url);

            $writer->add(
                $location,
                $url->lastModified,
                $url->changeFrequency ?? $section->changeFrequency,
                $url->priority ?? $section->priority,
            );

            if ($collectHtml && $url->inHtmlMap && $url->title !== null && $url->title !== '') {
                $items[] = [
                    'url' => $location,
                    'title' => $url->title,
                    'depth' => max(0, $url->depth),
                ];
            }
        }

        return [$writer->finish(), $items];
    }

    /**
     * Псевдораздел ручных адресов. Собирается всегда заново — он состоит из строки настроек,
     * то есть из того самого, что отключает переиспользование.
     *
     * @param array<string, true> $usedSlugs занятые разделами имена файлов
     * @return array{0: SectionState|null, 1: SectionReport|null, 2: array<string, mixed>|null}
     */
    private function writeExtraUrls(array &$usedSlugs): array
    {
        $extras = $this->settings->extraUrls;
        $disabled = in_array(self::EXTRA_SECTION, $this->settings->disabledSections, true);

        if ($extras === [] || $disabled) {
            return [null, null, null];
        }

        $slug = $this->uniqueSlug(self::EXTRA_SECTION, $usedSlugs);
        $writer = new XmlUrlSetWriter($this->storage, $slug, $this->settings->urlsPerFile);
        $items = [];

        foreach ($extras as $extra) {
            /** @var ExtraUrl $extra */
            $location = $this->urls->forPath($extra->path);
            $writer->add($location, null, $extra->changeFrequency, $extra->priority);

            if ($this->settings->htmlEnabled && $extra->title !== null) {
                $items[] = ['url' => $location, 'title' => $extra->title, 'depth' => 0];
            }
        }

        $result = $writer->finish();
        $label = 'Дополнительные адреса';

        $state = new SectionState(
            key: self::EXTRA_SECTION,
            label: $label,
            slug: $slug,
            urls: $result->urls,
            files: $result->files,
            revision: null,
            bytes: $result->bytes,
        );

        $html = $items === [] ? null : [
            'key' => self::EXTRA_SECTION,
            'label' => $label,
            'icon' => 'bi bi-link-45deg',
            'order' => -1,
            'items' => $items,
        ];

        return [$state, new SectionReport(self::EXTRA_SECTION, $label, $result->urls, count($result->files), $result->bytes), $html];
    }

    /**
     * @param array<string, SectionState> $states
     */
    private function writeIndex(array $states): void
    {
        $items = [];

        foreach ($states as $state) {
            foreach ($state->files as $file) {
                $modified = @filemtime($this->storage->path($file));

                $items[] = [
                    'loc' => $this->urls->forPath('/' . $file),
                    'lastmod' => $modified === false ? null : $modified,
                ];
            }
        }

        new XmlIndexWriter($this->storage)->write($items);
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return array<string, mixed>
     */
    private function htmlBlock(SitemapSection $section, array $items): array
    {
        return [
            'key' => $section->key,
            'label' => $section->label,
            'icon' => $section->icon,
            'order' => $section->order,
            'items' => $items,
        ];
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @return array<string, array<string, mixed>>
     */
    private function indexHtmlByKey(array $blocks): array
    {
        $indexed = [];

        foreach ($blocks as $block) {
            $key = (string)($block['key'] ?? '');

            if ($key !== '') {
                $indexed[$key] = $block;
            }
        }

        return $indexed;
    }

    /**
     * @param list<string> $files
     */
    private function filesExist(array $files): bool
    {
        foreach ($files as $file) {
            if (!$this->storage->exists($file)) {
                return false;
            }
        }

        return $files !== [];
    }

    /**
     * Имя файла раздела: `blog.post` → `blog-post`.
     *
     * Ключ раздела попадает в публичный URL, поэтому приводится к безопасному виду. Совпадение
     * slug'ов у разных ключей теоретически возможно (`blog.post` и `blog-post`) — второй раздел
     * получает числовой суффикс, чтобы файлы не затирали друг друга.
     *
     * @param array<string, true> $used
     */
    private function uniqueSlug(string $key, array &$used): string
    {
        $base = trim(strtolower((string)preg_replace('/[^a-zA-Z0-9]+/', '-', $key)), '-');
        $base = $base === '' ? 'section' : $base;
        $slug = $base;
        $suffix = 1;

        while (isset($used[$slug])) {
            $slug = $base . '-' . (++$suffix);
        }

        $used[$slug] = true;

        return $slug;
    }
}
