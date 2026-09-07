<?php

declare(strict_types=1);

namespace IndexNowKit\Cli\Check;

use IndexNowKit\Check\CheckInterface;
use IndexNowKit\Check\CheckReport;
use IndexNowKit\Cli\Config\EnvConfigSource;

/**
 * The `config.unknown` lines of `check`: an `INDEXNOW_*` variable nothing reads (a typo, a key of a framework adapter),
 * and a key of the JSON file neither the core nor the packages know — what a framework adapter logs at boot, printed
 * where the operator looks. Nothing is printed when everything is known, and the sources line says what was read.
 */
final class UnknownConfigCheck implements CheckInterface
{
    public const CODE = 'config.unknown';
    public const CODE_SOURCES = 'cli.sources';

    public function __construct(private readonly EnvConfigSource $source) {}

    public function check(CheckReport $report): void
    {
        $raw = $this->source->raw();
        $cli = \is_array($raw['cli'] ?? null) ? $raw['cli'] : [];
        $envFile = $cli['env_file'] ?? null;
        $configFile = $cli['config_file'] ?? null;
        $report->ok(\sprintf('configuration: the INDEXNOW_* variables%s%s', \is_string($envFile) ? ' and ' . $envFile : '', \is_string($configFile) ? ', over ' . $configFile : ''), self::CODE_SOURCES);
        $variables = $this->source->unknownVariables();
        if ($variables !== []) {
            $report->warning(\sprintf('configuration: unknown variable(s) %s: nothing reads them (a typo, or a key of a framework adapter; the list is docs/configuration.md)', implode(', ', $variables)), self::CODE);
        }
        $options = $this->source->unknownOptions();
        if ($options !== []) {
            $report->warning(\sprintf('configuration: unknown option(s) in %s: %s', \is_string($configFile) ? $configFile : 'the configuration', implode(', ', $options)), self::CODE);
        }
    }
}
