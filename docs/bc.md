# Backward compatibility

`indexnowkit/cli` follows SemVer and the tiers of the core's [docs/bc.md](https://github.com/indexnowkit/php/blob/main/packages/core/docs/bc.md).
**Before 1.0, minor versions may contain breaking changes**, listed under "Changed" in [CHANGELOG.md](../CHANGELOG.md).

The public contract of a command line is what a cron line, a CI step and a runbook depend on:

| Tier | Members |
|---|---|
| **Commands** — names, arguments, options, exit codes; an option is renamed only with a deprecation window | `check`, `config`, `submit`, `sitemap`, `key:generate`, `history`, `status` (the definitions of `indexnowkit/console`, `sitemap` and `history`), `key:file` (`Definitions::keyFile()`); the global options `--env-file`, `--no-env-file`, `--config`, `--state`; the exit codes of `Console\ExitCode` |
| **Variables and files** | `INDEXNOW_*` (the core's list plus `INDEXNOW_<BLOCK>_<KEY>` for `sitemap`, `verify`, `history`), `INDEXNOW_CONFIG`, `INDEXNOW_STATE`; the `.env` file; the JSON file in the shape of `Config::fromArray()`; the defaults `debounce.store = state`, `history.store = pdo` |
| **Packagings** | the PHAR (`indexnow.phar` and `indexnow.phar.sha256` in the GitHub release of `php-cli`), the image `ghcr.io/indexnowkit/indexnow` (tags `<version>`, `<major.minor>`, `latest`, `<version>-action`; the user `indexnow`, `WORKDIR /work`, `ENTRYPOINT indexnow`), the inputs and outputs of `action.yml` of `indexnowkit/indexnow-action` (`v1` moves within the major) |
| **Documents** | the JSON of `check --json`, `config --json`, `history --json`, `status --json`, `submit --json`, `sitemap --json` as the packages define them; `summary.json` of the action (`check`, `results`) |
| **Call** — signatures only grow by appended, defaulted parameters | `Application` (constructor, `wiring()`), `Wiring` (the accessors), `Commands`, `GlobalOptions`, `Env\Dotenv`, `Config\EnvBlocks`, `Config\EnvConfigSource`, `KeyFileRunner`, `Command\KeyFileCommand`, `Definitions::keyFile()`, `Version::current()` — for an application that embeds the CLI |
| **Internal until 1.0** | everything under `State\` (`State`, `SqliteCache`, `SqliteSeenStore`, the tables `indexnow_cache`, `indexnow_sitemap_seen`), `LazyChecker`, `Check\*`, `Command\ConfigurationErrorCommand`: the shape of the state file may change in a minor (a run then starts as if the file were new) |

Not covered: the printed texts (they are for humans and get improved; the codes of `check` are), log lines, anything
under `tests/`, `docker/indexnow-action` (the entrypoint is reached only through `action.yml`).

The package pins `indexnowkit/core ^0.13.1`, `console ^0.5`, `sitemap ^0.9`, `verify ^0.4`, `history ^0.4`: a minor
of any of them that changes what the CLI wires ships with a `cli` minor.
