<?php

declare(strict_types=1);

namespace IndexNowKit\Cli\Config;

use IndexNowKit\Adapter\ConfigFactory;
use IndexNowKit\Config;
use IndexNowKit\Console\ConfigSourceInterface;
use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\History\HistoryConfig;
use IndexNowKit\Sitemap\SitemapConfig;
use IndexNowKit\Verify\VerifyConfig;
use JsonException;

/**
 * The configuration of `indexnow`, as the `check` and `config` commands read it and as the graph is built from it:
 * the JSON file (`--config`, `INDEXNOW_CONFIG`; the nested shape of `Config::fromArray()` plus the `sitemap`, `verify`
 * and `history` blocks) under the environment — the core variables through `Config::arrayFromEnv()`, the package
 * blocks through {@see EnvBlocks} — the environment winning key by key; `INDEXNOW_HOSTS` replaces the hosts map whole.
 * The strict build is the core's `Adapter\ConfigFactory` with the options of the three packages owned, `dispatch`
 * limited to `sync` and `none` (a process has no queue), the check command named `indexnow check`.
 *
 * Two defaults of the CLI live here: `history.store` is `pdo` over the state file unless the block says otherwise
 * (`INDEXNOW_HISTORY_STORE=` empty, or `none`, switches it off); `debounce.store` defaults to `state` in the wiring.
 */
final class EnvConfigSource implements ConfigSourceInterface
{
    public const CONFIG_VARIABLE = 'INDEXNOW_CONFIG';

    /** The `cli` block `config` prints: where the configuration came from and where the state is. */
    public const CLI_OPTIONS = ['cli.config_file', 'cli.env_file', 'cli.state'];

    /**
     * The core variables after the `INDEXNOW_` prefix, as `Config::arrayFromEnv()` reads them (`Config::fromEnv()` lists
     * them); a test keeps the list equal to what the core reads. Everything else under the prefix is either a package
     * block ({@see EnvBlocks}), one of {@see OWN_VARIABLES}, or unknown — `check` warns about the unknown ones.
     */
    public const CORE_VARIABLES = [
        'ENABLED', 'KEY', 'PREVIOUS_KEY', 'HOSTS', 'KEY_LOCATION', 'BASE_URL', 'ENGINES', 'PRODUCTION_ENVIRONMENTS',
        'MAX_URL_LENGTH', 'LOG_URLS', 'FORBIDDEN_ESCALATION', 'RETRY_MAX_ATTEMPTS', 'RETRY_BASE_DELAY', 'RETRY_MULTIPLIER',
        'RETRY_MAX_DELAY', 'RETRY_SERVER_ERROR_DELAY', 'DISPATCH', 'DRY_RUN', 'SERVE_KEY_FILE', 'STRICT_HOSTS', 'ENV',
        'BATCH_MAX_URLS', 'DEBOUNCE_PER_URL', 'DEBOUNCE_STORE', 'THROTTLE_PER_MINUTE', 'HTTP_TIMEOUT', 'USER_AGENT',
        'HTTP_CLIENT', 'KEY_FILE_ENABLED', 'KEY_FILE_CACHE_MAX_AGE',
    ];

    /** The variables the CLI itself reads, after the prefix. */
    public const OWN_VARIABLES = ['CONFIG', 'STATE'];

    /** The `history.store` values that switch the history off (the block's `null`); an empty variable does the same. */
    private const HISTORY_OFF = ['none', 'null', 'off'];

    /** @var array<string, mixed>|null */
    private ?array $raw = null;

    /**
     * @param array<string, mixed>  $env        the environment ({@see \IndexNowKit\Cli\Env\Dotenv::environment()})
     * @param string|null           $configFile `--config`; null = `INDEXNOW_CONFIG`, else no file
     * @param array<string, scalar|null> $facts the `cli` block `config` prints (`config_file`, `env_file`, `state`)
     */
    public function __construct(private readonly array $env, private readonly ?string $configFile = null, private readonly array $facts = []) {}

    /**
     * The dotted keys the CLI accepts on top of `Config::OPTIONS`: the blocks of the three packages and its own `cli` block.
     *
     * @return list<string>
     */
    public static function ownedOptions(): array
    {
        return [...SitemapConfig::OPTIONS, ...VerifyConfig::OPTIONS, ...HistoryConfig::OPTIONS, ...self::CLI_OPTIONS];
    }

    /** The `Adapter\ConfigFactory` of the CLI: the strict and the never-throwing build share it. */
    public static function factory(): ConfigFactory
    {
        return new ConfigFactory(ownedOptions: self::ownedOptions(), dispatchModes: ['sync', 'none'], checkCommand: 'indexnow check');
    }

    /** The JSON file this run reads, or null: `--config`, else `INDEXNOW_CONFIG`. */
    public function configFile(): ?string
    {
        $variable = $this->env[self::CONFIG_VARIABLE] ?? null;

        return $this->configFile ?? (\is_string($variable) && $variable !== '' ? $variable : null);
    }

    /**
     * @throws ConfigurationException when the JSON file is missing or invalid, or `INDEXNOW_HOSTS` is malformed
     */
    public function raw(): array
    {
        if ($this->raw !== null) {
            return $this->raw;
        }
        $file = $this->configFile();
        $json = $file === null ? [] : self::json($file);
        $env = Config::arrayFromEnv($this->env);
        $blocks = EnvBlocks::read($this->env, [...SitemapConfig::OPTIONS, ...VerifyConfig::OPTIONS, ...HistoryConfig::OPTIONS]);
        $merged = array_replace_recursive($json, $env, $blocks);
        if (isset($env['hosts'])) {
            $merged['hosts'] = $env['hosts']; // INDEXNOW_HOSTS is the whole map, not a patch of the file's
        }
        if ($this->facts !== []) {
            $merged['cli'] = $this->facts;
        }

        return $this->raw = $merged;
    }

    public function build(): Config
    {
        return self::factory()->build($this->raw(), null);
    }

    public function packages(): array
    {
        return ['verify' => $this->verify()->toArray(), 'history' => $this->history()->toArray()];
    }

    /**
     * @throws ConfigurationException
     */
    public function sitemap(): SitemapConfig
    {
        return SitemapConfig::fromArray($this->block('sitemap'));
    }

    /**
     * @throws ConfigurationException
     */
    public function verify(): VerifyConfig
    {
        return VerifyConfig::fromArray($this->block('verify'));
    }

    /**
     * `history.store` defaults to `pdo` (over the state file); an empty `INDEXNOW_HISTORY_STORE`, `none`, `null` or `off` switches it off.
     *
     * @throws ConfigurationException
     */
    public function history(): HistoryConfig
    {
        $block = $this->block('history');
        if (!\array_key_exists('store', $block)) {
            $block['store'] = HistoryConfig::STORE_PDO;
        } elseif (\is_string($block['store']) && \in_array(strtolower($block['store']), self::HISTORY_OFF, true)) {
            $block['store'] = null;
        }

        return HistoryConfig::fromArray($block);
    }

    /**
     * The `INDEXNOW_*` variables of the environment nothing reads: a typo, or a key of another adapter.
     *
     * @return list<string>
     */
    public function unknownVariables(): array
    {
        $known = array_map(static fn(string $name): string => EnvBlocks::PREFIX . $name, [...self::CORE_VARIABLES, ...self::OWN_VARIABLES]);
        $known = [...$known, ...EnvBlocks::variables([...SitemapConfig::OPTIONS, ...VerifyConfig::OPTIONS, ...HistoryConfig::OPTIONS])];
        $unknown = [];
        foreach (array_keys($this->env) as $name) {
            $name = (string) $name;
            if (str_starts_with($name, EnvBlocks::PREFIX) && !\in_array($name, $known, true)) {
                $unknown[] = $name;
            }
        }
        sort($unknown);

        return $unknown;
    }

    /**
     * The keys of the JSON file (and the merged blocks) neither the core nor the packages know.
     *
     * @return list<string>
     */
    public function unknownOptions(): array
    {
        return self::factory()->unknownOptions($this->raw());
    }

    /**
     * @return array<string, mixed>
     */
    private function block(string $name): array
    {
        $block = $this->raw()[$name] ?? [];

        return \is_array($block) ? $block : [];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ConfigurationException
     */
    private static function json(string $file): array
    {
        if (!is_file($file)) {
            throw new ConfigurationException(\sprintf('the configuration file %s does not exist (--config, INDEXNOW_CONFIG).', $file));
        }
        try {
            $decoded = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ConfigurationException(\sprintf('the configuration file %s is not valid JSON: %s.', $file, $e->getMessage()), 0, $e);
        }
        if (!\is_array($decoded) || array_is_list($decoded) && $decoded !== []) {
            throw new ConfigurationException(\sprintf('the configuration file %s must hold a JSON object (the shape of Config::fromArray()), got %s.', $file, get_debug_type($decoded)));
        }
        /** @var array<string, mixed> $decoded */

        return $decoded;
    }
}
