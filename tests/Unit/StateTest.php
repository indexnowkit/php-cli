<?php

declare(strict_types=1);

namespace IndexNowKit\Cli\Tests\Unit;

use IndexNowKit\Cli\State\SqliteCache;
use IndexNowKit\Cli\State\SqliteSeenStore;
use IndexNowKit\Cli\State\State;
use IndexNowKit\Cli\Tests\Support\Harness;
use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\History\HistoryConfig;
use IndexNowKit\History\Pdo\PdoSubmissionStore;
use PDO;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

final class StateTest extends TestCase
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

    #[TestDox('the path: --state, else INDEXNOW_STATE, else .indexnow/state.sqlite in the working directory; "memory" keeps nothing')]
    public function testResolve(): void
    {
        self::assertSame($this->dir . '/.indexnow/state.sqlite', State::resolve(null, [], $this->dir)->path());
        self::assertSame('/var/lib/indexnow.sqlite', State::resolve(null, ['INDEXNOW_STATE' => '/var/lib/indexnow.sqlite'], $this->dir)->path());
        self::assertSame('/opt/s.sqlite', State::resolve('/opt/s.sqlite', ['INDEXNOW_STATE' => '/var/lib/indexnow.sqlite'], $this->dir)->path(), '--state wins');
        self::assertSame($this->dir . '/.indexnow/state.sqlite', State::resolve(null, ['INDEXNOW_STATE' => ''], $this->dir)->path(), 'an empty variable is unset');
        $memory = State::resolve('memory', [], $this->dir);
        self::assertTrue($memory->isMemory());
        self::assertSame('memory (nothing persists between runs)', $memory->describe());
        self::assertFalse($memory->isReadOnly());
        $memory->cache()->set('k', 1);
        self::assertSame(1, $memory->cache()->get('k'));
    }

    #[TestDox('the file is created on first use with its directory (0700) and the tables; nothing is touched before; the stores share one connection')]
    public function testLazyCreation(): void
    {
        $state = State::resolve(null, [], $this->dir);
        self::assertFileDoesNotExist($state->path());
        self::assertStringContainsString('(not created yet)', $state->describe());
        self::assertFalse($state->isReadOnly());

        $cache = $state->cache();
        self::assertFileExists($state->path());
        self::assertSame('0700', substr(\sprintf('%o', fileperms($this->dir . '/.indexnow')), -4));
        self::assertInstanceOf(SqliteCache::class, $cache);
        self::assertInstanceOf(SqliteSeenStore::class, $state->seenStore());
        self::assertInstanceOf(PdoSubmissionStore::class, $state->submissionStore(HistoryConfig::fromArray(['store' => 'pdo'])));
        self::assertSame($state->path() . ' (writable)', $state->describe());
        $tables = $state->pdo()->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")?->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame(['indexnow_cache', 'indexnow_sitemap_seen', 'indexnow_submissions'], $tables);
        self::assertSame('wal', strtolower((string) $state->pdo()->query('PRAGMA journal_mode')?->fetchColumn()));

        // a second State over the same file sees what the first wrote
        $cache->set('shared', 'yes');
        self::assertSame('yes', State::resolve(null, [], $this->dir)->cache()->get('shared'));
    }

    #[TestDox('a directory that cannot be created is one ConfigurationException with the path and the way out (--state, INDEXNOW_STATE, memory)')]
    public function testReadOnly(): void
    {
        if (posix_geteuid() === 0) {
            self::markTestSkipped('root can write anywhere');
        }
        $locked = $this->dir . '/locked';
        mkdir($locked, 0o500);
        $state = new State($locked . '/sub/state.sqlite');
        try {
            self::assertTrue($state->isReadOnly());
            self::assertStringContainsString('the directory is not writable', $state->describe());
            try {
                $state->pdo();
                self::fail('no exception');
            } catch (ConfigurationException $e) {
                self::assertStringContainsString('state file ' . $locked . '/sub/state.sqlite: the directory ' . $locked . '/sub cannot be created', $e->getMessage());
                self::assertStringContainsString('--state or INDEXNOW_STATE, or --state memory', $e->getMessage());
            }
        } finally {
            chmod($locked, 0o700);
        }
    }
}
