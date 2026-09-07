# The GitHub Action `indexnowkit/indexnow-action`

A Docker action over `ghcr.io/indexnowkit/indexnow:<version>-action`: `indexnow check --json` (the configuration and
the key file of the **live** site — nothing is announced before the engines can verify the key), then
`indexnow sitemap --json` (or `submit --json` when `urls` is given), the outputs and a step summary. The source is
[`action/`](https://github.com/indexnowkit/php/tree/main/packages/cli/action) of this package, mirrored into the repository `indexnowkit/indexnow-action`
(`action.yml` at its root, as the Marketplace wants); `v1` moves within the major, `v1.0.0` is pinned.

```yaml
name: IndexNow
on:
  push: { branches: [main] }          # after the deploy job, when the site is live
jobs:
  indexnow:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: actions/cache@v4         # the state file: what was announced already
        with: { path: .indexnow, key: indexnow-${{ github.ref_name }}-${{ github.run_id }}, restore-keys: indexnow-${{ github.ref_name }}- }
      - uses: indexnowkit/indexnow-action@v1
        with:
          key: ${{ secrets.INDEXNOW_KEY }}
          base-url: https://www.example.com
          sitemap: dist/sitemap.xml     # a file of the checkout, or a URL; default <base-url>/sitemap.xml
          new-only: 'true'
```

| Input | Default | |
|---|---|---|
| `key` | required | the IndexNow key; `<base-url>/<key>.txt` must be served by the site |
| `base-url` | required | the site; the host of the key file and of every URL |
| `key-location` | | the URL of the key file when it is not `<base-url>/<key>.txt` |
| `engines` | `api` | `api` (the shared endpoint) or `yandex,bing,…` |
| `sitemap` | `<base-url>/sitemap.xml` | a URL, or a file in the checkout |
| `urls` | | one URL per line; then `submit` runs instead of `sitemap` |
| `changed-since` | | only entries with `lastmod` after this (`1 day`, `2026-09-01`) |
| `new-only` | `false` | only what the state file has not seen with this `lastmod`; needs the cache above |
| `state-path` | `.indexnow` | the directory of the state file, relative to the workspace |
| `verify` | `false` | fetch every URL before submitting it (noindex, canonical, redirects, robots.txt are skipped) |
| `dry-run` | `false` | report what would be submitted, send nothing |
| `fail-on-error` | `true` | `false`: a failed check or submission is a notice, the step stays green |

Outputs: `submitted`, `skipped`, `failed` (URL counts), `summary-json` (a file with the check report and the results).
The step summary shows the result table and the check lines.

What sets it apart from the other IndexNow actions: every engine in one run (or a list), the key file checked before
anything is sent, `new-only` over a state file instead of a `lastmod` window (a sitemap without `lastmod` still gets
each change announced once), the pre-flight, the history in the state file. Inputs arrive as `INPUT_<NAME>` with the
dashes kept (`INPUT_BASE-URL`), which is why the entrypoint (`docker/indexnow-action` in the image) is PHP.
