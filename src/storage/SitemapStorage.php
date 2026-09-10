<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\Sitemap\storage;

use FilesystemIterator;
use RuntimeException;
use SplFileInfo;
use Yii;
use yii\helpers\FileHelper;

/**
 * Файловое хранилище артефактов карты сайта.
 *
 * Всё, что модуль пишет на диск, проходит здесь — в одном месте собраны три решения:
 *
 * 1. **Каталог вне docroot.** Артефакты лежат в `@runtime/sitemap` и отдаются контроллером, а не
 *    лежат в `frontend/pub`. Записываемый каталог внутри корня сайта — известный способ превратить
 *    любую ошибку в чужой исполняемый файл; отдача готового файла стоит одного `sendFile()` без
 *    единого запроса к базе. Если понадобится совсем без PHP — в nginx добавляется `alias` на этот
 *    каталог, и код не меняется.
 * 2. **Атомарная запись.** Файл всегда пишется во временный и переименовывается: `rename()` в
 *    пределах одной ФС атомарен, поэтому краулер не может получить полуготовый XML. Манифест
 *    записывается ПОСЛЕДНИМ — до этого момента для читателя действует предыдущая сборка целиком.
 * 3. **Имена без путей.** Имя файла проверяется белым списком символов: файлы карты адресуются из
 *    URL, и единственная защита от `../` — не пускать в имя ничего, кроме разрешённого.
 */
final class SitemapStorage
{
    /** Каталог артефактов. Регенерируемые данные — соседствуют с индексом поиска в `@runtime`. */
    public const string DIRECTORY = '@runtime/sitemap';

    /** Индекс карты — то, что отдаётся по `/sitemap.xml`. */
    public const string INDEX_FILE = 'sitemap.xml';

    /** Состояние сборки. */
    public const string MANIFEST_FILE = 'manifest.json';

    /** Дерево HTML-карты, готовое к выводу. */
    public const string HTML_FILE = 'map.json';

    private ?string $directory = null;

    private ?Manifest $manifest = null;

    private bool $manifestLoaded = false;

    /** Абсолютный путь к каталогу артефактов; каталог создаётся при первом обращении. */
    public function directory(): string
    {
        if ($this->directory === null) {
            $path = (string)Yii::getAlias(self::DIRECTORY);
            FileHelper::createDirectory($path);
            $this->directory = $path;
        }

        return $this->directory;
    }

    /** Абсолютный путь к файлу артефакта. */
    public function path(string $name): string
    {
        return $this->directory() . DIRECTORY_SEPARATOR . $this->assertName($name);
    }

    public function exists(string $name): bool
    {
        return is_file($this->path($name));
    }

    public function size(string $name): int
    {
        $path = $this->path($name);

        return is_file($path) ? (int)filesize($path) : 0;
    }

    /** Содержимое файла или null, если его нет. */
    public function read(string $name): ?string
    {
        $path = $this->path($name);

        if (!is_file($path)) {
            return null;
        }

        $content = file_get_contents($path);

        return $content === false ? null : $content;
    }

    /**
     * Начать потоковую запись файла: возвращает путь временного файла, в который пишет writer.
     *
     * @see commit() завершение записи
     */
    public function begin(string $name): string
    {
        return $this->path($name) . '.' . getmypid() . '.tmp';
    }

    /** Завершить потоковую запись: временный файл занимает место целевого. */
    public function commit(string $temporaryPath, string $name): void
    {
        $target = $this->path($name);

        if (!@rename($temporaryPath, $target)) {
            @unlink($temporaryPath);

            throw new RuntimeException("Не удалось записать файл карты сайта «{$name}».");
        }
    }

    /** Записать файл целиком, атомарно. */
    public function write(string $name, string $content): void
    {
        $temporary = $this->begin($name);

        if (file_put_contents($temporary, $content) === false) {
            throw new RuntimeException("Не удалось записать файл карты сайта «{$name}».");
        }

        $this->commit($temporary, $name);
    }

    /**
     * Удалить файлы разделов, не перечисленные в списке, и мусор от прерванных сборок.
     *
     * Нужно после сборки, в которой раздел исчез или уменьшился в числе частей: осиротевший
     * `sitemap-blog-post-3.xml` иначе остался бы доступен по прямой ссылке и продолжал бы водить
     * краулера по удалённым страницам.
     *
     * Заодно подчищаются временные файлы: сборка, прерванная на полпути (таймаут, фатал), оставляет
     * `*.tmp`, и никто, кроме следующей сборки, их не уберёт.
     *
     * Каталог обходится итератором, а не маской: `FilesystemIterator` читает записи лениво, не
     * собирая массив путей, а условие отбора остаётся обычным PHP-кодом — его видно и можно
     * расширить, тогда как glob-маска дополнительно приносит свой синтаксис (`[`, `?`, `{}`),
     * который в именах файлов означал бы не то, что кажется.
     *
     * Имена сначала собираются, и только потом удаляются: изменять каталог, по которому идёт
     * незавершённый обход, POSIX не запрещает, но и не обещает, что оставшиеся записи будут
     * прочитаны. Список имён одного каталога карты заведомо мал.
     *
     * @param list<string> $keep имена, которые оставить
     * @return int сколько файлов удалено
     */
    public function prune(array $keep): int
    {
        $keep = array_flip($keep);
        $doomed = [];

        $entries = new FilesystemIterator(
            $this->directory(),
            FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO,
        );

        /** @var SplFileInfo $entry */
        foreach ($entries as $entry) {
            if (!$entry->isFile()) {
                continue;
            }

            $name = $entry->getFilename();

            $isOrphanPart = str_starts_with($name, 'sitemap-')
                && str_ends_with($name, '.xml')
                && !isset($keep[$name]);

            if ($isOrphanPart || str_ends_with($name, '.tmp')) {
                $doomed[] = $entry->getPathname();
            }
        }

        $removed = 0;

        foreach ($doomed as $path) {
            if (@unlink($path)) {
                $removed++;
            }
        }

        return $removed;
    }

    /** Прочитанный манифест или null, если карта ещё не собиралась (или манифест испорчен). */
    public function manifest(): ?Manifest
    {
        if ($this->manifestLoaded) {
            return $this->manifest;
        }

        $this->manifestLoaded = true;
        $raw = $this->read(self::MANIFEST_FILE);

        if ($raw === null) {
            return $this->manifest = null;
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            // Испорченный манифест — это «карта не собрана»: следующая сборка перезапишет его.
            // Падать здесь нельзя, иначе один битый файл кладёт и фронт, и админку.
            Yii::warning('Манифест карты сайта повреждён: ' . $e->getMessage(), 'sitemap/storage');

            return $this->manifest = null;
        }

        return $this->manifest = is_array($data) ? Manifest::fromArray($data) : null;
    }

    public function saveManifest(Manifest $manifest): void
    {
        $this->write(
            self::MANIFEST_FILE,
            (string)json_encode($manifest->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        );

        $this->manifest = $manifest;
        $this->manifestLoaded = true;
    }

    /**
     * Дерево HTML-карты: массив разделов с адресами. Пустой массив — карта не собиралась.
     *
     * @return list<array<string, mixed>>
     */
    public function htmlMap(): array
    {
        $raw = $this->read(self::HTML_FILE);

        if ($raw === null) {
            return [];
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            Yii::warning('Дерево HTML-карты повреждено: ' . $e->getMessage(), 'sitemap/storage');

            return [];
        }

        return is_array($data) ? array_values($data) : [];
    }

    /**
     * @param list<array<string, mixed>> $sections
     */
    public function saveHtmlMap(array $sections): void
    {
        $this->write(
            self::HTML_FILE,
            (string)json_encode($sections, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
    }

    /** Забыть прочитанный манифест — после сборки, выполненной другим процессом. */
    public function forget(): void
    {
        $this->manifest = null;
        $this->manifestLoaded = false;
    }

    /**
     * Имя файла артефакта: только строчные буквы, цифры, дефис, точка и подчёркивание.
     *
     * Проверка нужна ровно потому, что имя приходит из URL (`/sitemap-blog-post-1.xml`), и её
     * отсутствие означало бы чтение произвольного файла на диске.
     */
    private function assertName(string $name): string
    {
        if (!preg_match('/^[a-z0-9._-]+$/', $name) || str_contains($name, '..')) {
            throw new RuntimeException("Недопустимое имя файла карты сайта: «{$name}».");
        }

        return $name;
    }
}
