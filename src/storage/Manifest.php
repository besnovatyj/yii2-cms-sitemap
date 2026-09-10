<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\Sitemap\storage;

/**
 * Состояние собранной карты — то, что раньше было бы таблицей в базе.
 *
 * Таблицы у модуля нет намеренно: карта целиком регенерируется одной командой, поэтому её состояние
 * — такой же расходный артефакт, как и сами файлы, и должно лежать рядом с ними, а не в базе, где
 * его пришлось бы синхронизировать с файловой системой.
 *
 * Формат — JSON, а НЕ `var_export` в `.php`, как у артефактов менеджера модулей. Причина
 * эксплуатационная: на боевом сервере `opcache.validate_timestamps=0`, и PHP-файл, перезаписанный
 * консолью (карту собирает крон), веб-процесс продолжил бы читать старым до перезагрузки FPM. JSON
 * читается мимо OPcache и виден сразу.
 */
final readonly class Manifest
{
    /** Версия формата: при несовместимом изменении старый манифест игнорируется, карта пересобирается. */
    public const int VERSION = 1;

    /**
     * @param int                        $builtAt     Когда собрана, Unix-timestamp.
     * @param float                      $seconds     Сколько заняла сборка.
     * @param int                        $urls        Всего адресов в XML-карте.
     * @param string                     $fingerprint Отпечаток настроек ({@see \Besnovatyj\Sitemap\settings\SitemapSettings::fingerprint()}).
     * @param string                     $host        `hostInfo` фронтенда на момент сборки: смена домена
     *                                                делает все записанные адреса неверными.
     * @param array<string, SectionState> $sections   Состояние разделов, ключ — ключ раздела.
     * @param int                        $htmlUrls    Сколько адресов попало в HTML-карту.
     */
    public function __construct(
        public int $builtAt,
        public float $seconds,
        public int $urls,
        public string $fingerprint,
        public string $host,
        public array $sections,
        public int $htmlUrls = 0,
    ) {
    }

    /**
     * Все файлы разделов, перечисленные в индексе. Служит белым списком для контроллера и
     * списком «что оставить» при удалении осиротевших файлов.
     *
     * @return list<string>
     */
    public function files(): array
    {
        $files = [];

        foreach ($this->sections as $section) {
            foreach ($section->files as $file) {
                $files[] = $file;
            }
        }

        return $files;
    }

    /** Возраст карты в секундах. */
    public function age(): int
    {
        return max(0, time() - $this->builtAt);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'version' => self::VERSION,
            'builtAt' => $this->builtAt,
            'seconds' => $this->seconds,
            'urls' => $this->urls,
            'fingerprint' => $this->fingerprint,
            'host' => $this->host,
            'htmlUrls' => $this->htmlUrls,
            'sections' => array_map(
                static fn (SectionState $state): array => $state->toArray(),
                $this->sections,
            ),
        ];
    }

    /**
     * Восстановление из JSON-массива. Манифест чужой версии считается отсутствующим —
     * так обновление формата не требует ни миграции, ни ручной чистки: карта просто пересоберётся.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        if ((int)($data['version'] ?? 0) !== self::VERSION) {
            return null;
        }

        $sections = [];
        foreach ((array)($data['sections'] ?? []) as $key => $row) {
            $sections[(string)$key] = SectionState::fromArray((array)$row);
        }

        return new self(
            builtAt: (int)($data['builtAt'] ?? 0),
            seconds: (float)($data['seconds'] ?? 0),
            urls: (int)($data['urls'] ?? 0),
            fingerprint: (string)($data['fingerprint'] ?? ''),
            host: (string)($data['host'] ?? ''),
            sections: $sections,
            htmlUrls: (int)($data['htmlUrls'] ?? 0),
        );
    }
}
