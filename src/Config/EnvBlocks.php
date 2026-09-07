<?php

declare(strict_types=1);

namespace IndexNowKit\Cli\Config;

/**
 * The one rule for the configuration blocks of the optional packages in the environment: the dotted key of the
 * option, upper-cased, `.` as `_`, behind the prefix — `sitemap.max_depth` is `INDEXNOW_SITEMAP_MAX_DEPTH`,
 * `history.pdo.dsn` is `INDEXNOW_HISTORY_PDO_DSN`. The keys come from the packages' own lists (`SitemapConfig::OPTIONS`,
 * `VerifyConfig::OPTIONS`, `HistoryConfig::OPTIONS`); the values stay strings, the packages' `fromArray()` coerce them.
 * The core's own variables keep their historical names (`Config::arrayFromEnv()` reads them).
 */
final class EnvBlocks
{
    public const PREFIX = 'INDEXNOW_';

    private function __construct() {}

    /** The variable of a dotted option: `verify.max_batch` → `INDEXNOW_VERIFY_MAX_BATCH`. */
    public static function variable(string $option, string $prefix = self::PREFIX): string
    {
        return $prefix . strtoupper(str_replace('.', '_', $option));
    }

    /**
     * The blocks the environment sets, nested as the packages' `fromArray()` take them, holding only the variables
     * that are present (an empty value is kept: `history.store` reads it as "no store").
     *
     * @param array<string, mixed> $env     the environment (the process over the `.env` file)
     * @param list<string>         $options dotted keys, `block.key` or `block.sub.key`
     *
     * @return array<string, mixed>
     */
    public static function read(array $env, array $options, string $prefix = self::PREFIX): array
    {
        $out = [];
        foreach ($options as $option) {
            $value = $env[self::variable($option, $prefix)] ?? null;
            if (!\is_scalar($value)) {
                continue;
            }
            $path = explode('.', $option);
            $cursor = &$out;
            foreach (\array_slice($path, 0, -1) as $segment) {
                if (!\is_array($cursor[$segment] ?? null)) {
                    $cursor[$segment] = [];
                }
                $cursor = &$cursor[$segment];
            }
            $cursor[$path[\count($path) - 1]] = \is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            unset($cursor);
        }

        return $out;
    }

    /**
     * The variables of the given options, for the list of what is known.
     *
     * @param list<string> $options
     *
     * @return list<string>
     */
    public static function variables(array $options, string $prefix = self::PREFIX): array
    {
        return array_map(static fn(string $option): string => self::variable($option, $prefix), $options);
    }
}
