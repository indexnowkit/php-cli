# Contributing

This repository is a read-only split of [`indexnowkit/php`](https://github.com/indexnowkit/php) (`packages/cli`).
Please open issues and pull requests there; releases are tagged in the monorepo as `cli@x.y.z` and mirrored here.

Quick rules (details in the monorepo's CONTRIBUTING.md):

- Every change comes with tests (the feature tests over `Tests\Support\Harness`); the printed texts follow one rule: the fact,
  what is allowed, how to fix it.
- The command names, the options and the `INDEXNOW_*` variables are the public contract (the classes under `State\` are not): rename only with a deprecation window.
- phpstan level 9 and php-cs-fixer must pass.
- The package is a consumer of `indexnowkit/core`: nothing here may require a change in the core to work.
