# Configuration — the variables, the `.env`, the JSON file

Environment first, file second. The precedence, highest first: the options of the command (`--state`, `--dry-run`,
`--force`), the process environment, the `.env` of the working directory (`--env-file` names another, `--no-env-file`
reads none; the process wins over it), the JSON file (`--config`, `INDEXNOW_CONFIG`), the defaults. Nothing is written
into the process environment: the file is parsed (`symfony/dotenv`: quotes, `${VAR}`, `export`), then merged.

## The core: `INDEXNOW_<KEY>`

The variables of `Config::fromEnv()` — the table is in the core's
[configuration.md](https://github.com/indexnowkit/php/blob/main/packages/core/docs/configuration.md#environment-variables):
`INDEXNOW_KEY`, `INDEXNOW_PREVIOUS_KEY`, `INDEXNOW_HOSTS` (`host=key,host2=key2`), `INDEXNOW_KEY_LOCATION`,
`INDEXNOW_BASE_URL`, `INDEXNOW_ENGINES`, `INDEXNOW_DISPATCH` (`sync` or `none` here), `INDEXNOW_DRY_RUN`,
`INDEXNOW_STRICT_HOSTS`, `INDEXNOW_ENV` (else `APP_ENV`), `INDEXNOW_PRODUCTION_ENVIRONMENTS`, `INDEXNOW_BATCH_MAX_URLS`,
`INDEXNOW_DEBOUNCE_PER_URL`, `INDEXNOW_DEBOUNCE_STORE` (`state` — the default —, `memory`, `none`),
`INDEXNOW_THROTTLE_PER_MINUTE`, `INDEXNOW_HTTP_TIMEOUT`, `INDEXNOW_USER_AGENT`, `INDEXNOW_KEY_FILE_ENABLED`,
`INDEXNOW_KEY_FILE_CACHE_MAX_AGE`, `INDEXNOW_MAX_URL_LENGTH`, `INDEXNOW_LOG_URLS`, `INDEXNOW_FORBIDDEN_ESCALATION`,
`INDEXNOW_RETRY_*`. `INDEXNOW_HTTP_CLIENT` is refused: there is no container to resolve it in.

## The packages: `INDEXNOW_<BLOCK>_<KEY>`

The dotted key of the option, upper-cased, `.` as `_` (`Cli\Config\EnvBlocks`). Values as strings; the package
coerces (`"3"`, `"true"`).

| Block | Variables |
|---|---|
| `sitemap` ([sitemap](https://github.com/indexnowkit/php/blob/main/packages/sitemap/README.md#configuration)) | `INDEXNOW_SITEMAP_ENABLED`, `INDEXNOW_SITEMAP_URL` (the default sitemap; else `<base_url>/sitemap.xml`), `INDEXNOW_SITEMAP_MAX_DEPTH`, `INDEXNOW_SITEMAP_MAX_SITEMAPS`, `INDEXNOW_SITEMAP_MAX_BYTES`, `INDEXNOW_SITEMAP_ALLOW_FOREIGN_HOSTS`, `INDEXNOW_SITEMAP_SPOOL`, `INDEXNOW_SITEMAP_SPOOL_DIR`, `INDEXNOW_SITEMAP_FETCH_RETRIES` |
| `verify` ([verify](https://github.com/indexnowkit/php/blob/main/packages/verify/docs/configuration.md)) | `INDEXNOW_VERIFY_ENABLED` (the pre-flight GET before every submission; off by default), `INDEXNOW_VERIFY_REDIRECT`, `INDEXNOW_VERIFY_NON_CANONICAL`, `INDEXNOW_VERIFY_ORIGIN_ERROR`, `INDEXNOW_VERIFY_DELAY`, `INDEXNOW_VERIFY_TIMEOUT`, `INDEXNOW_VERIFY_MAX_REDIRECTS`, `INDEXNOW_VERIFY_MAX_BATCH`, `INDEXNOW_VERIFY_TIME_BUDGET`, `INDEXNOW_VERIFY_ROBOTS_CACHE_TTL`, `INDEXNOW_VERIFY_USER_AGENT` |
| `history` ([history](https://github.com/indexnowkit/php/blob/main/packages/history/docs/configuration.md)) | `INDEXNOW_HISTORY_STORE` (`pdo` — the default, over the state file —, `psr16` — over the cache of the state file —, `none` / empty: off), `INDEXNOW_HISTORY_LIMIT`, `INDEXNOW_HISTORY_KEY_PREFIX`, `INDEXNOW_HISTORY_PDO_DSN` (a database of your own; a `sqlite:` file is created like the state file, any other needs the migration of the history package), `INDEXNOW_HISTORY_PDO_TABLE`, `INDEXNOW_HISTORY_RETENTION_DAYS`; `INDEXNOW_HISTORY_PDO_SERVICE` is refused (a framework connection) |

The CLI's own: `INDEXNOW_CONFIG` (the JSON file), `INDEXNOW_STATE` (the state file, or `memory`).

## The JSON file

The nested shape of `Config::fromArray()` plus the `sitemap`, `verify` and `history` blocks — what a framework adapter's
configuration looks like, as JSON (no `include`: it is PHAR-safe):

```json
{
  "key": "abcdef1234567890abcdef1234567890",
  "base_url": "https://www.example.com",
  "hosts": {"shop.example.com": {"key": "…", "key_location": "https://shop.example.com/keys/….txt"}},
  "strict_hosts": true,
  "sitemap": {"url": "https://www.example.com/sitemap-index.xml", "max_depth": 2},
  "verify": {"enabled": true, "redirect": "follow"},
  "history": {"retention_days": 30}
}
```

The variables win over it key by key (`array_replace_recursive`); `INDEXNOW_HOSTS` replaces the hosts map whole. A key
neither the core nor the packages know is a `config.unknown` warning of `check`. `config --json` prints the effective
values (keys masked) and the `cli` block: `config_file`, `env_file`, `state`.
