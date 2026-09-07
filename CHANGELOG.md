# Changelog

Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Versioning: SemVer; until 1.0 minor versions may
contain breaking changes, listed under "Changed". What the compatibility promise covers: [docs/bc.md](docs/bc.md).

## Unreleased

### Added

- **The `indexnow` binary** (wave N, spec 19b): the commands of `indexnowkit/console`, `sitemap` and `history` without a
  framework — `check`, `config`, `submit`, `sitemap`, `key:generate`, `history`, `status` under their names without the
  `indexnow:` prefix — plus `key:file <docroot>`, the one command that exists only here: `<docroot>/<key>.txt` for every
  configured host (the previous key too during a rotation, the path of `key_location` when it names one), the file
  masked in what it prints.
- **Configuration from the environment for everything**: the core through `Config::arrayFromEnv()` (core 0.13.1), the
  `sitemap`, `verify` and `history` blocks by one rule, `INDEXNOW_<BLOCK>_<KEY>`; a `.env` in the working directory
  (`--env-file`, `--no-env-file`; the process environment wins, nothing is written into it); a JSON file in the shape
  of `Config::fromArray()` (`--config`, `INDEXNOW_CONFIG`) under the variables. `check` warns about an `INDEXNOW_*`
  nothing reads (`config.unknown`) and prints where the configuration came from (`cli.sources`).
- **The state file** `.indexnow/state.sqlite` (`--state`, `INDEXNOW_STATE`; `memory` keeps nothing): a PSR-16 cache
  over one table (the debounce window — `debounce.store` defaults to `state` — the 403 counters, the robots cache of
  the pre-flight), the `pdo` history store (`history.store` defaults to `pdo` over it; `INDEXNOW_HISTORY_STORE=none`
  switches it off) and the store of seen sitemap URLs behind `sitemap --new-only` (sitemap 0.9.0). `check` prints
  the `cli.state` line and probes the debounce store through the file.
- **A deterministic transport**: `symfony/http-client` with `nyholm/psr7`, required and passed explicitly, no
  `php-http/discovery` at run time (`bin/indexnow` removes its strategies, so a hidden discovery fails loudly); the
  pre-flight of `indexnowkit/verify` gets its own transport with `verify.timeout` and no redirects. `http.client`,
  a container id, is a configuration error.
- **Three packagings**: the Composer package (`vendor/bin/indexnow`, `composer global require`), `indexnow.phar`
  built with Box (`box.json`; the release workflow of `php-cli` attaches it to the GitHub release with its sha256),
  the image `ghcr.io/indexnowkit/indexnow` (`php:8.3-cli-alpine`, user `indexnow` uid 1000, `WORKDIR /work`; the
  `<version>-action` tag without a user for the GitHub Action). And the GitHub Action `indexnowkit/indexnow-action`
  over the image (`action/`): `check` then `sitemap --new-only` or `submit`, inputs `key`, `base-url`, `sitemap`,
  `urls`, `changed-since`, `new-only`, `state-path`, `verify`, `dry-run`, `fail-on-error`, outputs `submitted`,
  `skipped`, `failed`, `summary-json`, a step summary.
