# The Docker image `ghcr.io/indexnowkit/indexnow`

`php:8.3-cli-alpine` plus `indexnow.phar` as `/usr/local/bin/indexnow`; `pdo_sqlite`, `xmlreader`, `zlib`, `curl` and
`openssl` come with the base image, `intl` does not (international host names go through the core's pure PHP
Punycode). Tags: `<version>` (`0.1.0`), `<major.minor>` (`0.1`), `latest`; `<version>-action` is the same image without
a `USER`, for the GitHub Action only. Multi-platform: `linux/amd64`, `linux/arm64`.

The image runs as the user `indexnow` (uid 1000) with `WORKDIR /work` and `ENTRYPOINT ["indexnow"]`: mount the
directory that holds `.indexnow/` (the state file) and the sitemap files on `/work`, give the variables:

```bash
docker run --rm -v "$PWD:/work" -e INDEXNOW_KEY -e INDEXNOW_BASE_URL ghcr.io/indexnowkit/indexnow check --live
docker run --rm -v "$PWD:/work" --env-file .env ghcr.io/indexnowkit/indexnow sitemap --new-only --json
docker run --rm -e INDEXNOW_KEY=… -e INDEXNOW_BASE_URL=… -e INDEXNOW_STATE=memory ghcr.io/indexnowkit/indexnow submit https://www.example.com/a
```

A mounted directory owned by another uid: `--user "$(id -u):$(id -g)"`, or `INDEXNOW_STATE=/tmp/state.sqlite`
(`memory` when nothing may persist). `.env` inside `/work` is read like on a host; `--env-file` of Docker hands the
variables in without a file in the container.

GitLab CI, the same image:

```yaml
indexnow:
  image: { name: ghcr.io/indexnowkit/indexnow:0.1, entrypoint: [""] }
  cache: { key: indexnow, paths: [.indexnow/] }
  script:
    - indexnow check --json
    - indexnow sitemap --new-only --json public/sitemap.xml
  variables: { INDEXNOW_BASE_URL: https://www.example.com }   # INDEXNOW_KEY: a masked CI variable
```

How it is built: the PHAR first (`bin/phar` in the monorepo, the `phar` job of the release workflow of `php-cli`),
then `docker build --target cli|action packages/cli` copies it in — the Dockerfile compiles nothing, so the image
holds exactly the PHAR of the release. The first push of a version creates the GHCR package; the organization made
it public once.
