<?php

declare(strict_types=1);

namespace IndexNowKit\Cli\Tests\Unit;

use IndexNowKit\Cli\Env\Dotenv;
use IndexNowKit\Cli\Tests\Support\Harness;
use IndexNowKit\Exception\ConfigurationException;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

final class DotenvTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = Harness::tempDir();
    }

    protected function tearDown(): void
    {
        Harness::remove($this->dir);
    }

    #[TestDox('the process environment wins over the .env file; the file fills the rest; nothing is written into $_ENV or putenv()')]
    public function testProcessWins(): void
    {
        file_put_contents($this->dir . '/.env', "INDEXNOW_KEY=filekey12345\nINDEXNOW_BASE_URL=\"https://file.test\"\nexport INDEXNOW_ENGINES=yandex\n");
        $before = $_ENV;

        $env = Dotenv::environment(null, false, $this->dir, ['INDEXNOW_KEY' => 'processkey12', 'PATH' => '/bin']);

        self::assertSame('processkey12', $env['INDEXNOW_KEY']);
        self::assertSame('https://file.test', $env['INDEXNOW_BASE_URL']);
        self::assertSame('yandex', $env['INDEXNOW_ENGINES']);
        self::assertSame('/bin', $env['PATH']);
        self::assertSame($before, $_ENV, 'the file is parsed, not loaded');
        self::assertFalse(getenv('INDEXNOW_BASE_URL'));
    }

    #[TestDox('which file: --env-file must exist, --no-env-file reads none, the default .env is read when there and skipped when not')]
    public function testResolve(): void
    {
        self::assertNull(Dotenv::resolve(null, false, $this->dir), 'no .env: silence');
        self::assertSame([], Dotenv::environment(null, false, $this->dir, []));
        file_put_contents($this->dir . '/.env', "INDEXNOW_KEY=filekey12345\n");
        self::assertSame($this->dir . '/.env', Dotenv::resolve(null, false, $this->dir));
        self::assertNull(Dotenv::resolve(null, true, $this->dir), '--no-env-file');
        self::assertSame([], Dotenv::environment(null, true, $this->dir, []));
        file_put_contents($this->dir . '/other.env', "INDEXNOW_KEY=otherkey1234\n");
        self::assertSame($this->dir . '/other.env', Dotenv::resolve($this->dir . '/other.env', false, $this->dir));
        self::assertSame('otherkey1234', Dotenv::environment($this->dir . '/other.env', false, $this->dir, [])['INDEXNOW_KEY']);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('--env-file ' . $this->dir . '/missing.env: no such file');
        Dotenv::resolve($this->dir . '/missing.env', false, $this->dir);
    }

    #[TestDox('a syntax error in the file is a ConfigurationException naming the file')]
    public function testFormatError(): void
    {
        file_put_contents($this->dir . '/.env', "INDEXNOW_KEY=\"unterminated\n");

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage($this->dir . '/.env: ');
        Dotenv::read($this->dir . '/.env');
    }

    #[TestDox('process(): getenv() under $_SERVER under $_ENV, like Config::fromEnv()')]
    public function testProcess(): void
    {
        $_ENV['INDEXNOW_TEST_PROCESS'] = 'env';
        try {
            self::assertSame('env', Dotenv::process()['INDEXNOW_TEST_PROCESS']);
            self::assertArrayHasKey('PATH', Dotenv::process());
        } finally {
            unset($_ENV['INDEXNOW_TEST_PROCESS']);
        }
    }
}
