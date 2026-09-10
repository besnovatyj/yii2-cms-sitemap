<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\Sitemap\settings;

use Besnovatyj\Contracts\sitemap\ChangeFrequency;

/**
 * Сборка {@see SitemapSettings} из `params` модуля.
 *
 * Единственное место, где знают, как называются параметры и какие у них дефолты и границы.
 * Дефолты дублируют `config/config.php` намеренно: конфиг модуля — то, что видит и правит
 * администратор, а эти значения — страховка на случай, когда модуль настроек ещё ничего не
 * применил (первый запуск, сброс настроек, вызов из теста).
 */
final class SitemapSettingsFactory
{
    /**
     * @param array<string, mixed> $params `params` модуля карты сайта
     */
    public function create(array $params): SitemapSettings
    {
        $reader = new ParamReader($params);

        return new SitemapSettings(
            disabledSections: $reader->list('disabledSections'),
            priorities: $this->clampPriorities($reader->floatMap('priorities')),
            changeFrequencies: $this->parseFrequencies($reader->stringMap('changeFrequencies')),
            extraUrls: $this->parseExtraUrls($reader->lines('extraUrls')),
            urlsPerFile: $reader->int('urlsPerFile', 20000, min: 100, max: 50000),
            ttlMinutes: $reader->int('ttl', 1440, min: 0, max: 43200),
            htmlEnabled: $reader->bool('htmlEnabled', true),
            htmlVariant: $reader->string('htmlVariant'),
            robotsFile: $reader->string('robotsFile'),
        );
    }

    /**
     * Приоритет вне диапазона 0.0…1.0 делает элемент `<priority>` невалидным, поэтому значение
     * зажимается, а не отбрасывается: администратор, написавший «2», имел в виду «повыше».
     *
     * @param array<string, float> $values
     * @return array<string, float>
     */
    private function clampPriorities(array $values): array
    {
        return array_map(
            static fn (float $value): float => max(0.0, min(1.0, $value)),
            $values,
        );
    }

    /**
     * @param array<string, string> $values
     * @return array<string, ChangeFrequency>
     */
    private function parseFrequencies(array $values): array
    {
        $result = [];

        foreach ($values as $section => $value) {
            // Опечатку в названии частоты молча пропускаем: раздел получит своё умолчание,
            // а невалидный `<changefreq>` обесценил бы карту целиком.
            $frequency = ChangeFrequency::parse($value);

            if ($frequency !== null) {
                $result[$section] = $frequency;
            }
        }

        return $result;
    }

    /**
     * @param list<string> $lines
     * @return list<ExtraUrl>
     */
    private function parseExtraUrls(array $lines): array
    {
        $urls = [];

        foreach ($lines as $line) {
            $url = ExtraUrl::parse($line);

            if ($url !== null) {
                $urls[] = $url;
            }
        }

        return $urls;
    }
}
