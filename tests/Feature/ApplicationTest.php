<?php

declare(strict_types=1);

namespace IndexNowKit\Cli\Tests\Feature;

use IndexNowKit\Cli\Application;
use IndexNowKit\Cli\Commands;
use IndexNowKit\Cli\GlobalOptions;
use IndexNowKit\Cli\Tests\Support\Harness;
use IndexNowKit\Cli\Version;
use IndexNowKit\Cli\Wiring;
use IndexNowKit\Console\ExitCode;
use LogicException;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\ApplicationTester;

final class ApplicationTest extends TestCase
{
    #[TestDox('list: the eight commands under their names without the indexnow: prefix, and nothing is built — no environment read, no state file, no request')]
    public function testListBuildsNothing(): void
    {
        $built = 0;
        $application = new Application(static function () use (&$built): Wiring {
            ++$built;
            throw new LogicException('the graph must not be built for list');
        }, '/nonexistent');
        $application->setAutoExit(false);
        $application->setCatchExceptions(false);
        $tester = new ApplicationTester($application);

        self::assertSame(ExitCode::SUCCESS, $tester->run(['command' => 'list']));
        $display = $tester->getDisplay();
        foreach (Commands::NAMES as $name) {
            self::assertMatchesRegularExpression('/^\s+' . preg_quote($name, '/') . '\s{2,}/m', $display, $name . ' is listed');
        }
        self::assertStringNotContainsString('indexnow:', $display);
        self::assertSame(0, $built);
        self::assertStringContainsString('--env-file=ENV-FILE', $display, 'the global options are in the help');
        self::assertStringContainsString('--state=STATE', $display);

        self::assertSame(ExitCode::SUCCESS, $tester->run(['--version' => true]));
        self::assertSame('indexnow ' . Version::current(), trim($tester->getDisplay()));
        self::assertSame(0, $built);

    }

    #[TestDox('help <command> works on a host without a key: the definition is the package\'s, the command itself would print the configuration error')]
    public function testHelpWithoutConfiguration(): void
    {
        $harness = new Harness([], false);

        self::assertSame(ExitCode::SUCCESS, $harness->run(['command' => 'help', 'command_name' => 'submit']));
        self::assertStringContainsString('Ignore the debounce store', $harness->display(), 'the definition of the package command');
        self::assertSame(ExitCode::SUCCESS, $harness->run(['command' => 'help', 'command_name' => 'sitemap']));
        self::assertStringContainsString('INDEXNOW_SITEMAP_URL from the config', $harness->display());
        self::assertSame(ExitCode::FAILURE, $harness->run(['command' => 'submit', 'urls' => ['https://www.example.com/a']]));
        self::assertStringContainsString('no "key" (or "hosts" map) is configured', $harness->errorOutput());
        self::assertStringContainsString('see the INDEXNOW_* variables', $harness->errorOutput());
        self::assertSame([], $harness->transport->posts);
    }

    #[TestDox('the global options reach the wiring: --env-file, --no-env-file, --config, --state, with the working directory')]
    public function testGlobalOptionsReachTheWiring(): void
    {
        $seen = null;
        $harness = new Harness();
        $application = new Application(static function (GlobalOptions $options, OutputInterface $output) use (&$seen, $harness): Wiring {
            $seen = $options;

            return Application::wiring($options, $output, $harness->env, $harness->transport, $harness->clock);
        }, $harness->dir);
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);

        $tester->run(['command' => 'config', '--json' => true, '--state' => 'memory', '--no-env-file' => true, '--config' => $harness->file('c.json', '{}')]);
        self::assertInstanceOf(GlobalOptions::class, $seen);
        self::assertSame($harness->dir, $seen->workingDir);
        self::assertSame('memory', $seen->state);
        self::assertTrue($seen->noEnvFile);
        self::assertSame($harness->dir . '/c.json', $seen->configFile);
        self::assertNull($seen->envFile);
        $document = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($document);
        self::assertSame(['config_file' => $harness->dir . '/c.json', 'env_file' => null, 'state' => 'memory'], $document['adapter']['cli'], 'config --json prints where the configuration came from');
    }

    #[TestDox('a configuration that does not build is one error line naming the place to fix it, exit 1 — for every command')]
    public function testBrokenConfiguration(): void
    {
        $harness = new Harness(['INDEXNOW_KEY' => 'short'], false);

        self::assertSame(ExitCode::FAILURE, $harness->run(['command' => 'submit', 'urls' => ['https://www.example.com/a']]));
        self::assertStringContainsString('key "shor*" is invalid', $harness->errorOutput());
        self::assertStringContainsString('see the INDEXNOW_* variables', $harness->errorOutput());
        self::assertSame([], $harness->transport->posts);

        $harness = new Harness(['INDEXNOW_KEY' => Harness::KEY, 'INDEXNOW_HTTP_CLIENT' => 'app.client'], false);
        self::assertSame(ExitCode::FAILURE, $harness->run(['command' => 'submit', 'urls' => ['https://www.example.com/a']]));
        self::assertStringContainsString('"http.client" is "app.client"', $harness->errorOutput());
    }

    #[TestDox('--env-file that does not exist is an error; the .env of the working directory is read; the process environment wins over it')]
    public function testEnvFile(): void
    {
        $harness = new Harness([], false);
        $harness->file('.env', "INDEXNOW_KEY=" . Harness::KEY . "\nINDEXNOW_BASE_URL=https://www.example.com\nINDEXNOW_ENV=prod\n");

        self::assertSame(ExitCode::SUCCESS, $harness->run(['command' => 'config', '--json' => true]));
        $document = json_decode($harness->display(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($document);
        self::assertSame('https://www.example.com', $document['config']['base_url']);
        self::assertSame($harness->dir . '/.env', $document['adapter']['cli']['env_file']);

        $harness->env['INDEXNOW_BASE_URL'] = 'https://process.example.com';
        self::assertSame(ExitCode::SUCCESS, $harness->run(['command' => 'config', '--json' => true]));
        $document = json_decode($harness->display(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($document);
        self::assertSame('https://process.example.com', $document['config']['base_url'], 'the process wins');

        self::assertSame(ExitCode::FAILURE, $harness->run(['command' => 'config', '--env-file' => $harness->dir . '/nope.env']));
        self::assertStringContainsString('--env-file ' . $harness->dir . '/nope.env: no such file', $harness->errorOutput());
    }
}
