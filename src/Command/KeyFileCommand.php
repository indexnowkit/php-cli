<?php

declare(strict_types=1);

namespace IndexNowKit\Cli\Command;

use IndexNowKit\Cli\Definitions;
use IndexNowKit\Cli\KeyFileRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `key:file <docroot> [--host=]* [--dry-run]`: the key file of every configured host written into the document root
 * (`<docroot>/<key>.txt`, or the path `key_location` names). The one command of the family that exists only here: on
 * a host without a framework nothing serves the key file, the disk does.
 */
#[AsCommand(name: 'key:file', description: 'Write the key file of every configured host into a document root ({docroot}/{key}.txt, or the path of key_location): the first step on a host without a framework')]
final class KeyFileCommand extends Command
{
    public function __construct(private readonly KeyFileRunner $runner)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        Definitions::keyFile()->applyTo($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $docroot = $input->getArgument('docroot');
        $hosts = $input->getOption('host');

        return $this->runner->run(
            new SymfonyStyle($input, $output),
            \is_string($docroot) ? $docroot : '',
            \is_array($hosts) ? array_values(array_filter($hosts, static fn(mixed $h): bool => \is_string($h) && $h !== '')) : [],
            (bool) $input->getOption('dry-run'),
        );
    }
}
