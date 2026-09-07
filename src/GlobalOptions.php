<?php

declare(strict_types=1);

namespace IndexNowKit\Cli;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * The options every command of `indexnow` takes before its own: where the `.env` file is (or that none is read),
 * the JSON configuration file, the state file. Read off the raw input before the command is known
 * (`Application::doRun()`), the way Symfony's own application reads `--env`.
 */
final readonly class GlobalOptions
{
    public const ENV_FILE = 'env-file';
    public const NO_ENV_FILE = 'no-env-file';
    public const CONFIG = 'config';
    public const STATE = 'state';

    /**
     * @param string      $workingDir the directory `.env`, the state file and relative paths are resolved against (the current directory)
     * @param string|null $envFile    `--env-file`: the file to read; null = `<workingDir>/.env` when it exists
     * @param bool        $noEnvFile  `--no-env-file`: read no file at all
     * @param string|null $configFile `--config`: the JSON configuration file; null = `INDEXNOW_CONFIG`, else none
     * @param string|null $state      `--state`: the state file, or `memory`; null = `INDEXNOW_STATE`, else `<workingDir>/.indexnow/state.sqlite`
     */
    public function __construct(
        public string $workingDir,
        public ?string $envFile = null,
        public bool $noEnvFile = false,
        public ?string $configFile = null,
        public ?string $state = null,
    ) {}

    public static function fromInput(InputInterface $input, string $workingDir): self
    {
        return new self(
            $workingDir,
            self::value($input->getParameterOption('--' . self::ENV_FILE, null, true)),
            $input->hasParameterOption('--' . self::NO_ENV_FILE, true),
            self::value($input->getParameterOption('--' . self::CONFIG, null, true)),
            self::value($input->getParameterOption('--' . self::STATE, null, true)),
        );
    }

    /**
     * The options as the application's definition declares them (so `--help` lists them and a command accepts them).
     *
     * @return list<InputOption>
     */
    public static function definition(): array
    {
        return [
            new InputOption(self::ENV_FILE, null, InputOption::VALUE_REQUIRED, 'Read the INDEXNOW_* variables of this .env file too (the real environment wins); default: .env in the current directory when it exists'),
            new InputOption(self::NO_ENV_FILE, null, InputOption::VALUE_NONE, 'Read no .env file'),
            new InputOption(self::CONFIG, null, InputOption::VALUE_REQUIRED, 'A JSON configuration file (the shape of Config::fromArray() plus the sitemap, verify and history blocks); the variables win over it. Default: INDEXNOW_CONFIG'),
            new InputOption(self::STATE, null, InputOption::VALUE_REQUIRED, 'The sqlite state file (debounce window, 403 counters, history, seen sitemap URLs), or "memory" for a run that keeps nothing. Default: INDEXNOW_STATE, else .indexnow/state.sqlite'),
        ];
    }

    private static function value(mixed $option): ?string
    {
        return \is_string($option) && $option !== '' ? $option : null;
    }
}
