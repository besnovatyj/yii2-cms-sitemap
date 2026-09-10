<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

use Besnovatyj\Contracts\sitemap\SitemapSection;
use Besnovatyj\Sitemap\services\RobotsCheck;
use Besnovatyj\Sitemap\settings\SitemapSettings;
use Besnovatyj\Sitemap\storage\Manifest;
use yii\helpers\Html;
use yii\web\View;

/**
 * Состояние карты сайта.
 *
 * @var View                          $this
 * @var Manifest|null                 $manifest
 * @var bool                          $stale
 * @var array<string, SitemapSection> $sections
 * @var array<string, SitemapSection> $disabled
 * @var RobotsCheck                   $robots
 * @var SitemapSettings               $settings
 * @var string                        $indexUrl
 * @var string                        $mapUrl
 */

$this->title = 'Карта сайта';
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="sitemap-status">
    <h1 class="h4 mb-3"><?= Html::encode($this->title) ?></h1>

    <?php if ($sections === []): ?>
        <div class="alert alert-warning">
            Ни один модуль не объявил разделов карты. В карту попадут только адреса, вписанные
            вручную в настройках («Дополнительные адреса»). Чтобы раздел появился здесь, его модуль
            должен реализовать контракт <code>SitemapProvider</code>.
        </div>
    <?php endif; ?>

    <?php if ($manifest === null): ?>
        <div class="alert alert-danger">
            Карта ещё не собиралась — по адресу <code><?= Html::encode($indexUrl) ?></code> сейчас
            ничего нет. Нажмите «Собрать заново» или выполните
            <code>php yii Sitemap/build/run</code>.
        </div>
    <?php elseif ($stale): ?>
        <div class="alert alert-warning">
            Карта старше заданного срока годности и будет пересобрана при первом обращении.
        </div>
    <?php endif; ?>

    <?php if ($robots->needsAttention()): ?>
        <div class="alert alert-warning">
            <?php if ($robots->exists): ?>
                В <code><?= Html::encode($robots->path) ?></code> нет строки с адресом карты.
            <?php else: ?>
                Файл <code><?= Html::encode($robots->path) ?></code> не найден.
            <?php endif; ?>
            Поисковые системы находят карту прежде всего по ней — добавьте строку:
            <pre class="mb-0 mt-2"><?= Html::encode($robots->expectedLine) ?></pre>
        </div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header">Состояние</div>
                <table class="table table-sm mb-0">
                    <tbody>
                        <tr>
                            <th style="width: 45%">Собрана</th>
                            <td>
                                <?= $manifest === null
                                    ? 'никогда'
                                    : Yii::$app->formatter->asDatetime($manifest->builtAt) ?>
                            </td>
                        </tr>
                        <tr>
                            <th>Адресов в XML-карте</th>
                            <td><?= $manifest?->urls ?? 0 ?></td>
                        </tr>
                        <tr>
                            <th>Адресов в HTML-карте</th>
                            <td>
                                <?php if (!$settings->htmlEnabled): ?>
                                    <span class="text-muted">страница отключена</span>
                                <?php else: ?>
                                    <?= $manifest?->htmlUrls ?? 0 ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th>Длительность сборки</th>
                            <td><?= $manifest === null ? '—' : $manifest->seconds . ' с' ?></td>
                        </tr>
                        <tr>
                            <th>Срок годности</th>
                            <td>
                                <?= $settings->ttlMinutes === 0
                                    ? 'не пересобирать по времени'
                                    : $settings->ttlMinutes . ' мин' ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header">Адреса</div>
                <div class="card-body">
                    <p class="mb-2">
                        XML-карта: <?= Html::a(Html::encode($indexUrl), $indexUrl, ['target' => '_blank', 'rel' => 'noopener']) ?>
                    </p>
                    <p class="mb-3">
                        Карта для посетителей:
                        <?php if ($settings->htmlEnabled): ?>
                            <?= Html::a(Html::encode($mapUrl), $mapUrl, ['target' => '_blank', 'rel' => 'noopener']) ?>
                        <?php else: ?>
                            <span class="text-muted">отключена в настройках</span>
                        <?php endif; ?>
                    </p>

                    <?= Html::beginForm(['build'], 'post') ?>
                        <?= Html::submitButton(
                            '<i class="bi bi-arrow-repeat me-1"></i>Собрать заново',
                            ['class' => 'btn btn-primary'],
                        ) ?>
                    <?= Html::endForm() ?>

                    <p class="text-muted small mt-3 mb-0">
                        На боевом сервере карту собирает расписание:
                        <code>sudo -u www-data php yii Sitemap/build/run</code>
                    </p>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header">Разделы</div>
        <table class="table table-sm mb-0 align-middle">
            <thead>
                <tr>
                    <th>Ключ</th>
                    <th>Раздел</th>
                    <th class="text-end">Приоритет</th>
                    <th>Частота</th>
                    <th class="text-end">Адресов</th>
                    <th class="text-end">Файлов</th>
                    <th class="text-end">Размер</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($sections as $key => $section): ?>
                    <?php $state = $manifest?->sections[$key] ?? null; ?>
                    <tr>
                        <td><code><?= Html::encode($key) ?></code></td>
                        <td>
                            <?php if ($section->icon !== null): ?>
                                <i class="<?= Html::encode($section->icon) ?> me-1" aria-hidden="true"></i>
                            <?php endif; ?>
                            <?= Html::encode($section->label) ?>
                            <?php if (!$section->inHtmlMap): ?>
                                <span class="badge text-bg-light" title="Раздел не показывается на HTML-карте">только XML</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end"><?= number_format($section->priority, 1, '.', '') ?></td>
                        <td><?= Html::encode($section->changeFrequency->value) ?></td>
                        <td class="text-end">
                            <?= $state === null
                                ? '<span class="text-muted">—</span>'
                                : $state->urls ?>
                        </td>
                        <td class="text-end"><?= $state === null ? '—' : count($state->files) ?></td>
                        <td class="text-end">
                            <?= $state === null ? '—' : Yii::$app->formatter->asShortSize($state->bytes) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php foreach ($manifest?->sections ?? [] as $key => $state): ?>
                    <?php if (isset($sections[$key]) || isset($disabled[$key])) { continue; } ?>
                    <tr class="table-light">
                        <td><code><?= Html::encode($key) ?></code></td>
                        <td>
                            <?= Html::encode($state->label) ?>
                            <span class="badge text-bg-secondary">служебный</span>
                        </td>
                        <td class="text-end">—</td>
                        <td>—</td>
                        <td class="text-end"><?= $state->urls ?></td>
                        <td class="text-end"><?= count($state->files) ?></td>
                        <td class="text-end"><?= Yii::$app->formatter->asShortSize($state->bytes) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($disabled !== []): ?>
        <div class="card mb-4">
            <div class="card-header">Исключены администратором</div>
            <div class="card-body">
                <p class="text-muted small mb-2">
                    Заданы в настройках приложения, раздел «Sitemap» → «Исключённые разделы».
                </p>
                <ul class="mb-0">
                    <?php foreach ($disabled as $key => $section): ?>
                        <li><code><?= Html::encode($key) ?></code> — <?= Html::encode($section->label) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    <?php endif; ?>
</div>
