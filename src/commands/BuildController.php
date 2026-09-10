<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\Sitemap\commands;

use Besnovatyj\Sitemap\services\BuildInProgressException;
use Besnovatyj\Sitemap\services\RobotsInspector;
use Besnovatyj\Sitemap\services\SectionRegistry;
use Besnovatyj\Sitemap\services\SitemapBuilder;
use Besnovatyj\Sitemap\storage\SitemapStorage;
use Throwable;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Консольная сборка карты сайта.
 *
 *  - `php yii Sitemap/build/run`    — собрать карту заново (для крона и деплоя)
 *  - `php yii Sitemap/build/status` — что собрано и когда
 *
 * Консоль — основной способ сборки на боевом сервере: нет лимита времени веб-сервера и не занимается
 * воркер PHP-FPM. Команду следует запускать от пользователя веб-сервера
 * (`sudo -u www-data php yii ...`) — иначе процесс не прочитает секреты подключения к базе, а
 * созданные им файлы карты окажутся недоступны на запись веб-процессу.
 *
 * Живёт в пакете (namespace `commands` модуля {@see \Besnovatyj\Kernel\module\CmsModule}), а не в
 * скелете приложения: команда — поведение модуля, и уезжает вместе с ним. Адресуется по той же
 * конвенции, что `Modman/modules/recompile` и `Themes/cache/flush`, без `controllerMap`.
 */
final class BuildController extends Controller
{
    public function __construct(
        $id,
        $module,
        private readonly SitemapBuilder $builder,
        private readonly SitemapStorage $storage,
        private readonly SectionRegistry $registry,
        private readonly RobotsInspector $robots,
        $config = [],
    ) {
        parent::__construct($id, $module, $config);
    }

    /**
     * Собрать карту заново.
     */
    public function actionRun(): int
    {
        $this->stdout("Сборка карты сайта\n", Console::BOLD);

        try {
            $report = $this->builder->build(function (string $message): void {
                $this->stdout($message . "\n");
            });
        } catch (BuildInProgressException $e) {
            // Штатное совпадение: карту прямо сейчас собирает админка или соседний запуск крона.
            // Для планировщика это не повод слать письмо об ошибке, но и не успех.
            $this->stderr($e->getMessage() . "\n", Console::FG_YELLOW);

            return ExitCode::TEMPFAIL;
        } catch (Throwable $e) {
            $this->stderr('Ошибка: ' . $e->getMessage() . "\n", Console::FG_RED);

            return ExitCode::UNAVAILABLE;
        }

        $this->stdout(sprintf(
            "Готово: %d адресов в XML-карте, %d в HTML-карте, %s с. Удалено устаревших файлов: %d.\n",
            $report->urls,
            $report->htmlUrls,
            $report->seconds,
            $report->removed,
        ), Console::FG_GREEN);

        $failed = $report->failed();

        if ($failed !== []) {
            foreach ($failed as $section) {
                $this->stderr("Раздел «{$section->label}» не собран: {$section->error}\n", Console::FG_RED);
            }

            // Карта записана, но неполная — код возврата обязан это показать, иначе крон
            // будет годами «успешно» собирать карту без половины разделов.
            return ExitCode::DATAERR;
        }

        $this->reportRobots();

        return ExitCode::OK;
    }

    /**
     * Показать состояние карты и объявленные разделы.
     */
    public function actionStatus(): int
    {
        $manifest = $this->storage->manifest();

        if ($manifest === null) {
            $this->stdout("Карта ещё не собрана. Запустите: php yii Sitemap/build/run\n", Console::FG_YELLOW);
        } else {
            $this->stdout(sprintf(
                "Собрана: %s, адресов: %d, разделов: %d, домен: %s\n",
                date('Y-m-d H:i:s', $manifest->builtAt),
                $manifest->urls,
                count($manifest->sections),
                $manifest->host,
            ));

            foreach ($manifest->sections as $state) {
                $this->stdout(sprintf(
                    "  %-28s %6d адресов, файлов: %d\n",
                    $state->key,
                    $state->urls,
                    count($state->files),
                ));
            }
        }

        $declared = array_keys($this->registry->allSections());
        $disabled = array_keys($this->registry->disabledSections());

        $this->stdout("\nОбъявлено разделов: " . count($declared) . "\n");

        if ($declared === []) {
            $this->stdout(
                "Ни один модуль не реализует SitemapProvider — в карту попадут только дополнительные адреса.\n",
                Console::FG_YELLOW,
            );
        }

        if ($disabled !== []) {
            $this->stdout('Исключено администратором: ' . implode(', ', $disabled) . "\n", Console::FG_YELLOW);
        }

        $this->reportRobots();

        return ExitCode::OK;
    }

    /**
     * Напомнить про строку `Sitemap:` в `robots.txt`: собранная, но не объявленная карта —
     * самая частая причина «поисковик её не видит».
     */
    private function reportRobots(): void
    {
        $check = $this->robots->check();

        if (!$check->needsAttention()) {
            return;
        }

        $this->stdout(
            $check->exists
                ? "\nВ robots.txt нет строки карты. Добавьте в {$check->path}:\n  {$check->expectedLine}\n"
                : "\nФайл robots.txt не найден ({$check->path}). Карту стоит объявить строкой:\n  {$check->expectedLine}\n",
            Console::FG_YELLOW,
        );
    }
}
