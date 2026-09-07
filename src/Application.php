<?php

declare(strict_types=1);

namespace IndexNowKit\Cli;

use Closure;
use IndexNowKit\Cli\Config\EnvConfigSource;
use IndexNowKit\Cli\Env\Dotenv;
use IndexNowKit\Cli\State\State;
use IndexNowKit\Console\ExitCode;
use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\Http\TransportInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Application as BaseApplication;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `indexnow`: a `symfony/console` application with the global options of {@see GlobalOptions} (`--env-file`,
 * `--no-env-file`, `--config`, `--state`), read off the raw input before the command is known, and the eight commands
 * of {@see Commands} behind a lazy loader. The environment, the state and the graph ({@see wiring()}) are assembled on
 * the first command that needs them; a configuration that does not build is one error line with where to fix it.
 */
final class Application extends BaseApplication
{
    public const NAME = 'indexnow';

    /**
     * @param (Closure(GlobalOptions, OutputInterface): Wiring)|null $wiringFactory how the graph is built (tests hand in a
     *                                                                              fake transport and their own environment); null = {@see wiring()}
     * @param string|null                                             $workingDir    where `.env` and the state file live; null = the current directory
     */
    public function __construct(private readonly ?Closure $wiringFactory = null, private readonly ?string $workingDir = null)
    {
        parent::__construct(self::NAME, Version::current());
    }

    public function doRun(InputInterface $input, OutputInterface $output): int
    {
        $options = GlobalOptions::fromInput($input, $this->workingDir ?? self::cwd());
        $factory = $this->wiringFactory ?? self::wiring(...);
        $this->setCommandLoader((new Commands(static fn(): Wiring => $factory($options, $output)))->loader());
        try {
            return parent::doRun($input, $output);
        } catch (ConfigurationException $e) {
            (new SymfonyStyle($input, $output))->getErrorStyle()->error(\sprintf('%s (see %s)', rtrim($e->getMessage(), '.'), Wiring::words()->configLocation));

            return ExitCode::FAILURE;
        }
    }

    /**
     * The graph of a real run: the `.env` file under the process environment, the state file, `symfony/http-client`,
     * the log on the console (`-v` shows more). Tests replace the three pieces that touch the outside world.
     *
     * @param array<string, mixed>|null $process   the process environment; null = the real one
     * @param TransportInterface|null   $transport the transport; null = symfony/http-client
     * @param ClockInterface|null       $clock     the clock of the graph and the state; null = the system clock
     *
     * @throws ConfigurationException when `--env-file` names a missing or broken file
     */
    public static function wiring(GlobalOptions $options, OutputInterface $output, ?array $process = null, ?TransportInterface $transport = null, ?ClockInterface $clock = null): Wiring
    {
        $envFile = Dotenv::resolve($options->envFile, $options->noEnvFile, $options->workingDir);
        $env = array_replace(Dotenv::read($envFile), $process ?? Dotenv::process());
        $state = State::resolve($options->state, $env, $options->workingDir, $clock);
        $configVariable = $env[EnvConfigSource::CONFIG_VARIABLE] ?? null;
        $configFile = $options->configFile ?? (\is_string($configVariable) && $configVariable !== '' ? $configVariable : null);
        $source = new EnvConfigSource($env, $configFile, ['config_file' => $configFile, 'env_file' => $envFile, 'state' => $state->path()]);

        return new Wiring($source, $state, new ConsoleLogger($output), $transport, $clock);
    }

    protected function getDefaultInputDefinition(): InputDefinition
    {
        $definition = parent::getDefaultInputDefinition();
        $definition->addOptions(GlobalOptions::definition());

        return $definition;
    }

    private static function cwd(): string
    {
        $cwd = getcwd();

        return $cwd === false ? '.' : $cwd;
    }
}
