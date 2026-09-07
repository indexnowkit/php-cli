<?php

declare(strict_types=1);

namespace IndexNowKit\Cli;

use IndexNowKit\Console\ExitCode;
use IndexNowKit\Console\Vocabulary;
use IndexNowKit\Key\KeyFileResponder;
use IndexNowKit\Key\KeyProviderInterface;
use IndexNowKit\Key\KeyValidator;
use IndexNowKit\Key\StaticKeyProvider;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Body of `key:file <docroot>`: for every host the key provider manages, `<docroot>/<key>.txt` holding the key —
 * or the path `key_location` names when it is on that host — and the same for the previous key while a rotation is
 * open, so a host without a framework serves its key file from the disk the web server already reads. The operator
 * names the directory; nothing outside it is written. One table: host, file (the key masked), what happened.
 */
final class KeyFileRunner
{
    public const WRITTEN = 'written';
    public const UNCHANGED = 'unchanged';
    public const WOULD_WRITE = 'would write';

    public function __construct(private readonly KeyProviderInterface $keys, private readonly Vocabulary $words = new Vocabulary()) {}

    /**
     * @param string       $docroot the document root of the site
     * @param list<string> $hosts   only these hosts (`--host`); [] = every managed host
     * @param bool         $dryRun  print, touch nothing
     *
     * @return int exit code ({@see ExitCode})
     */
    public function run(SymfonyStyle $io, string $docroot, array $hosts, bool $dryRun): int
    {
        $managed = $this->keys->managedHosts();
        if ($managed === []) {
            $io->error('No host to write a key file for: set INDEXNOW_BASE_URL, or INDEXNOW_HOSTS for several hosts.');

            return ExitCode::INVALID;
        }
        $hosts = $hosts === [] ? $managed : array_values(array_unique(array_map(static fn(string $h): string => strtolower(trim($h)), $hosts)));
        $unknown = array_diff($hosts, $managed);
        if ($unknown !== []) {
            $io->error(\sprintf('Host(s) %s are not configured (base_url, hosts); the managed hosts are: %s.', implode(', ', $unknown), implode(', ', $managed)));

            return ExitCode::INVALID;
        }
        $docroot = rtrim($docroot, '/');
        if (!is_dir($docroot)) {
            $io->error(\sprintf('%s is not a directory: give the document root the web server serves as /.', $docroot === '' ? '/' : $docroot));

            return ExitCode::INVALID;
        }
        $responder = new KeyFileResponder($this->keys);
        $rows = [];
        $failed = false;
        foreach ($hosts as $host) {
            foreach ($this->keysOf($host) as $key) {
                $body = $responder->bodyForKey($key, $host);
                if ($body === null) {
                    $rows[] = [$host, KeyValidator::mask($key), 'skipped: the key is invalid (' . KeyValidator::MIN_LENGTH . '-' . KeyValidator::MAX_LENGTH . ' characters, [A-Za-z0-9-])'];
                    $failed = true;

                    continue;
                }
                $file = $this->pathOf($docroot, $host, $key);
                $status = self::write($file, $body, $dryRun);
                $failed = $failed || $status === null;
                $rows[] = [$host, self::mask($file, $key), $status ?? 'cannot write'];
            }
        }
        $io->table(['host', 'file', 'status'], $rows);
        if ($failed) {
            $io->error('Some key files could not be written; check the permissions of the document root.');

            return ExitCode::FAILURE;
        }
        $io->text(\sprintf('Verify with: %s %s --live', $this->words->cli, $this->words->check));

        return ExitCode::SUCCESS;
    }

    /**
     * The current key and, while a rotation is open, the previous one (the engines keep verifying against it for a while).
     *
     * @return list<string>
     */
    private function keysOf(string $host): array
    {
        $keys = [];
        $key = $this->keys->keyFor($host);
        if ($key !== null) {
            $keys[] = $key;
        }
        $previous = $this->keys instanceof StaticKeyProvider ? $this->keys->previousKeyFor($host) : null;
        if ($previous !== null && $previous !== $key) {
            $keys[] = $previous;
        }

        return $keys;
    }

    /**
     * `<docroot>/<key>.txt`, or the path of `key_location` when the location is on this host and names that key
     * (a location on another host is not this docroot's business; `check` reports it).
     */
    private function pathOf(string $docroot, string $host, string $key): string
    {
        $location = $this->keys->keyLocationFor($host);
        if ($location !== null && strcasecmp((string) parse_url($location, PHP_URL_HOST), $host) === 0) {
            $path = parse_url($location, PHP_URL_PATH);
            if (\is_string($path) && str_contains($path, $key) && !str_contains($path, '..')) {
                return $docroot . '/' . ltrim($path, '/');
            }
        }

        return $docroot . '/' . $key . '.txt';
    }

    /** The status of the row, null when the file could not be written. */
    private static function write(string $file, string $body, bool $dryRun): ?string
    {
        if (is_file($file) && file_get_contents($file) === $body) {
            return self::UNCHANGED;
        }
        if ($dryRun) {
            return self::WOULD_WRITE;
        }
        $dir = \dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0o755, true) && !is_dir($dir)) {
            return null;
        }

        return @file_put_contents($file, $body) === \strlen($body) ? self::WRITTEN : null;
    }

    private static function mask(string $file, string $key): string
    {
        return str_replace($key, KeyValidator::mask($key), $file);
    }
}
