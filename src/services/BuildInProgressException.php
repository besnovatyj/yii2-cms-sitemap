<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\Sitemap\services;

use RuntimeException;

/**
 * Сборку карты уже ведёт другой процесс.
 *
 * Отдельный тип, а не общая ошибка: это штатная ситуация (крон и кнопка в админке совпали во
 * времени), и обрабатывать её надо иначе, чем настоящий сбой — без записи в журнал ошибок и без
 * пугающего сообщения. Кто ждать не может, тот отдаёт прошлую карту.
 */
final class BuildInProgressException extends RuntimeException
{
}
