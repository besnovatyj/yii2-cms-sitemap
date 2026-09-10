<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\Sitemap\settings;

use Besnovatyj\Contracts\sitemap\ChangeFrequency;

/**
 * Настройки карты сайта — готовые значения, ничего не вычисляющие и никуда не ходящие.
 *
 * Строки из админки уже разобраны, диапазоны применены: потребитель получает список, карту или
 * число и работает с ними, не зная ни про формат хранения, ни про модуль настроек. Собирает объект
 * {@see SitemapSettingsFactory}, а `params` ему передаёт composition root (`config/common.php`) —
 * поэтому здесь нет ни одного обращения к `Yii`, и класс можно создать в тесте одной строкой.
 */
final readonly class SitemapSettings
{
    /**
     * @param list<string>                     $disabledSections  Разделы, исключённые администратором.
     * @param array<string, float>             $priorities        Переопределённые приоритеты разделов.
     * @param array<string, ChangeFrequency>   $changeFrequencies Переопределённые частоты изменения.
     * @param list<ExtraUrl>                   $extraUrls         Адреса, вписанные вручную.
     * @param int                              $urlsPerFile       Предел адресов в одном файле карты.
     * @param int                              $ttlMinutes        Возраст, после которого карта пересобирается
     *                                                            сама; 0 — только вручную и по крону.
     * @param bool                             $htmlEnabled       Показывать ли карту `/sitemap` посетителю.
     * @param string                           $htmlVariant       Ключ варианта представления HTML-карты.
     * @param string                           $robotsFile        Путь (алиас) до `robots.txt` для диагностики;
     *                                                            пустая строка — проверка не выполняется.
     */
    public function __construct(
        public array $disabledSections,
        public array $priorities,
        public array $changeFrequencies,
        public array $extraUrls,
        public int $urlsPerFile,
        public int $ttlMinutes,
        public bool $htmlEnabled,
        public string $htmlVariant,
        public string $robotsFile,
    ) {
    }

    /**
     * Отпечаток настроек, влияющих на СОДЕРЖИМОЕ карты.
     *
     * Нужен переиспользованию файлов разделов: раздел, не изменившийся по данным, всё равно обязан
     * быть пересобран, если администратор поменял приоритет, частоту или размер файла. Сравнивать
     * настройки поштучно значило бы забыть одну из них при следующем расширении — отпечаток
     * автоматически покрывает все поля объекта.
     *
     * `htmlVariant` в отпечаток попадает тоже, хотя на содержимое не влияет: лишняя пересборка раз
     * в жизни дешевле, чем ещё одно исключение в правиле.
     */
    public function fingerprint(): string
    {
        return md5((string)json_encode(get_object_vars($this)));
    }
}
