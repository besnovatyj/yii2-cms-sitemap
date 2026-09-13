<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\Sitemap\services;

use Besnovatyj\Contracts\sitemap\SitemapFreshness;
use Besnovatyj\Contracts\sitemap\SitemapProvider;
use Besnovatyj\Contracts\sitemap\SitemapSection;
use Besnovatyj\Kernel\module\ModuleFinder;
use Besnovatyj\Sitemap\settings\SitemapSettings;
use Throwable;
use Yii;

/**
 * Реестр разделов карты: находит модули-провайдеры и сводит их объявления в один список.
 *
 * Обход зарегистрированных модулей по контракту ({@see ModuleFinder}) — тот же приём, что в
 * {@see \Besnovatyj\Search\services\SourceRegistry} и реестре целей меню: модуль карты не знает
 * имён контентных модулей, а они не знают о нём; инстанцируются только модули-провайдеры. Отключённый в modman модуль в конфиг приложения
 * не попадает, поэтому и в реестре не появится — отдельной проверки активности не нужно.
 *
 * Поверх объявлений накладываются настройки администратора: выключённые разделы исчезают из карты,
 * приоритет и частота переопределяются. Дальше по коду разделы ходят уже готовыми — сборщик не
 * знает, что часть значений пришла не от модуля.
 */
final class SectionRegistry
{
    /** @var array<string, SitemapProvider>|null */
    private ?array $providers = null;

    /** @var array<string, SitemapSection>|null */
    private ?array $sections = null;

    /** @var array<string, string> карта «ключ раздела => id модуля-провайдера» */
    private array $owners = [];

    public function __construct(private readonly SitemapSettings $settings)
    {
    }

    /**
     * Модули, объявившие себя провайдерами карты. Ключ — id модуля.
     *
     * @return array<string, SitemapProvider>
     */
    public function providers(): array
    {
        if ($this->providers !== null) {
            return $this->providers;
        }

        return $this->providers = ModuleFinder::implementing(SitemapProvider::class);
    }

    /**
     * Все объявленные разделы (включая отключённые администратором) с наложенными
     * переопределениями, отсортированные по порядку вывода. Ключ — ключ раздела.
     *
     * @return array<string, SitemapSection>
     */
    public function allSections(): array
    {
        if ($this->sections !== null) {
            return $this->sections;
        }

        $sections = [];
        $owners = [];

        foreach ($this->providers() as $moduleId => $provider) {
            foreach ($provider->sitemapSections() as $section) {
                if (isset($sections[$section->key])) {
                    Yii::warning(
                        "Раздел карты сайта «{$section->key}» объявлен дважды: модулями "
                        . "«{$owners[$section->key]}» и «{$moduleId}». Взят первый.",
                        'sitemap/registry',
                    );
                    continue;
                }

                $sections[$section->key] = $this->applyOverrides($section);
                $owners[$section->key] = (string)$moduleId;
            }
        }

        uasort(
            $sections,
            static fn (SitemapSection $a, SitemapSection $b): int => [$a->order, $a->label] <=> [$b->order, $b->label],
        );

        $this->owners = $owners;

        return $this->sections = $sections;
    }

    /**
     * Разделы, попадающие в карту сейчас.
     *
     * @return array<string, SitemapSection>
     */
    public function enabledSections(): array
    {
        $disabled = array_flip($this->settings->disabledSections);

        return array_filter(
            $this->allSections(),
            static fn (string $key): bool => !isset($disabled[$key]),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * Разделы, исключённые администратором — для страницы состояния.
     *
     * @return array<string, SitemapSection>
     */
    public function disabledSections(): array
    {
        return array_diff_key($this->allSections(), $this->enabledSections());
    }

    /** Модуль-владелец раздела, либо null для неизвестного ключа. */
    public function providerFor(string $key): ?SitemapProvider
    {
        $this->allSections();
        $moduleId = $this->owners[$key] ?? null;

        return $moduleId === null ? null : ($this->providers()[$moduleId] ?? null);
    }

    /** Id модуля-владельца раздела — нужен только для сообщений об ошибках. */
    public function ownerOf(string $key): string
    {
        $this->allSections();

        return $this->owners[$key] ?? '?';
    }

    /**
     * Отпечаток состояния раздела, если провайдер его сообщает.
     *
     * Провайдер не обязан реализовывать {@see SitemapFreshness}; без него раздел пересобирается
     * всегда. Исключение из чужого кода гасится: неудачная проверка обязана приводить к пересборке,
     * а не к падению всей карты.
     */
    public function revision(string $key): ?string
    {
        $provider = $this->providerFor($key);

        if (!$provider instanceof SitemapFreshness) {
            return null;
        }

        try {
            return $provider->sitemapRevision($key);
        } catch (Throwable $e) {
            Yii::warning(
                "Раздел «{$key}»: отпечаток не получен ({$e->getMessage()}), раздел будет пересобран.",
                'sitemap/registry',
            );

            return null;
        }
    }

    /**
     * Наложение настроек администратора на объявление модуля.
     *
     * Раздел уезжает дальше уже готовым — так ни сборщик, ни страница состояния не обязаны знать
     * о существовании переопределений и не могут забыть их применить.
     */
    private function applyOverrides(SitemapSection $section): SitemapSection
    {
        $priority = $this->settings->priorities[$section->key] ?? $section->priority;
        $frequency = $this->settings->changeFrequencies[$section->key] ?? $section->changeFrequency;

        if ($priority === $section->priority && $frequency === $section->changeFrequency) {
            return $section;
        }

        return new SitemapSection(
            key: $section->key,
            label: $section->label,
            changeFrequency: $frequency,
            priority: $priority,
            inHtmlMap: $section->inHtmlMap,
            order: $section->order,
            icon: $section->icon,
        );
    }
}
