<?php

declare(strict_types=1);

namespace IndexNowKit\Cli\Env;

use IndexNowKit\Exception\ConfigurationException;
use Symfony\Component\Dotenv\Dotenv as SymfonyDotenv;
use Symfony\Component\Dotenv\Exception\FormatException;

/**
 * The environment the CLI reads its configuration from: the process environment (`getenv()`, `$_SERVER`, `$_ENV`
 * — the same three `Config::fromEnv()` merges) over the variables of a `.env` file, **the process winning**. The file
 * is parsed (`symfony/dotenv`), never loaded into the process: nothing here writes `$_ENV` or calls `putenv()`, so
 * `variables_order` without `E` and a `.env` next to a cron job that already exports `INDEXNOW_KEY` both come out the
 * same way, and a test hands in its own process array.
 *
 * Which file: `--env-file` names one and it must exist; without the option `.env` in the working directory is read
 * when it is there and silently skipped when it is not; `--no-env-file` reads none.
 */
final class Dotenv
{
    public const DEFAULT_FILE = '.env';

    private function __construct() {}

    /**
     * @param string|null                $envFile    `--env-file`; null = the default file when it exists
     * @param bool                       $noEnvFile  `--no-env-file`
     * @param string                     $workingDir where the default file lives
     * @param array<string, mixed>|null  $process    the process environment; null = {@see process()}
     *
     * @return array<string, mixed> the merged environment, the process over the file
     *
     * @throws ConfigurationException when `--env-file` names a file that is not there or cannot be parsed
     */
    public static function environment(?string $envFile, bool $noEnvFile, string $workingDir, ?array $process = null): array
    {
        return array_replace(self::read(self::resolve($envFile, $noEnvFile, $workingDir)), $process ?? self::process());
    }

    /**
     * The file the run reads, or null when there is none: `--env-file` as given (must exist), `--no-env-file` none,
     * else `<workingDir>/.env` when it exists.
     *
     * @throws ConfigurationException when `--env-file` names a file that is not there
     */
    public static function resolve(?string $envFile, bool $noEnvFile, string $workingDir): ?string
    {
        if ($noEnvFile) {
            return null;
        }
        if ($envFile !== null) {
            if (!is_file($envFile)) {
                throw new ConfigurationException(\sprintf('--env-file %s: no such file (pass the path of an existing .env file, or --no-env-file).', $envFile));
            }

            return $envFile;
        }
        $default = rtrim($workingDir, '/') . '/' . self::DEFAULT_FILE;

        return is_file($default) ? $default : null;
    }

    /**
     * The variables of one `.env` file, as `symfony/dotenv` parses them (quotes, `${VAR}` references, `export`).
     *
     * @return array<string, string>
     *
     * @throws ConfigurationException when the file cannot be read or has a syntax error
     */
    public static function read(?string $file): array
    {
        if ($file === null) {
            return [];
        }
        $contents = @file_get_contents($file);
        if ($contents === false) {
            throw new ConfigurationException(\sprintf('%s cannot be read.', $file));
        }
        try {
            return (new SymfonyDotenv())->parse($contents, $file);
        } catch (FormatException $e) {
            throw new ConfigurationException(\sprintf('%s: %s', $file, $e->getMessage()), 0, $e);
        }
    }

    /**
     * The process environment as `Config::fromEnv()` reads it: `getenv()`, then `$_SERVER`, then `$_ENV` on top.
     *
     * @return array<string, mixed>
     */
    public static function process(): array
    {
        return array_merge(getenv(), $_SERVER, $_ENV);
    }
}
