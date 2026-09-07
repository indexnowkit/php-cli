<?php

declare(strict_types=1);

namespace IndexNowKit\Cli\Tests\Unit;

use DateTimeImmutable;
use IndexNowKit\Cli\State\SqliteSeenStore;
use IndexNowKit\Sitemap\SitemapEntry;
use IndexNowKit\Testing\FrozenClock;
use PDO;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SqliteSeenStoreTest extends TestCase
{
    private PDO $pdo;
    private SqliteSeenStore $store;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        SqliteSeenStore::createTable($this->pdo);
        $this->store = new SqliteSeenStore($this->pdo, new FrozenClock('2026-09-08 12:00:00 UTC'));
    }

    /**
     * @param list<SitemapEntry> $entries
     *
     * @return list<string>
     */
    private function unseen(array $entries): array
    {
        $urls = [];
        foreach ($this->store->unseen($entries) as $entry) {
            $urls[] = $entry->url;
        }

        return $urls;
    }

    #[TestDox('new, a changed lastmod, a lastmod that appears or disappears are unseen; the same again is not; without lastmod an entry is new once')]
    public function testUnseenAndRemember(): void
    {
        $a = new SitemapEntry('https://www.example.com/a', new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
        $b = new SitemapEntry('https://www.example.com/b', null);
        self::assertSame(['https://www.example.com/a', 'https://www.example.com/b'], $this->unseen([$a, $b]), 'everything is new at first');
        self::assertSame(0, $this->store->count(), 'unseen() writes nothing');

        $this->store->remember([$a, $b]);
        self::assertSame(2, $this->store->count());
        self::assertSame([], $this->unseen([$a, $b]), 'the same fingerprints again');
        self::assertSame([], $this->unseen([new SitemapEntry('https://www.example.com/b', null)]), 'no lastmod, seen once: known');

        $changed = new SitemapEntry('https://www.example.com/a', new DateTimeImmutable('2026-02-01T00:00:00+00:00'));
        $gained = new SitemapEntry('https://www.example.com/b', new DateTimeImmutable('2026-02-01T00:00:00+00:00'));
        self::assertSame(['https://www.example.com/a', 'https://www.example.com/b'], $this->unseen([$changed, $gained]));
        $this->store->remember([$changed]);
        self::assertSame([], $this->unseen([$changed]));
        self::assertSame(['https://www.example.com/a'], $this->unseen([$a]), 'the old lastmod is a change too');
        self::assertSame(2, $this->store->count(), 'INSERT OR REPLACE: one row per URL');
        self::assertSame(1788868800, (int) $this->pdo->query('SELECT seen_at FROM ' . SqliteSeenStore::TABLE . " WHERE url = 'https://www.example.com/a'")->fetchColumn(), 'seen_at is the clock of the graph');
    }

    #[TestDox('remember() is one transaction: a failure in the middle leaves nothing of the batch')]
    public function testRememberIsAtomic(): void
    {
        $entries = static function (): iterable {
            yield new SitemapEntry('https://www.example.com/1', null);
            throw new RuntimeException('boom');
        };
        try {
            $this->store->remember($entries());
            self::fail('no exception');
        } catch (RuntimeException) {
        }
        self::assertSame(0, $this->store->count());
        self::assertFalse($this->pdo->inTransaction());

        $this->pdo->beginTransaction();
        $this->store->remember([new SitemapEntry('https://www.example.com/2', null)]);
        self::assertTrue($this->pdo->inTransaction(), 'an open transaction of the caller is left to the caller');
        $this->pdo->commit();
        self::assertSame(1, $this->store->count());
    }

    #[TestDox('the fingerprint is the URL plus the lastmod in ISO 8601, or empty')]
    public function testFingerprint(): void
    {
        self::assertSame("https://www.example.com/a\n2026-01-01T00:00:00+00:00", SqliteSeenStore::fingerprint(new SitemapEntry('https://www.example.com/a', new DateTimeImmutable('2026-01-01T00:00:00+00:00'))));
        self::assertSame("https://www.example.com/a\n", SqliteSeenStore::fingerprint(new SitemapEntry('https://www.example.com/a', null)));
    }
}
