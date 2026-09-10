<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\Sitemap\storage;

/**
 * Состояние одного раздела в собранной карте.
 *
 * Чистые данные: попадают в `manifest.json` и восстанавливаются из него. Служат трём вещам —
 * показать администратору, что и сколько собралось; проверить, можно ли переиспользовать файлы
 * раздела при следующей сборке; отдать краулеру ровно те файлы, которые перечислены в индексе
 * (список файлов заодно работает белым списком для контроллера).
 */
final readonly class SectionState
{
    /**
     * @param string       $key       Ключ раздела ({@see \Besnovatyj\Contracts\sitemap\SitemapSection::$key}).
     * @param string       $label     Подпись раздела на момент сборки.
     * @param string       $slug      Ключ, приведённый к виду для имени файла (`blog.post` → `blog-post`).
     * @param int          $urls      Сколько адресов собрано.
     * @param list<string> $files     Имена файлов раздела в порядке следования в индексе.
     * @param string|null  $revision  Отпечаток данных, если провайдер его сообщил
     *                                ({@see \Besnovatyj\Contracts\sitemap\SitemapFreshness}).
     * @param int          $bytes     Суммарный размер файлов раздела.
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $slug,
        public int $urls,
        public array $files,
        public ?string $revision = null,
        public int $bytes = 0,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'slug' => $this->slug,
            'urls' => $this->urls,
            'files' => $this->files,
            'revision' => $this->revision,
            'bytes' => $this->bytes,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            key: (string)($data['key'] ?? ''),
            label: (string)($data['label'] ?? ''),
            slug: (string)($data['slug'] ?? ''),
            urls: (int)($data['urls'] ?? 0),
            files: array_values(array_map('strval', (array)($data['files'] ?? []))),
            revision: isset($data['revision']) ? (string)$data['revision'] : null,
            bytes: (int)($data['bytes'] ?? 0),
        );
    }
}
