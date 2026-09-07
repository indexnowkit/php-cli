# Security

Report vulnerabilities privately to the maintainer (see `composer.json` → `authors`) or through GitHub's private
vulnerability reporting on [indexnowkit/php](https://github.com/indexnowkit/php/security). Please do not open a
public issue for an unfixed vulnerability.

## What the CLI handles

- The key is read from `INDEXNOW_KEY` (the process environment, a `.env` file next to the cron job, or the JSON
  file of `--config`); `key:generate --write-env` creates `.env` with mode 0600. `config` and `check` print it
  masked (`KeyValidator::mask()`); `key:file` masks it in the file names it prints. Keep `.env` out of the document
  root: the key file is public by design, the `.env` holding it is not.
- `key:file <docroot>` writes only into the directory given (a `key_location` path with `..` is refused); the paths of
  `--env-file`, `--config` and `--state` are the operator's, taken as given.
- The state file (`.indexnow/state.sqlite`, created in a `0700` directory) holds the submission history: the URLs of
  the site and the answers of the engines, no keys. Do not put it under the document root.
- The transport is `symfony/http-client` with no redirects and a timeout; the pre-flight of `indexnowkit/verify`
  fetches only the URLs of the configured hosts, and the `sitemap` command drops every `<loc>` on a host without a key
  before anything fetches it.
- The Docker image runs as the user `indexnow` (uid 1000); the `-action` tag runs as root because a GitHub Docker
  action must (the workspace is written by the runner's user), and only inside the action's container.

Reports are acknowledged within 5 business days; a fix or a mitigation plan follows within 30 days.
