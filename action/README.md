# IndexNow submit (indexnowkit) — GitHub Action

Tell Yandex, Bing, Naver, Seznam and the other [IndexNow](https://www.indexnow.org) engines which URLs of your site
changed, on deploy: the key file is checked first, then the sitemap is submitted — only what is new or changed since
the last run — or a list of URLs. Google does not participate in IndexNow.

```yaml
- uses: actions/cache@v4
  with: { path: .indexnow, key: indexnow-${{ github.ref_name }}-${{ github.run_id }}, restore-keys: indexnow-${{ github.ref_name }}- }
- uses: indexnowkit/indexnow-action@v1
  with:
    key: ${{ secrets.INDEXNOW_KEY }}
    base-url: https://www.example.com
    sitemap: dist/sitemap.xml            # a file of the checkout or a URL; default <base-url>/sitemap.xml
    new-only: 'true'
```

| Input | Default | |
|---|---|---|
| `key` | required | the IndexNow key; `<base-url>/<key>.txt` must be served by the site (put the file in your static source) |
| `base-url` | required | the site |
| `key-location` | | the URL of the key file when it is elsewhere on the host |
| `engines` | `api` | `api` = every engine through the shared endpoint; or `yandex,bing,…` |
| `sitemap` | `<base-url>/sitemap.xml` | a URL or a file in the checkout (sitemap index, gzip and text sitemaps too) |
| `urls` | | one URL per line: submit these instead of a sitemap |
| `changed-since` | | only entries with `lastmod` after this (`1 day`, `2026-09-01`) |
| `new-only` | `false` | only what was not announced with this `lastmod` before (cache `.indexnow` between runs) |
| `state-path` | `.indexnow` | the directory of the state file |
| `verify` | `false` | fetch every URL before submitting it: noindex, redirects, non-canonical pages and robots.txt blocks are skipped |
| `dry-run` | `false` | report, send nothing |
| `fail-on-error` | `true` | `false`: problems are a notice, the step stays green |

Outputs: `submitted`, `skipped`, `failed`, `summary-json`. The step summary shows the results and the check lines.

Why this one: all engines in one run, the key file verified before anything is sent, `new-only` over a state file
(a sitemap without `lastmod` still announces each change exactly once), an optional pre-flight of every page, a
history you can read (`indexnow history` of the same image). It is the `indexnow` CLI of
[`indexnowkit/cli`](https://github.com/indexnowkit/php/tree/main/packages/cli) in the image
`ghcr.io/indexnowkit/indexnow`; the source of this action lives in
[`packages/cli/action`](https://github.com/indexnowkit/php/tree/main/packages/cli/action) of the monorepo, this
repository is a read-only mirror. Issues: [github.com/indexnowkit/php](https://github.com/indexnowkit/php/issues).

MIT. IndexNow is a trademark of its owner; this project is independent and not affiliated with Microsoft, Yandex or indexnow.org.
