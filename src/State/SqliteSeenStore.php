<?php

declare(strict_types=1);

namespace IndexNowKit\Cli\State;

use IndexNowKit\Clock\SystemClock;
use IndexNowKit\Sitemap\SeenStoreInterface;
use IndexNowKit\Sitemap\SitemapEntry;
use PDO;
use Psr\Clock\ClockInterface;
use Throwable;

/**
 * The store of seen sitemap URLs behind `sitemap --new-only` (`Sitemap\SeenStoreInterface`), one table of the state
 * file: the URL, its fingerprint (URL plus `<lastmod>` in ISO 8601, or empty) and when it was last announced.
 * `unseen()` looks each entry up as it streams by; `remember()` writes a batch in one transaction.
 *
 * @internal the state file and its stores are not API before 1.0 (docs/bc.md)
 */
final class SqliteSeenStore implements SeenStoreInterface
{
    public const TABLE = 'indexnow_sitemap_seen';

    private readonly ClockInterface $clock;

    public function __construct(private readonly PDO $pdo, ?ClockInterface $clock = null)
    {
        $this->clock = $clock ?? new SystemClock();
    }

    public static function createTable(PDO $pdo): void
    {
        $pdo->exec(\sprintf('CREATE TABLE IF NOT EXISTS %s (url TEXT PRIMARY KEY, fingerprint TEXT NOT NULL, seen_at INTEGER NOT NULL)', self::TABLE));
    }

    public static function fingerprint(SitemapEntry $entry): string
    {
        return $entry->url . "\n" . ($entry->lastmod?->format(DATE_ATOM) ?? '');
    }

    public function unseen(iterable $entries): iterable
    {
        $statement = $this->pdo->prepare(\sprintf('SELECT fingerprint FROM %s WHERE url = ?', self::TABLE));
        foreach ($entries as $entry) {
            $statement->execute([$entry->url]);
            $known = $statement->fetchColumn();
            if ($known === false || $known !== self::fingerprint($entry)) {
                yield $entry;
            }
        }
    }

    public function remember(iterable $entries): void
    {
        $statement = $this->pdo->prepare(\sprintf('INSERT OR REPLACE INTO %s (url, fingerprint, seen_at) VALUES (?, ?, ?)', self::TABLE));
        $at = $this->clock->now()->getTimestamp();
        $own = !$this->pdo->inTransaction();
        if ($own) {
            $this->pdo->beginTransaction();
        }
        try {
            foreach ($entries as $entry) {
                $statement->execute([$entry->url, self::fingerprint($entry), $at]);
            }
            if ($own) {
                $this->pdo->commit();
            }
        } catch (Throwable $e) {
            if ($own && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    /** How many URLs the store knows (the `check` line). */
    public function count(): int
    {
        $statement = $this->pdo->query(\sprintf('SELECT COUNT(*) FROM %s', self::TABLE));

        return $statement === false ? 0 : (int) $statement->fetchColumn();
    }
}
