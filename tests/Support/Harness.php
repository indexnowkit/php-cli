<?php

declare(strict_types=1);

namespace IndexNowKit\Cli\Tests\Support;

use FilesystemIterator;
use IndexNowKit\Cli\Application;
use IndexNowKit\Cli\GlobalOptions;
use IndexNowKit\Cli\Wiring;
use IndexNowKit\Testing\FakeTransport;
use IndexNowKit\Testing\FrozenClock;
use LogicException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * The `indexnow` application of the feature tests: a temporary working directory (the `.env`, the state file, the
 * document root of `key:file` live there), an environment handed in as an array instead of the process one, a
 * `FakeTransport` for the engines and the key files, a `FrozenClock`. Everything else is the real wiring
 * ({@see Application::wiring()}): what a user runs, minus the network.
 */
final class Harness
{
    public const KEY = 'abcdef1234567890abcdef1234567890';

    public FakeTransport $transport;
    public FrozenClock $clock;
    public string $dir;
    /** @var array<string, string> */
    public array $env;
    private ?ApplicationTester $tester = null;

    /**
     * @param array<string, string> $env the process environment of the run (`INDEXNOW_*`); the defaults give a working single-site setup
     */
    public function __construct(array $env = [], bool $defaults = true)
    {
        $this->transport = new FakeTransport();
        $this->clock = new FrozenClock('2026-09-08 12:00:00 UTC');
        $this->dir = self::tempDir();
        // strict_hosts: the production warning about it would otherwise colour every report; debounce off: the clock is frozen
        $this->env = $env + ($defaults ? ['INDEXNOW_KEY' => self::KEY, 'INDEXNOW_BASE_URL' => 'https://www.example.com', 'INDEXNOW_ENV' => 'prod', 'INDEXNOW_STRICT_HOSTS' => 'true', 'INDEXNOW_DEBOUNCE_PER_URL' => '0'] : []);
        $this->transport->onGet('https://www.example.com/' . self::KEY . '.txt', new \IndexNowKit\Http\Response(200, self::KEY, headers: ['Content-Type' => 'text/plain']));
    }

    public function application(): Application
    {
        return new Application(fn(GlobalOptions $options, OutputInterface $output): Wiring => Application::wiring($options, $output, $this->env, $this->transport, $this->clock), $this->dir);
    }

    /** The tester of the last run (a symfony/console application caches its commands: every run is a fresh process, as in life). */
    public function tester(): ApplicationTester
    {
        if ($this->tester === null) {
            throw new LogicException('run() first');
        }

        return $this->tester;
    }

    /**
     * @param array<string, mixed> $input `['command' => 'check', '--json' => true]`
     */
    public function run(array $input): int
    {
        $application = $this->application();
        $application->setAutoExit(false);
        $application->setCatchExceptions(false);
        $this->tester = new ApplicationTester($application);

        return $this->tester->run($input, ['capture_stderr_separately' => true]);
    }

    public function display(): string
    {
        return $this->tester()->getDisplay();
    }

    public function errorOutput(): string
    {
        return $this->tester()->getErrorOutput();
    }

    /**
     * The URLs of every POST the engines saw, in order.
     *
     * @return list<string>
     */
    public function sentUrls(): array
    {
        $urls = [];
        foreach ($this->transport->posts as $post) {
            $urls = [...$urls, ...$post['body']['urlList']];
        }

        return $urls;
    }

    public function statePath(): string
    {
        return $this->dir . '/.indexnow/state.sqlite';
    }

    public function file(string $name, string $contents): string
    {
        $path = $this->dir . '/' . $name;
        if (!is_dir(\dirname($path))) {
            mkdir(\dirname($path), 0o777, true);
        }
        file_put_contents($path, $contents);

        return $path;
    }

    public static function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/indexnow-cli-' . bin2hex(random_bytes(6));
        mkdir($dir, 0o777, true);

        return $dir;
    }

    public static function remove(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
            if ($item instanceof SplFileInfo) {
                @chmod($item->getPathname(), 0o777);
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
        }
        @rmdir($dir);
    }

    public function __destruct()
    {
        self::remove($this->dir);
    }
}
