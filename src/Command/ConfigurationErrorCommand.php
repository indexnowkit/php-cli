<?php

declare(strict_types=1);

namespace IndexNowKit\Cli\Command;

use IndexNowKit\Console\CommandDefinition;
use IndexNowKit\Console\ExitCode;
use IndexNowKit\Exception\ConfigurationException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * What stands in for a command whose graph cannot be built because the configuration does not: the same arguments
 * and options (so `help <command>` and `list` work on a host with no `INDEXNOW_KEY` yet), and one error line with the
 * place to fix it when it runs — the runtime path of a framework adapter prints that line and submits nothing; here
 * it is the exit code too.
 */
final class ConfigurationErrorCommand extends Command
{
    /**
     * @param string $configLocation where the operator fixes the configuration (`Vocabulary::$configLocation`)
     */
    public function __construct(string $name, private readonly CommandDefinition $definition, private readonly ConfigurationException $error, private readonly string $configLocation)
    {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->definition->applyTo($this);
        $this->ignoreValidationErrors();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        (new SymfonyStyle($input, $output))->getErrorStyle()->error(\sprintf('%s (see %s)', rtrim($this->error->getMessage(), '.'), $this->configLocation));

        return ExitCode::FAILURE;
    }
}
