# IndexNow из командной строки — `indexnowkit/cli`

Сообщайте Яндексу, Bing, Naver и Seznam, какие URL изменились, с любого хоста, где есть PHP, и из любого CI — без
фреймворка. Один бинарник `indexnow`: проверить ключ-файл, отправить URL или весь sitemap (только новое или
изменившееся с прошлого прогона), вести историю. Cron на Битриксе, WordPress, MODX, OpenCart, Joomla; деплой Hugo,
Astro, Jekyll; «просто отправить десять URL». Три упаковки одного и того же: Composer-пакет, `indexnow.phar`,
Docker-образ `ghcr.io/indexnowkit/indexnow` — и GitHub Action поверх образа.

[English](README.md) · Вопросы и pull request'ы: [github.com/indexnowkit/php](https://github.com/indexnowkit/php/issues) (репозитории `php-*` — read-only сплиты)

## Кто получит уведомление

**Яндекс, Bing (и DuckDuckGo через Bing), Naver, Seznam, Yep, Internet Archive, Amazon** — все движки
[реестра IndexNow](https://www.indexnow.org/searchengines.json). Один запрос на общий endpoint доходит до всех;
`INDEXNOW_ENGINES=yandex,bing` — только когда нужен один.

**Google: нет.** Google не поддерживает IndexNow. IndexNow — уведомление, не индексация: сканировать ли и когда, решает движок.

## Установка

```bash
composer global require indexnowkit/cli
curl -LO https://github.com/indexnowkit/php-cli/releases/latest/download/indexnow.phar && php indexnow.phar --version
docker run --rm ghcr.io/indexnowkit/indexnow --version
```

PHP 8.2+ с `pdo_sqlite` и `xmlreader` (PHAR проверяет это до запуска; без `pdo_sqlite` — `--state memory`, без файла состояния).

## Быстрый старт

```bash
cd /var/www/site                                   # .env и файл состояния живут в рабочем каталоге
indexnow key:generate --write-env                  # INDEXNOW_KEY=… в .env (права 0600)
echo 'INDEXNOW_BASE_URL=https://www.example.com' >> .env
indexnow key:file /var/www/site/public             # public/<key>.txt — файл, по которому движки проверяют ключ
indexnow check --live                              # конфигурация, ключ-файл по HTTP, по одному пробному запросу на движок
indexnow sitemap                                   # весь sitemap один раз…
```

…дальше одна строка в crontab — только то, что изменилось с прошлого прогона, что бы ни говорил `<lastmod>`:

```cron
*/30 * * * *  cd /var/www/site && indexnow sitemap --new-only --json >> /var/log/indexnow.log 2>&1
```

Десять URL руками: `indexnow submit https://www.example.com/a /b /c`.

## Любая CMS: Битрикс, WordPress, MODX, OpenCart, Joomla

CLI нужны две вещи, которые есть у любого сайта: document root (для `key:file`) и sitemap. Битрикс генерирует
`sitemap.xml` и индекс по инфоблокам штатным модулем «Поисковая оптимизация» (с версии 14); WordPress отдаёт
`/wp-sitemap.xml` (5.5+) или sitemap SEO-плагина; MODX (`pdoTools`, `SEO Suite`), OpenCart, Joomla — так же.
`INDEXNOW_SITEMAP_URL`, когда он не `<base_url>/sitemap.xml`; или файл с диска: `indexnow sitemap /var/www/site/public/sitemap.xml`.
PHP самой CMS не запускается: CLI — отдельный процесс на том же хосте.

## Статика: на деплое

GitHub Action `indexnowkit/indexnow-action` запускает `check`, затем `sitemap --new-only` из образа; файл состояния
лежит в `.indexnow/` и кэшируется между прогонами:

```yaml
- uses: actions/cache@v4
  with: { path: .indexnow, key: indexnow-${{ github.ref_name }} }
- uses: indexnowkit/indexnow-action@v1
  with:
    key: ${{ secrets.INDEXNOW_KEY }}
    base-url: https://www.example.com
    sitemap: dist/sitemap.xml
    new-only: 'true'
```

Входы, выходы, summary шага: [docs/action.md](docs/action.md). Любой другой CI запускает образ так же:
`docker run --rm -v "$PWD:/work" -e INDEXNOW_KEY -e INDEXNOW_BASE_URL ghcr.io/indexnowkit/indexnow sitemap --new-only`
([docs/docker.md](docs/docker.md)).

## Файл состояния

`.indexnow/state.sqlite` в рабочем каталоге (`--state`, `INDEXNOW_STATE`), один sqlite-файл, создаётся при первом
обращении в каталоге `0700`: окно дебаунса (`debounce.per_url`, 10 минут; `--force` обходит), счётчики 403 по
хостам, история отправок (`history`, `status`) и отпечатки уже объявленных URL sitemap (`sitemap --new-only`). Всё,
что делает прогоны по расписанию идемпотентными, в одном файле; бэкап не нужен (потеря стоит одного полного
прогона). `--state memory` не хранит ничего между прогонами. Подробности: [docs/state.md](docs/state.md).

## Конфигурация

Сначала окружение, потом файл. Каждая опция ядра и трёх пакетов — одна переменная: `INDEXNOW_<KEY>` для ядра
(`INDEXNOW_KEY`, `INDEXNOW_BASE_URL`, `INDEXNOW_ENGINES`, …), `INDEXNOW_<BLOCK>_<KEY>` для блоков
(`INDEXNOW_SITEMAP_URL`, `INDEXNOW_VERIFY_ENABLED`, `INDEXNOW_HISTORY_PDO_DSN`). `.env` в рабочем каталоге читается,
если есть (`--env-file`, `--no-env-file`); реальное окружение выигрывает у файла. Для того, что не одно значение
(карта хостов с их ключ-файлами) — JSON в форме `Config::fromArray()`: `--config indexnow.json` или `INDEXNOW_CONFIG`;
переменные выигрывают у файла, `INDEXNOW_HOSTS` заменяет карту целиком. Приоритет: опции команды, окружение процесса,
`.env`, `--config`, умолчания.

Два умолчания — CLI-шные: `debounce.store` = `state` (файл состояния; `memory` и `none` как везде), `history.store` =
`pdo` над тем же файлом (`INDEXNOW_HISTORY_STORE=none` выключает). `dispatch` — `sync` или `none`: у процесса нет
очереди. `check` предупреждает о `INDEXNOW_*`, которую никто не читает. Таблицы: [docs/configuration.md](docs/configuration.md).

## Команды

`check`, `config`, `submit`, `sitemap`, `key:generate`, `key:file`, `history`, `status` — команды семейства без
префикса `indexnow:` (префикс — сам бинарник); `key:file <docroot>` — единственная, которой нет у адаптеров: пишет
`<docroot>/<key>.txt` для каждого хоста (и предыдущий ключ на время ротации). Глобальные опции: `--env-file`,
`--no-env-file`, `--config`, `--state`, `-v`. Коды выхода: 0, 1 (движок или источник упал), 2 (плохие аргументы).
Таблица с опциями — в [английском README](README.md#commands).

## Ограничения

- Нет `explain` и `submit-<subject>`: им нужна ORM адаптера. `check --sample <url>` показывает, что увидит движок.
- `debounce.store` и `history.pdo.service` не могут быть id контейнера: контейнера нет. `state`, `memory`, `none`; своя база — `history.pdo.dsn`.
- В образе нет `ext-intl`: IDN-хосты идут через чистый PHP Punycode ядра.
- Массовые правки в CMS ничего не запускают: `sitemap --new-only` по расписанию — в этом и смысл.

## Другие пакеты

[`indexnowkit/symfony-bundle`](https://github.com/indexnowkit/php/tree/main/packages/symfony-bundle),
[`indexnowkit/laravel`](https://github.com/indexnowkit/php/tree/main/packages/laravel),
[`indexnowkit/yii2`](https://github.com/indexnowkit/php/tree/main/packages/yii2),
[`indexnowkit/yii3`](https://github.com/indexnowkit/php/tree/main/packages/yii3),
[`indexnowkit/core`](https://github.com/indexnowkit/php/tree/main/packages/core). Адаптер отправляет в момент
коммита модели; CLI — когда его запустили. На хосте с фреймворком — адаптер, CLI для того, чего у него нет.

## Заметки для AI-ассистентов

- Composer-пакет `indexnowkit/cli`, бинарник `indexnow` (также `indexnow.phar` из релизов `indexnowkit/php-cli` и образ
  `ghcr.io/indexnowkit/indexnow`); без фреймворка и ORM. Конфигурация: переменные `INDEXNOW_*` (`.env` в рабочем
  каталоге) или `--config <file.json>`; состояние в каталог `.indexnow` (`state.sqlite`).
- Минимальная настройка (PHP-кода нет: программа — сам CLI):

```bash
indexnow key:generate --write-env && echo 'INDEXNOW_BASE_URL=https://www.example.com' >> .env
indexnow key:file /var/www/site/public && indexnow check --live
indexnow sitemap --new-only --json          # cron
```

```php
// тот же граф из PHP, когда CLI встраивают (IndexNowKit\Cli\Wiring — composition root над ядром)
use IndexNowKit\Cli\Application;
exit((new Application())->run());
```

- Команды: `check`, `config`, `submit`, `sitemap`, `key:generate`, `key:file`, `history`, `status` — семейные
  `indexnow:check`, `indexnow:config`, `indexnow:submit`, `indexnow:sitemap`, `indexnow:key:generate`,
  `indexnow:history`, `indexnow:status` без префикса. Проверка: `indexnow check --live`.
- Подводные камни:
  - Ключ-файл должен отдаваться сайтом (`key:file <docroot>` пишет, `check` читает); 403 от всех движков — неверный или закэшированный старый ключ-файл.
  - Вне production (`INDEXNOW_ENV` не `prod`/`production`) ключ при незаданном `dry_run` роняет `check`: `INDEXNOW_DRY_RUN=true` там или `INDEXNOW_ENV=prod`.
  - `sitemap --new-only` требует файл состояния между прогонами (`actions/cache` в CI); `--state memory` делает каждый прогон первым. `--changed-since` и `--new-only` складываются.
  - `debounce.store` — только `state`, `memory`, `none`; `http.client` задать нельзя. `history.store` по умолчанию `pdo` над файлом состояния; `INDEXNOW_HISTORY_STORE=none` выключает историю.
  - Неизвестные `INDEXNOW_*` — предупреждение `check` (`config.unknown`); список ключей — `Config::OPTIONS` плюс `sitemap.*`, `verify.*`, `history.*` как `INDEXNOW_<BLOCK>_<KEY>`.

## Версионирование

SemVer; до 1.0 минорные версии могут содержать несовместимые изменения — в [CHANGELOG.md](CHANGELOG.md). Что покрывает
обещание совместимости — команды, их опции и переменные, не классы: [docs/bc.md](docs/bc.md).

MIT. IndexNow — товарный знак его владельца; проект независим и не связан с Microsoft, Яндексом и indexnow.org.
