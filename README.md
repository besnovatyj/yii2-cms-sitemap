# besnovatyj/yii2-cms-sitemap

Карта сайта для Yii2 CMS: XML-карта для поисковых систем (`/sitemap.xml` + файлы разделов) и
человеческая карта `/sitemap`.

Модуль **не знает ни одного контентного модуля поимённо**. Адреса приходят от модулей, которые
реализовали нейтральный контракт `Besnovatyj\Contracts\sitemap\SitemapProvider`, — тем же приёмом,
что источники сквозного поиска и цели меню. Выключили модуль в менеджере — его раздел исчез из
карты сам, без правок здесь.

## Что делает

- собирает разделы модулей в XML-карту, режет по лимитам протокола (50 000 адресов / 50 МБ на файл)
  и пишет индекс;
- из того же прохода строит дерево HTML-карты — XML и HTML не могут разойтись;
- строит адреса через `frontendUrlManager`, поэтому короткие URL модуля алиасов учитываются
  автоматически;
- хранит артефакты в `@runtime/sitemap` (вне корня сайта) и отдаёт их без единого запроса к базе;
- проверяет, объявлена ли карта в `robots.txt`, и показывает готовую строку.

Миграций и таблиц у модуля нет: всё состояние — файлы, которые полностью восстанавливаются одной
командой.

## Как подключить модуль к карте

```php
use Besnovatyj\Contracts\sitemap\ChangeFrequency;
use Besnovatyj\Contracts\sitemap\SitemapProvider;
use Besnovatyj\Contracts\sitemap\SitemapSection;
use Besnovatyj\Contracts\sitemap\SitemapUrl;

class Module extends CmsModule implements SitemapProvider
{
    public function sitemapSections(): array
    {
        return [
            new SitemapSection(
                key: 'shop.product',      // стабильный ключ: попадает в имя файла карты
                label: 'Товары',
                changeFrequency: ChangeFrequency::Daily,
                priority: 0.7,
                icon: 'bi bi-box-seam',
            ),
        ];
    }

    public function sitemapUrls(string $section): iterable
    {
        return match ($section) {
            'shop.product' => (new ProductReadRepository())->sitemapUrls(),
            default => [],   // неизвестный ключ — пустой итератор, не исключение
        };
    }
}
```

А сам поток адресов — генератором, из `readModels` (только публичное!):

```php
public function sitemapUrls(): iterable
{
    foreach (Product::find()->visible()->each(200) as $product) {
        yield new SitemapUrl(
            route: '/Shop/product/view',          // роут, а НЕ готовый URL
            params: ['slug' => $product->slug],
            title: $product->name,                // нужен человеческой карте
            lastModified: strtotime($product->updated_at) ?: null,
        );
    }
}
```

Тяжёлому разделу стоит добавить `SitemapFreshness` — тогда неизменившийся раздел не перечитывается:

```php
public function sitemapRevision(string $section): ?string
{
    $row = Product::find()->visible()->select(['n' => 'COUNT(*)', 'm' => 'MAX(updated_at)'])->asArray()->one();

    return $row['n'] . ':' . $row['m'];   // именно пара: MAX(updated_at) не замечает удаления
}
```

## Эксплуатация

```
php yii Sitemap/build/run      # собрать заново (крон, деплой)
php yii Sitemap/build/status   # что собрано и когда
```

На боевом сервере — от пользователя веб-сервера: `sudo -u www-data php yii Sitemap/build/run`.
Иначе процесс не прочитает секреты базы, а созданные им файлы окажутся недоступны на запись
веб-процессу.

Если крон не настроен, карта чинит себя сама: устаревшую (старше `ttl`) или отсутствующую
пересоберёт первый же запрос — под замком, поэтому одновременных сборок не бывает.

В `robots.txt` карту нужно объявить руками — модуль не правит файл скелета приложения, но
показывает готовую строку на своей странице в админке:

```
Sitemap: https://example.test/sitemap.xml
```

## Настройки

Раздел «Sitemap» в настройках приложения: исключённые разделы, переопределение приоритетов и
частоты, дополнительные адреса (главная страница объявляется там), число адресов в файле, срок
годности карты, показ человеческой карты и вариант её оформления из активной темы.
