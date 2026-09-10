<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

use yii\helpers\Html;
use yii\web\View;

/**
 * Базовая вью человеческой карты сайта (пакетный фолбэк; тема может переопределить или предложить
 * вариант в каталоге `index.variants/`).
 *
 * Ничего не досчитывает и никуда не ходит: дерево пришло из артефакта сборки готовым. Вложенность
 * передана числом (`depth`), а не вложенными списками — так провайдеру не нужно строить дерево, а
 * представлению остаётся один отступ.
 *
 * @var View                       $this
 * @var list<array<string, mixed>> $sections
 * @var int|null                   $builtAt
 */

/**
 * Отступ по глубине — штатными утилитами Bootstrap, без своих правил и без переопределения классов
 * фреймворка. Шкала обрывается на четвёртом уровне намеренно: карта сайта, ушедшая глубже,
 * перестаёт быть оглавлением, и дальнейший отступ только съедает ширину колонки.
 */
$indent = ['', 'ms-3', 'ms-4', 'ms-5', 'ms-5'];
?>
<div class="container py-4">
    <h1 class="h3 mb-4"><?= Html::encode($this->title) ?></h1>

    <?php if ($sections === []): ?>
        <p class="text-muted">Карта сайта пока пуста.</p>
    <?php else: ?>
        <div class="row">
            <?php foreach ($sections as $section): ?>
                <?php $items = (array)($section['items'] ?? []); ?>
                <?php if ($items === []) { continue; } ?>
                <section class="col-12 col-lg-6 mb-4">
                    <h2 class="h5 mb-3">
                        <?php if (($section['icon'] ?? null) !== null): ?>
                            <i class="<?= Html::encode((string)$section['icon']) ?> me-1" aria-hidden="true"></i>
                        <?php endif; ?>
                        <?= Html::encode((string)($section['label'] ?? '')) ?>
                    </h2>
                    <ul class="list-unstyled mb-0">
                        <?php foreach ($items as $item): ?>
                            <?php $depth = min(4, max(0, (int)($item['depth'] ?? 0))); ?>
                            <li class="mb-1 <?= $indent[$depth] ?>">
                                <?= Html::a(
                                    Html::encode((string)($item['title'] ?? '')),
                                    (string)($item['url'] ?? '#'),
                                ) ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </section>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($builtAt !== null): ?>
        <p class="text-muted small mb-0">
            Обновлено <?= Yii::$app->formatter->asDatetime($builtAt) ?>
        </p>
    <?php endif; ?>
</div>
