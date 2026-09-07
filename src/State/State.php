<?php

declare(strict_types=1);

namespace IndexNowKit\Cli\State;

use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\History\Adapter\HistoryServices;
use IndexNowKit\History\HistoryConfig;
use IndexNowKit\History\Pdo\PdoSubmissionStore;
use PDO;
use PDOException;
use Psr\Clock\ClockInterface;

/**
 * The one place `indexnow` keeps what makes scheduled runs idempotent: the debounce window and the 403 counters
 * ({@see SqliteCache}), the submission history (`History\Pdo\PdoSubmissionStore`) and the seen sitemap URLs
 * ({@see SqliteSeenStore}) — one sqlite file, `.indexnow/state.sqlite` in the working directory unless `--state` or
 * `INDEXNOW_STATE` says otherwise; `memory` keeps nothing between runs (a read-only container, a one-off `--dry-run`).
 * Nothing is opened before a store is asked for: `list`, `--version` and a broken configuration never touch the file.
 *
 * @internal the state file and its stores are not API before 1.0 (docs/bc.md); the commands and the variables are
 */
final class State
{
    public const MEMORY = 'memory';
    public const DEFAULT_PATH = '.indexnow/state.sqlite';
    public const VARIABLE = 'INDEXNOW_STATE';
    /** The `debounce.store` id that names this file (the default of the CLI). */
    public const STORE_ID = 'state';

    private ?PDO $pdo = null;
    private ?SqliteCache $cache = null;
    private ?SqliteSeenStore $seen = null;

    /**
     * @param string $path an absolute or relative file path, or {@see MEMORY}
     */
    public function __construct(private readonly string $path, private readonly ?ClockInterface $clock = null) {}

    /**
     * `--state`, else `INDEXNOW_STATE`, else `<workingDir>/.indexnow/state.sqlite`; a relative path is taken as given (relative to the working directory).
     *
     * @param array<string, mixed> $env
     */
    public static function resolve(?string $option, array $env, string $workingDir, ?ClockInterface $clock = null): self
    {
        $variable = $env[self::VARIABLE] ?? null;
        $path = $option ?? (\is_string($variable) && $variable !== '' ? $variable : rtrim($workingDir, '/') . '/' . self::DEFAULT_PATH);

        return new self($path, $clock);
    }

    public function path(): string
    {
        return $this->path;
    }

    public function isMemory(): bool
    {
        return $this->path === self::MEMORY;
    }

    /**
     * One line for `check` and `status`: `memory (nothing persists between runs)`, `<path> (writable)`,
     * `<path> (read-only)`, `<path> (not created yet)`; whether the line is a problem is {@see isReadOnly()}.
     */
    public function describe(): string
    {
        if ($this->isMemory()) {
            return self::MEMORY . ' (nothing persists between runs)';
        }
        if (is_file($this->path)) {
            return \sprintf('%s (%s)', $this->path, is_writable($this->path) && is_writable(\dirname($this->path)) ? 'writable' : 'read-only');
        }

        return \sprintf('%s (not created yet%s)', $this->path, $this->isReadOnly() ? ', the directory is not writable' : '');
    }

    /** True when the file exists and cannot be written, or does not exist and cannot be created. */
    public function isReadOnly(): bool
    {
        if ($this->isMemory()) {
            return false;
        }
        if (is_file($this->path)) {
            return !is_writable($this->path) || !is_writable(\dirname($this->path));
        }
        $dir = \dirname($this->path);
        while (!is_dir($dir)) {
            $parent = \dirname($dir);
            if ($parent === $dir) {
                return true;
            }
            $dir = $parent;
        }

        return !is_writable($dir);
    }

    /**
     * The connection, opened on first use: the directory created (`0700`, the file holds the key's URL history),
     * WAL journal so a cron run and a manual one do not lock each other out, the tables created if missing.
     *
     * @throws ConfigurationException when the file cannot be created or opened, with the path and the way out
     */
    public function pdo(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }
        if (!$this->isMemory()) {
            $dir = \dirname($this->path);
            if (!is_dir($dir) && !@mkdir($dir, 0o700, true) && !is_dir($dir)) {
                throw $this->cannot(\sprintf('the directory %s cannot be created', $dir));
            }
        }
        try {
            $pdo = new PDO($this->isMemory() ? 'sqlite::memory:' : 'sqlite:' . $this->path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            if (!$this->isMemory()) {
                $pdo->exec('PRAGMA journal_mode=WAL');
            }
            $pdo->exec('PRAGMA busy_timeout=5000');
            SqliteCache::createTable($pdo);
            SqliteSeenStore::createTable($pdo);
        } catch (PDOException $e) {
            throw $this->cannot($e->getMessage(), $e);
        }

        return $this->pdo = $pdo;
    }

    /** The PSR-16 cache of the state: the debounce store `state`, the 403 counters, the robots cache of the pre-flight. */
    public function cache(): SqliteCache
    {
        return $this->cache ??= new SqliteCache($this->pdo(), $this->clock);
    }

    /** The store of seen sitemap URLs (`sitemap --new-only`). */
    public function seenStore(): SqliteSeenStore
    {
        return $this->seen ??= new SqliteSeenStore($this->pdo(), $this->clock);
    }

    /**
     * The `pdo` history store over this file (`history.pdo.dsn` unset), its table created if missing.
     *
     * @throws ConfigurationException
     */
    public function submissionStore(HistoryConfig $history): PdoSubmissionStore
    {
        $store = HistoryServices::pdoStore($this->pdo(), $history);
        $store->createTable();

        return $store;
    }

    private function cannot(string $problem, ?PDOException $previous = null): ConfigurationException
    {
        return new ConfigurationException(\sprintf('state file %s: %s. Give a writable path with --state or %s, or --state %s for a run that keeps nothing.', $this->path, rtrim($problem, '.'), self::VARIABLE, self::MEMORY), 0, $previous);
    }
}
