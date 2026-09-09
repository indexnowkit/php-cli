# IndexNow command line — `indexnowkit/cli`

Tell Yandex, Bing, Naver and Seznam which URLs changed, from any host that has PHP and from any CI — no framework.
One binary, `indexnow`: check the key file, submit URLs or a whole sitemap (only what is new or changed since the
last run), keep the history. Cron on Bitrix, WordPress, MODX, OpenCart or Joomla; a deploy of Hugo, Astro or Jekyll;
"just send these ten URLs". Three packagings of the same thing: a Composer package, `indexnow.phar`, the Docker image
`ghcr.io/indexnowkit/indexnow` — and a GitHub Action on top of the image.

[![Packagist](https://img.shields.io/packagist/v/indexnowkit/cli)](https://packagist.org/packages/indexnowkit/cli)
[![Downloads](https://img.shields.io/packagist/dt/indexnowkit/cli)](https://packagist.org/packages/indexnowkit/cli)
[![CI](https://github.com/indexnowkit/php/actions/workflows/ci.yml/badge.svg)](https://github.com/indexnowkit/php/actions)
![PHPStan](https://img.shields.io/badge/phpstan-level%209-4c1)
![PHP](https://img.shields.io/badge/php-%5E8.2-777bb4)
[![License](https://img.shields.io/packagist/l/indexnowkit/cli)](LICENSE)

[Русская версия](README.ru.md) · Issues and pull requests: [github.com/indexnowkit/php](https://github.com/indexnowkit/php/issues) (the `php-*` repositories are read-only splits)

## Who gets notified

**Yandex, Bing (and DuckDuckGo via Bing), Naver, Seznam, Yep, Internet Archive, Amazon** — every engine in the
[IndexNow](https://www.indexnow.org) [registry](https://www.indexnow.org/searchengines.json). One request to the shared
endpoint reaches all of them; name engines explicitly (`INDEXNOW_ENGINES=yandex,bing`) only to reach a single one.

**Google: no.** Google does not support IndexNow; this tool will not pretend otherwise. IndexNow is a notification,
not indexing: the engine decides whether and when to crawl.

## Install

```bash
composer global require indexnowkit/cli            # ~/.composer/vendor/bin/indexnow, or vendor/bin/indexnow in a project
curl -LO https://github.com/indexnowkit/php-cli/releases/latest/download/indexnow.phar && php indexnow.phar --version
docker run --rm ghcr.io/indexnowkit/indexnow --version
```

PHP 8.2+ with `pdo_sqlite` and `xmlreader` (the PHAR checks that before it runs; shared hosting without `pdo_sqlite`
cannot keep the state file — `--state memory` runs without one). No `ext-intl` needed.

## Quick start

```bash
cd /var/www/site                                   # the .env and the state file live in the working directory
indexnow key:generate --write-env                  # INDEXNOW_KEY=… into .env (mode 0600)
echo 'INDEXNOW_BASE_URL=https://www.example.com' >> .env
indexnow key:file /var/www/site/public             # writes public/<key>.txt: the file the engines verify
indexnow check --live                              # the configuration, the key file over HTTP, one real probe per engine
indexnow sitemap                                   # the whole sitemap once…
```

…then one line in crontab — only what changed since the last run, whatever `<lastmod>` says:

```cron
*/30 * * * *  cd /var/www/site && indexnow sitemap --new-only --json >> /var/log/indexnow.log 2>&1
```

Or ten URLs by hand: `indexnow submit https://www.example.com/a /b /c`.

## Any CMS: Bitrix, WordPress, MODX, OpenCart, Joomla

The CLI needs two things every site has: the document root (for `key:file`) and a sitemap. Bitrix generates
`sitemap.xml` and the index per information block with its own "Search engine optimization" module (since version
14); WordPress has one at `/wp-sitemap.xml` (5.5+) or from an SEO plugin; MODX (`pdoTools`, `SEO Suite`), OpenCart
(a sitemap feed) and Joomla (an extension) likewise. Point `INDEXNOW_SITEMAP_URL` at it when it is not
`<base_url>/sitemap.xml`, or give the file: `indexnow sitemap /var/www/site/public/sitemap.xml` reads it from disk.
No PHP of the CMS runs; the CLI is a separate process on the same host.

### Bitrix, step by step

On a BitrixVM the site lives in `/home/bitrix/www` (the other sites of a multi-site setup in `/home/bitrix/ext_www/<site>`)
and the "Search engine optimization" module writes `sitemap.xml` right into that document root (Marketing → Search engine
optimization → sitemap.xml settings; regenerate it there or by its agent). The CLI needs PHP 8.2+ on the command line —
the PHP the VM menu selected.

```bash
mkdir -p /home/bitrix/indexnow && cd /home/bitrix/indexnow    # outside the document root: the .env and the state file
indexnow key:generate --write-env
echo 'INDEXNOW_BASE_URL=https://www.example.com' >> .env
indexnow key:file /home/bitrix/www                            # /home/bitrix/www/<key>.txt, one file per configured host
indexnow check --live
```

```cron
*/30 * * * *  cd /home/bitrix/indexnow && indexnow sitemap /home/bitrix/www/sitemap.xml --new-only --json >> /var/log/indexnow.log 2>&1
```

The sitemap is read from disk, so nothing depends on the web server or on caching; only URLs the state file has not seen
with this `<lastmod>` go out. Nothing is installed into Bitrix, no module, no agent: the CLI is a process of the `bitrix`
user next to the site.

## Static sites: on deploy

The GitHub Action `indexnowkit/indexnow-action` runs `check` and then `sitemap --new-only` from the image; the state
file lives in `.indexnow/` and is cached between runs:

```yaml
- uses: actions/cache@v4
  with: { path: .indexnow, key: indexnow-${{ github.ref_name }} }
- uses: indexnowkit/indexnow-action@v1
  with:
    key: ${{ secrets.INDEXNOW_KEY }}
    base-url: https://www.example.com
    sitemap: dist/sitemap.xml            # the file the build wrote, or a URL; default <base-url>/sitemap.xml
    new-only: 'true'
```

Inputs, outputs and the step summary: [docs/action.md](docs/action.md). Any other CI runs the image the same way:
`docker run --rm -v "$PWD:/work" -e INDEXNOW_KEY -e INDEXNOW_BASE_URL ghcr.io/indexnowkit/indexnow sitemap --new-only`
([docs/docker.md](docs/docker.md)).

## The state file

`.indexnow/state.sqlite` in the working directory (`--state`, `INDEXNOW_STATE`), one sqlite file, created on first
use in a `0700` directory: the debounce window (`debounce.per_url`, 10 minutes: a URL announced twice within it is
sent once — `--force` overrides), the 403 counters per host, the submission history (`history`, `status`) and the
fingerprints of the sitemap URLs announced so far (`sitemap --new-only`). Everything that makes a scheduled run
idempotent, in one file that fits `actions/cache`; no backup needed (losing it costs one full re-announcement).
`--state memory` keeps nothing between runs — a read-only container, a one-off `--dry-run`. `check` prints the
`state:` line; details in [docs/state.md](docs/state.md).

## Configuration

Environment first, file second. Every option of the core and of the three packages is one variable:
`INDEXNOW_<KEY>` for the core (`INDEXNOW_KEY`, `INDEXNOW_BASE_URL`, `INDEXNOW_ENGINES`, `INDEXNOW_DEBOUNCE_PER_URL`,
…), `INDEXNOW_<BLOCK>_<KEY>` for the blocks (`INDEXNOW_SITEMAP_URL`, `INDEXNOW_SITEMAP_MAX_DEPTH`,
`INDEXNOW_VERIFY_ENABLED`, `INDEXNOW_HISTORY_PDO_DSN`). A `.env` in the working directory is read when it is there
(`--env-file`, `--no-env-file`); the process environment wins over it. For the parts that are not one value (a hosts map
with per-host key locations) a JSON file in the shape of `Config::fromArray()`: `--config indexnow.json` or
`INDEXNOW_CONFIG`; the variables win over the file, `INDEXNOW_HOSTS` replaces its hosts map whole. Precedence: the
command's options, the process environment, `.env`, `--config`, the defaults.

Two defaults are the CLI's: `debounce.store` is `state` (the state file; `memory` and `none` as everywhere) and
`history.store` is `pdo` over the same file (`INDEXNOW_HISTORY_STORE=none` switches it off). `dispatch` is `sync` or
`none`: a process has no queue. `check` warns about an `INDEXNOW_*` variable nothing reads. The tables:
[docs/configuration.md](docs/configuration.md).

## Commands

| Command | |
|---|---|
| `check [--live] [--host=…] [--json] [--strict] [--sample=<url>]` | the configuration, the key file of every host over HTTP, the state file, the debounce store, sitemap spool, verify, history; `--live` sends one probe per engine; `--sample` fetches a page the way the pre-flight would ([console](https://github.com/indexnowkit/php/tree/main/packages/console), codes in the core's `docs/check-codes.md`) |
| `config [--json]` | the effective configuration, keys masked, plus the `cli` block (which file, which state); what a bug report pastes |
| `submit <url>… [--force] [--dry-run] [--json]` | URLs, or paths under `base_url`, now |
| `sitemap [url|file] [--new-only] [--changed-since=…] [--dry-run] [--no-verify] [--json]` | the sitemap (index, gzip, text) in batches; `--new-only` only what the state file has not seen with this `lastmod` ([sitemap](https://github.com/indexnowkit/php/tree/main/packages/sitemap)) |
| `key:generate [--write-env[=file]] [--force]` | a key; `--write-env` puts `INDEXNOW_KEY=` into `.env` (mode 0600; `--force` rotates, keeping the old key as `INDEXNOW_PREVIOUS_KEY`) |
| `key:file <docroot> [--host=…] [--dry-run]` | `<docroot>/<key>.txt` for every configured host (and the previous key during a rotation), or the path `key_location` names; the one command that exists only here |
| `history [--host=…] [--status=…] [--since=…] [--json] [--purge]` | what was sent, when, with what answer ([history](https://github.com/indexnowkit/php/tree/main/packages/history)) |
| `status [--json]` | switches, debounce store, 403 counters per host, the last successful submission, the history size |

Global options: `--env-file`, `--no-env-file`, `--config`, `--state`, `-v` (the log on stderr). Exit codes: 0, 1
(an engine or the source failed), 2 (bad arguments; `--new-only` without a state, `sitemap.enabled: false`).
`--json` keeps stdout machine-readable; the notes go to stderr.

## Docker

`ghcr.io/indexnowkit/indexnow:<version>` (`0.1`, `latest`), `php:8.3-cli-alpine` plus the PHAR, runs as the user
`indexnow` (uid 1000) in `/work`: mount the directory that holds `.indexnow/` and the sitemap files there. The
`<version>-action` tag is the same image without a user for the GitHub Action. [docs/docker.md](docs/docker.md).

## Limitations

- No `explain`, no `submit-<subject>`: they need the ORM of a framework adapter. `check --sample <url>` says what an
  engine would see of a page (`indexnowkit/verify` is built in; `verify.enabled` switches the pre-flight on).
- `debounce.store` and `history.pdo.service` cannot name a container id: there is no container. `state`, `memory`,
  `none`; `history.pdo.dsn` for a database of your own.
- The image has no `ext-intl`: international host names go through the core's pure PHP Punycode.
- Bulk changes in a CMS fire nothing: run `sitemap --new-only` on a schedule, that is the point.

## Other packages

| Package | Framework |
|---|---|
| [`indexnowkit/symfony-bundle`](https://github.com/indexnowkit/php/tree/main/packages/symfony-bundle) | Symfony + Doctrine |
| [`indexnowkit/laravel`](https://github.com/indexnowkit/php/tree/main/packages/laravel) | Laravel |
| [`indexnowkit/yii2`](https://github.com/indexnowkit/php/tree/main/packages/yii2), [`indexnowkit/yii3`](https://github.com/indexnowkit/php/tree/main/packages/yii3) | Yii |
| [`indexnowkit/core`](https://github.com/indexnowkit/php/tree/main/packages/core) | plain PHP, the library under all of them |

A framework adapter submits the moment a model is committed; this CLI submits when it is run. On a host with a
framework, prefer the adapter and use the CLI for what it adds (`key:file` on a bare host, a sitemap in cron).

## Notes for AI assistants

- Composer package `indexnowkit/cli`, binary `indexnow` (also indexnow.phar from the GitHub releases of
  `indexnowkit/php-cli` and the image `ghcr.io/indexnowkit/indexnow`); no framework, no ORM. Configuration: `INDEXNOW_*`
  variables (`.env` in the working directory) or `--config FILE` (JSON); state in the `.indexnow` directory (state.sqlite).
- Minimal complete setup (no PHP code: the CLI is the program):

```bash
indexnow key:generate --write-env && echo 'INDEXNOW_BASE_URL=https://www.example.com' >> .env
indexnow key:file /var/www/site/public && indexnow check --live
indexnow sitemap --new-only --json          # cron
```

```php
// the same graph in PHP, when the CLI is embedded (IndexNowKit\Cli\Wiring is a composition root over the core)
use IndexNowKit\Cli\Application;
exit((new Application())->run());
```

- Commands: `check`, `config`, `submit`, `sitemap`, `key:generate`, `key:file`, `history`, `status` — the family's
  `indexnow:check`, `indexnow:config`, `indexnow:submit`, `indexnow:sitemap`, `indexnow:key:generate`,
  `indexnow:history`, `indexnow:status` without the prefix (the binary is the prefix). Verify: `indexnow check --live`.
- Pitfalls:
  - The key file must be served by the site (`key:file <docroot>` writes it; `check` fetches it); a 403 from every engine means the key file is wrong or cached stale.
  - Outside production (`INDEXNOW_ENV` not in `prod, production`) a configured key with `dry_run` unset makes `check` fail; set `INDEXNOW_DRY_RUN=true` there or `INDEXNOW_ENV=prod`.
  - `sitemap --new-only` needs the state file between runs (`actions/cache` in CI); `--state memory` makes every run the first. `--changed-since` and `--new-only` add up.
  - `debounce.store` accepts `state`, `memory`, `none` only; `http.client` cannot be set (no container). `history.store` is `pdo` over the state file by default; `INDEXNOW_HISTORY_STORE=none` switches the history off.
  - Unknown `INDEXNOW_*` variables are warned about by `check` (code config.unknown); the key list is `Config::OPTIONS` plus `sitemap.*`, `verify.*`, `history.*` as `INDEXNOW_<BLOCK>_<KEY>`.

## Versioning

SemVer; until 1.0 minor versions may contain breaking changes, listed in [CHANGELOG.md](CHANGELOG.md). What the
compatibility promise covers — the commands, their options and the variables, not the classes: [docs/bc.md](docs/bc.md).

MIT. IndexNow is a trademark of its owner; this project is independent and not affiliated with Microsoft, Yandex or indexnow.org.
