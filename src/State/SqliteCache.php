<?php

declare(strict_types=1);

namespace IndexNowKit\Cli\State;

use DateInterval;
use IndexNowKit\Clock\SystemClock;
use PDO;
use Psr\Clock\ClockInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * PSR-16 over one table of the state file: the debounce window (`Debounce\Psr16DebounceStore`), the 403 counters of
 * the client and the robots cache of the pre-flight share it between cron runs, which is what the shared cache of a
 * framework adapter does for its workers. Keys as PSR-16 spells them (no `{}()/\@:`), values serialized, an expiry
 * as a UTC timestamp on the clock of the graph (`Testing\FrozenClock` in tests); expired rows are never returned and
 * are purged when something is written.
 *
 * @internal the state file and its stores are not API before 1.0 (docs/bc.md)
 */
final class SqliteCache implements CacheInterface
{
    public const TABLE = 'indexnow_cache';

    private readonly ClockInterface $clock;

    public function __construct(private readonly PDO $pdo, ?ClockInterface $clock = null)
    {
        $this->clock = $clock ?? new SystemClock();
    }

    public static function createTable(PDO $pdo): void
    {
        $pdo->exec(\sprintf('CREATE TABLE IF NOT EXISTS %s (key TEXT PRIMARY KEY, value BLOB NOT NULL, expires_at INTEGER NULL)', self::TABLE));
    }

    /**
     * @param string $key
     */
    public function get($key, $default = null): mixed
    {
        $statement = $this->pdo->prepare(\sprintf('SELECT value FROM %s WHERE key = ? AND (expires_at IS NULL OR expires_at > ?)', self::TABLE));
        $statement->execute([self::key($key), $this->now()]);
        $value = $statement->fetchColumn();

        return \is_string($value) ? unserialize($value, ['allowed_classes' => true]) : $default;
    }

    /**
     * @param string                $key
     * @param int|DateInterval|null $ttl
     */
    public function set($key, $value, $ttl = null): bool
    {
        return $this->setMultiple([self::key($key) => $value], $ttl);
    }

    /**
     * @param string $key
     */
    public function delete($key): bool
    {
        return $this->deleteMultiple([self::key($key)]);
    }

    public function clear(): bool
    {
        $this->pdo->exec(\sprintf('DELETE FROM %s', self::TABLE));

        return true;
    }

    /**
     * @param iterable<string> $keys
     *
     * @return iterable<string, mixed>
     */
    public function getMultiple($keys, $default = null): iterable
    {
        $out = [];
        foreach ($keys as $key) {
            $out[self::key($key)] = $this->get($key, $default);
        }

        return $out;
    }

    /**
     * @param iterable<mixed, mixed> $values
     * @param int|DateInterval|null  $ttl
     */
    public function setMultiple($values, $ttl = null): bool
    {
        $expires = $this->expiresAt($ttl);
        $now = $this->now();
        $this->pdo->prepare(\sprintf('DELETE FROM %s WHERE expires_at IS NOT NULL AND expires_at <= ?', self::TABLE))->execute([$now]);
        if ($expires !== null && $expires <= $now) {
            // PSR-16: a TTL of zero or less means "expired already", so the entry is removed rather than written
            $keys = [];
            foreach ($values as $key => $value) {
                $keys[] = self::key($key);
            }

            return $this->deleteMultiple($keys);
        }
        $statement = $this->pdo->prepare(\sprintf('INSERT OR REPLACE INTO %s (key, value, expires_at) VALUES (?, ?, ?)', self::TABLE));
        foreach ($values as $key => $value) {
            $statement->execute([self::key($key), serialize($value), $expires]);
        }

        return true;
    }

    /**
     * @param iterable<string> $keys
     */
    public function deleteMultiple($keys): bool
    {
        $statement = $this->pdo->prepare(\sprintf('DELETE FROM %s WHERE key = ?', self::TABLE));
        foreach ($keys as $key) {
            $statement->execute([self::key($key)]);
        }

        return true;
    }

    /**
     * @param string $key
     */
    public function has($key): bool
    {
        $statement = $this->pdo->prepare(\sprintf('SELECT 1 FROM %s WHERE key = ? AND (expires_at IS NULL OR expires_at > ?)', self::TABLE));
        $statement->execute([self::key($key), $this->now()]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * @throws InvalidCacheKeyException
     */
    private static function key(mixed $key): string
    {
        if (!\is_string($key) || $key === '' || preg_match('/[{}()\/\\\\@:]/', $key) === 1) {
            throw new InvalidCacheKeyException(\sprintf('A cache key must be a non-empty string without {}()/\\@:, got %s.', \is_string($key) ? '"' . $key . '"' : get_debug_type($key)));
        }

        return $key;
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }

    /**
     * @param int|DateInterval|null $ttl
     */
    private function expiresAt(mixed $ttl): ?int
    {
        return match (true) {
            $ttl === null => null,
            $ttl instanceof DateInterval => $this->clock->now()->add($ttl)->getTimestamp(),
            default => $this->now() + $ttl,
        };
    }
}
