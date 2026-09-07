<?php

declare(strict_types=1);

namespace IndexNowKit\Cli\Tests\Feature;

use IndexNowKit\Cli\Command\KeyFileCommand;
use IndexNowKit\Cli\KeyFileRunner;
use IndexNowKit\Cli\Tests\Support\Harness;
use IndexNowKit\Cli\Wiring;
use IndexNowKit\Config;
use IndexNowKit\Console\ExitCode;
use IndexNowKit\Key\KeyValidator;
use IndexNowKit\Key\StaticKeyProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class KeyCommandsTest extends TestCase
{
    private const OTHER = 'fedcba0987654321fedcba0987654321';

    #[TestDox('key:file writes <docroot>/<key>.txt for every managed host, the path of key_location when it is on the host, and the previous key while a rotation is open; unchanged the second time; --dry-run touches nothing; --host limits')]
    public function testKeyFile(): void
    {
        $dir = Harness::tempDir();
        $docroot = $dir . '/public';
        mkdir($docroot);
        $config = Config::fromArray([
            'key' => Harness::KEY, 'base_url' => 'https://www.example.com', 'previous_key' => self::OTHER,
            'hosts' => ['b.example.com' => ['key' => self::OTHER, 'key_location' => 'https://b.example.com/keys/' . self::OTHER . '.txt']],
        ]);
        $tester = new CommandTester(new KeyFileCommand(new KeyFileRunner(StaticKeyProvider::fromConfig($config), Wiring::words())));
        try {
            self::assertSame(ExitCode::SUCCESS, $tester->execute(['docroot' => $docroot, '--dry-run' => true]));
            $display = $tester->getDisplay();
            self::assertStringContainsString(KeyFileRunner::WOULD_WRITE, $display);
            self::assertStringNotContainsString(Harness::KEY, $display, 'the key is masked in the file names');
            self::assertStringContainsString(KeyValidator::mask(Harness::KEY), $display);
            self::assertSame([], glob($docroot . '/*') ?: []);

            self::assertSame(ExitCode::SUCCESS, $tester->execute(['docroot' => $docroot]));
            $display = $tester->getDisplay();
            self::assertSame(Harness::KEY, file_get_contents($docroot . '/' . Harness::KEY . '.txt'), 'www.example.com: the default key');
            self::assertSame(self::OTHER, file_get_contents($docroot . '/' . self::OTHER . '.txt'), 'www.example.com: the previous key while the rotation is open');
            self::assertSame(self::OTHER, file_get_contents($docroot . '/keys/' . self::OTHER . '.txt'), 'b.example.com: the path of key_location');
            self::assertStringContainsString('Verify with: indexnow check --live', $display);
            self::assertSame(3, substr_count($display, KeyFileRunner::WRITTEN), 'three files: the key and the previous key of www, the key of b');

            self::assertSame(ExitCode::SUCCESS, $tester->execute(['docroot' => $docroot, '--host' => ['www.example.com']]));
            self::assertSame(2, substr_count($tester->getDisplay(), KeyFileRunner::UNCHANGED));
            self::assertStringNotContainsString('b.example.com', $tester->getDisplay());

            self::assertSame(ExitCode::INVALID, $tester->execute(['docroot' => $docroot, '--host' => ['nope.example.com']]));
            self::assertStringContainsString('nope.example.com are not configured', $tester->getDisplay());

            self::assertSame(ExitCode::INVALID, $tester->execute(['docroot' => $dir . '/missing']));
            self::assertStringContainsString($dir . '/missing is not a directory', $tester->getDisplay());
        } finally {
            Harness::remove($dir);
        }
    }

    #[TestDox('key:file without a host to write for is INVALID naming the variables; a directory that cannot be written is FAILURE naming the permissions')]
    public function testKeyFileErrors(): void
    {
        $tester = new CommandTester(new KeyFileCommand(new KeyFileRunner(StaticKeyProvider::fromConfig(Config::fromArray(['dry_run' => true])))));
        self::assertSame(ExitCode::INVALID, $tester->execute(['docroot' => '/tmp']));
        self::assertStringContainsString('set INDEXNOW_BASE_URL', $tester->getDisplay());

        if (posix_geteuid() === 0) {
            return;
        }
        $dir = Harness::tempDir();
        mkdir($dir . '/ro', 0o500);
        try {
            $tester = new CommandTester(new KeyFileCommand(new KeyFileRunner(StaticKeyProvider::fromConfig(Config::fromArray(['key' => Harness::KEY, 'base_url' => 'https://www.example.com'])))));
            self::assertSame(ExitCode::FAILURE, $tester->execute(['docroot' => $dir . '/ro']));
            self::assertStringContainsString('cannot write', $tester->getDisplay());
            self::assertStringContainsString('check the permissions', $tester->getDisplay());
        } finally {
            chmod($dir . '/ro', 0o700);
            Harness::remove($dir);
        }
    }

    #[TestDox('the DoD scenario: key:generate --write-env writes .env in the working directory, key:file writes the file, check --live is green over the file the transport serves')]
    public function testGenerateThenFileThenCheck(): void
    {
        $harness = new Harness([], false);
        $harness->env['INDEXNOW_BASE_URL'] = 'https://www.example.com';
        $harness->env['INDEXNOW_ENV'] = 'prod';
        $docroot = $harness->dir . '/public';
        mkdir($docroot);
        $cwd = getcwd();
        self::assertIsString($cwd);
        chdir($harness->dir);
        try {
            self::assertSame(ExitCode::SUCCESS, $harness->run(['command' => 'key:generate', '--write-env' => null]));
            self::assertStringContainsString('INDEXNOW_KEY written to ' . $harness->dir . '/.env', $harness->display());
            self::assertStringContainsString('by the <key>.txt file that "indexnow key:file <docroot>" writes. Verify with: indexnow check', $harness->display());
            self::assertSame('0600', substr(\sprintf('%o', fileperms($harness->dir . '/.env')), -4));
            self::assertSame(1, preg_match('/^INDEXNOW_KEY=([a-f0-9]{32})$/m', (string) file_get_contents($harness->dir . '/.env'), $m));
            $key = $m[1];

            // the next run reads the .env of the working directory: the key is there without touching the process environment
            self::assertSame(ExitCode::SUCCESS, $harness->run(['command' => 'key:file', 'docroot' => $docroot]));
            self::assertSame($key, file_get_contents($docroot . '/' . $key . '.txt'));

            $harness->transport->onGet('https://www.example.com/' . $key . '.txt', new \IndexNowKit\Http\Response(200, (string) file_get_contents($docroot . '/' . $key . '.txt'), headers: ['Content-Type' => 'text/plain']));
            self::assertSame(ExitCode::SUCCESS, $harness->run(['command' => 'check', '--live' => true]));
            self::assertStringContainsString('key file OK', $harness->display());
            self::assertStringContainsString('accepted probe (200)', $harness->display());
            self::assertStringContainsString('IndexNow is ready.', $harness->display());
        } finally {
            chdir($cwd);
        }
    }
}
