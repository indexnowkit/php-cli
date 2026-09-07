<?php

declare(strict_types=1);

namespace IndexNowKit\Cli\Tests\Unit;

use DateInterval;
use IndexNowKit\Cli\State\InvalidCacheKeyException;
use IndexNowKit\Cli\State\SqliteCache;
use IndexNowKit\Debounce\Psr16DebounceStore;
use IndexNowKit\Testing\FrozenClock;
use PDO;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\InvalidArgumentException;

final class SqliteCacheTest extends TestCase
{
    private FrozenClock $clock;
    private PDO $pdo;
    private SqliteCache $cache;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock('2026-09-08 12:00:00 UTC');
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        SqliteCache::createTable($this->pdo);
        $this->cache = new SqliteCache($this->pdo, $this->clock);
    }

    #[TestDox('PSR-16: get/set/has/delete/clear and the multiple forms, any serializable value, the default when absent')]
    public function testBasics(): void
    {
        self::assertNull($this->cache->get('a'));
        self::assertSame('dflt', $this->cache->get('a', 'dflt'));
        self::assertFalse($this->cache->has('a'));
        self::assertTrue($this->cache->set('a', ['x' => 1, 'y' => [2, 3]]));
        self::assertSame(['x' => 1, 'y' => [2, 3]], $this->cache->get('a'));
        self::assertTrue($this->cache->has('a'));
        self::assertTrue($this->cache->set('n', null));
        self::assertTrue($this->cache->has('n'), 'a stored null is a hit');
        self::assertTrue($this->cache->setMultiple(['b' => 1, 'c' => false]));
        self::assertSame(['a' => ['x' => 1, 'y' => [2, 3]], 'b' => 1, 'z' => 'd'], iterator_to_array_or_array($this->cache->getMultiple(['a', 'b', 'z'], 'd')));
        self::assertFalse($this->cache->get('c'));
        self::assertTrue($this->cache->delete('a'));
        self::assertFalse($this->cache->has('a'));
        self::assertTrue($this->cache->deleteMultiple(['b', 'missing']));
        self::assertNull($this->cache->get('b'));
        self::assertTrue($this->cache->clear());
        self::assertFalse($this->cache->has('c'));
    }

    #[TestDox('a TTL expires on the clock of the graph: an expired entry is a miss, is purged on the next write, a DateInterval works too, a TTL of zero deletes')]
    public function testTtl(): void
    {
        $this->cache->set('t', 'v', 60);
        $this->cache->set('i', 'w', new DateInterval('PT120S'));
        $this->cache->set('forever', 'f');
        $this->clock->advance(59);
        self::assertSame('v', $this->cache->get('t'));
        $this->clock->advance(1);
        self::assertNull($this->cache->get('t'), 'expired at exactly the TTL');
        self::assertFalse($this->cache->has('t'));
        self::assertSame('w', $this->cache->get('i'));
        self::assertSame(1, $this->rows(), 'the expired row is still there');
        $this->cache->set('other', 1);
        self::assertSame(0, $this->rows('t'), 'purged by the write');
        $this->clock->advance(60);
        self::assertNull($this->cache->get('i'));
        self::assertSame('f', $this->cache->get('forever'));
        $this->cache->set('forever', 'gone', 0);
        self::assertFalse($this->cache->has('forever'), 'TTL 0 means expired already');
    }

    #[TestDox('a key with a PSR-16 reserved character, an empty one or a non-string is the PSR-16 InvalidArgumentException')]
    public function testInvalidKey(): void
    {
        foreach (['', 'a:b', 'a{b', 'a}b', 'a(b', 'a)b', 'a/b', 'a\\b', 'a@b'] as $key) {
            try {
                $this->cache->get($key);
                self::fail('no exception for ' . $key);
            } catch (InvalidCacheKeyException $e) {
                self::assertInstanceOf(InvalidArgumentException::class, $e);
            }
        }
        $this->expectException(InvalidCacheKeyException::class);
        $this->cache->setMultiple([5 => 'int key']);
    }

    #[TestDox('the debounce store of the core works over it: a URL sent within the window is recent, past it is not')]
    public function testDebounceStore(): void
    {
        $store = new Psr16DebounceStore($this->cache, 'indexnow_');
        $store->markSubmitted(['https://www.example.com/a'], 600);
        self::assertSame(['https://www.example.com/a'], $store->filterRecent(['https://www.example.com/a', 'https://www.example.com/b'], 600));
        $this->clock->advance(600);
        self::assertSame([], $store->filterRecent(['https://www.example.com/a'], 600));
    }

    private function rows(?string $key = null): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM ' . SqliteCache::TABLE . ($key === null ? ' WHERE expires_at IS NOT NULL AND expires_at <= ?' : ' WHERE key = ?'));
        $statement->execute([$key ?? $this->clock->now()->getTimestamp()]);

        return (int) $statement->fetchColumn();
    }
}

/**
 * @param iterable<string, mixed> $values
 *
 * @return array<string, mixed>
 */
function iterator_to_array_or_array(iterable $values): array
{
    return \is_array($values) ? $values : iterator_to_array($values);
}
