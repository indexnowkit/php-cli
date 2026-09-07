# The image ghcr.io/indexnowkit/indexnow: the indexnow.phar over php:8.3-cli-alpine (pdo_sqlite, xmlreader, zlib,
# curl and openssl are in the base image; intl is not — the core's pure PHP Punycode fallback serves). The PHAR is
# built before the image (bin/phar in the monorepo, the phar job of the release workflow of php-cli) and copied in.
#
# Two targets from the same layers:
#   cli    (the default; tags <version>, <major.minor>, latest): runs as the user `indexnow` (uid 1000), WORKDIR /work
#          — mount the directory that holds .indexnow/ and the sitemap files there
#   action (tag <version>-action): the same image without USER, for the GitHub Action — a Docker action must run as
#          root to write the workspace (docs.github.com, "Dockerfile support for GitHub Actions"), and its entrypoint
#          is /usr/local/bin/indexnow-action
ARG PHP_VERSION=8.3
FROM php:${PHP_VERSION}-cli-alpine AS base
COPY indexnow.phar /usr/local/bin/indexnow
COPY docker/indexnow-action /usr/local/bin/indexnow-action
RUN chmod +x /usr/local/bin/indexnow /usr/local/bin/indexnow-action \
    && adduser -D -u 1000 indexnow \
    && mkdir -p /work && chown indexnow:indexnow /work
WORKDIR /work
ENTRYPOINT ["indexnow"]
CMD ["list"]

FROM base AS action
ENTRYPOINT ["/usr/local/bin/indexnow-action"]
CMD []

FROM base AS cli
USER indexnow
