<?php

declare(strict_types=1);

namespace IndexNowKit\Cli;

use Composer\InstalledVersions;
use Phar;

/**
 * The version `indexnow --version` prints: the tag Box compiled the PHAR from (`@git-version@`, replaced at build
 * time — box.json `git-version`), else what Composer installed (`InstalledVersions`), else `dev` (a checkout).
 */
final class Version
{
    public const PACKAGE = 'indexnowkit/cli';

    /** Replaced by Box when the PHAR is compiled; left as it is everywhere else. */
    private const PHAR_VERSION = '@git-version@';

    private function __construct() {}

    public static function current(): string
    {
        if (Phar::running(false) !== '' && !str_starts_with(self::PHAR_VERSION, '@')) {
            return ltrim(self::PHAR_VERSION, 'v');
        }
        if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled(self::PACKAGE)) {
            $version = InstalledVersions::getPrettyVersion(self::PACKAGE);
            // a checkout is `dev-main`, and Composer calls a root package without a version `1.0.0+no-version-set`
            if (\is_string($version) && $version !== '' && !str_starts_with($version, 'dev-') && !str_contains($version, 'no-version-set')) {
                return ltrim($version, 'v');
            }
        }

        return 'dev';
    }
}
