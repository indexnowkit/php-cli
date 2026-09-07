# The state file

`indexnow` keeps what makes scheduled runs idempotent in one sqlite file: `.indexnow/state.sqlite` in the working
directory, `--state <path>` or `INDEXNOW_STATE` elsewhere, `memory` for a run that keeps nothing (a read-only
container, a one-off `--dry-run`). Nothing opens it before a store is asked for: `list`, `--version`, `help` and a
configuration that does not build never touch it. It is created on first use, its directory with mode `0700`, in WAL
mode (a cron run and a manual one do not lock each other out), the tables created if missing.

| Table | What | Who reads it |
|---|---|---|
| `indexnow_cache` | a PSR-16 cache: the debounce window (`debounce.store = state`, keys `indexnowkit_<sha1 of the URL>`, TTL `debounce.per_url`), the consecutive-403 counters per host, the robots.txt cache of the pre-flight | `submit`, `sitemap`, `check` (the probe), `status` |
| `indexnow_submissions` | the history: one row per URL of a result, the rows of one result share a `batch` (the schema of the history package; `history.pdo.table` renames it) | `history`, `status`, `check` |
| `indexnow_sitemap_seen` | the fingerprint of every sitemap URL announced so far (URL plus `<lastmod>`, or none) and when | `sitemap --new-only` |

What it means: a URL announced within the last `debounce.per_url` seconds (600) is not sent again by the next run —
`--force` overrides; a sitemap URL announced once is not sent again by `--new-only` until its `lastmod` changes;
`history --purge` removes records older than `history.retention_days`. No keys are stored. No backup needed: losing
the file costs one full re-announcement (the engines de-duplicate). Do not put it under the document root.

`check` prints `state: <path> (writable | read-only | not created yet)` (`cli.state`, a warning when it cannot be
written) and probes the debounce store through it (`debounce.store`). In CI, cache the directory between runs
(`actions/cache` with `path: .indexnow`); in Docker, mount the working directory on `/work`.

The classes behind it (`IndexNowKit\Cli\State\*`) are internal until 1.0: the shape of the file may change in a minor,
and a run then starts as if the file were new.
