<?php

// The taint entry points of this package (bin/taint, .github/workflows/taint.yml): the public API called with request-
// shaped data — what the command line and the environment hand in — so that Psalm's taint analysis has a source to
// follow into the sinks (files, SQL, the shell). A library has no taint source of its own. Not a test: PHPUnit does not
// load it, phpstan analyses it at the level of the test suite, only psalm.xml lists it.

declare(strict_types=1);

namespace IndexNowKit\Taint;

use IndexNowKit\Cli\Application;
use IndexNowKit\Cli\Config\EnvBlocks;
use IndexNowKit\Cli\Config\EnvConfigSource;
use IndexNowKit\Cli\Env\Dotenv;
use IndexNowKit\Cli\GlobalOptions;
use IndexNowKit\Cli\KeyFileRunner;
use IndexNowKit\Cli\State\State;
use IndexNowKit\Cli\Wiring;
use IndexNowKit\Key\StaticKeyProvider;
use IndexNowKit\Sitemap\SitemapConfig;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

/** A request value as a string: the taint of the superglobal, none of the mixed. */
function input(string $name): string
{
    $value = $_GET[$name] ?? $_POST[$name] ?? $_SERVER[$name] ?? null;

    return \is_string($value) ? $value : '';
}

/** @var array<string, mixed> $env */
$env = $_SERVER;
$io = new SymfonyStyle(new ArrayInput([]), new NullOutput());

// the environment and the files it names
$merged = Dotenv::environment(input('env_file'), false, input('cwd'), $env);
$source = new EnvConfigSource($merged, input('config'));
$config = $source->build();
EnvBlocks::read($merged, SitemapConfig::OPTIONS);

// the state file and its stores
$state = State::resolve(input('state'), $merged, input('cwd'));
$state->cache()->set(input('key'), input('value'));
$state->seenStore()->count();

// the graph and the commands over them
$wiring = Application::wiring(GlobalOptions::fromInput(new ArrayInput(['--state' => input('state'), '--config' => input('config'), '--env-file' => input('env_file')]), input('cwd')), new NullOutput(), $env);
$wiring->services()->checker()->run(false, input('host'));
(new KeyFileRunner(StaticKeyProvider::fromConfig($config), Wiring::words()))->run($io, input('docroot'), [input('host')], false);
(new Application())->run(new ArrayInput(['command' => 'key:file', 'docroot' => input('docroot'), '--host' => [input('host')]]), new NullOutput());
