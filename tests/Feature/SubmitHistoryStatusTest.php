<?php

declare(strict_types=1);

namespace IndexNowKit\Cli\Tests\Feature;

use IndexNowKit\Cli\Tests\Support\Harness;
use IndexNowKit\Console\ExitCode;
use IndexNowKit\Http\Response;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

final class SubmitHistoryStatusTest extends TestCase
{
    #[TestDox('submit sends the URLs through the explicit transport and records them in the state file; a second run within the debounce window is skipped, --force sends again; --dry-run sends nothing')]
    public function testSubmitWithDebounceAndHistory(): void
    {
        $harness = new Harness(['INDEXNOW_DEBOUNCE_PER_URL' => '600']);

        self::assertSame(ExitCode::SUCCESS, $harness->run(['command' => 'submit', 'urls' => ['https://www.example.com/a', '/b']]));
        self::assertSame(['https://www.example.com/a', 'https://www.example.com/b'], $harness->sentUrls());
        self::assertStringContainsString('api      www.example.com   2      ok       200', $harness->display());
        self::assertSame('https://api.indexnow.org/indexnow', $harness->transport->posts[0]['url']);

        // the window lives in the state file: a new process (a new Harness over the same directory) still sees it
        $second = new Harness(['INDEXNOW_DEBOUNCE_PER_URL' => '600']);
        $second->dir = $harness->dir;
        self::assertSame(ExitCode::SUCCESS, $second->run(['command' => 'submit', 'urls' => ['https://www.example.com/a'], '--json' => true]));
        self::assertSame([], $second->transport->posts, 'debounced between runs');
        self::assertStringContainsString('"reason": "debounced"', $second->display());

        self::assertSame(ExitCode::SUCCESS, $second->run(['command' => 'submit', 'urls' => ['https://www.example.com/a'], '--force' => true]));
        self::assertCount(1, $second->transport->posts, '--force ignores the window');

        self::assertSame(ExitCode::SUCCESS, $second->run(['command' => 'submit', 'urls' => ['https://www.example.com/c'], '--dry-run' => true]));
        self::assertCount(1, $second->transport->posts, '--dry-run sends nothing');

        // history: the two runs that sent, from the state file
        self::assertSame(ExitCode::SUCCESS, $second->run(['command' => 'history', '--json' => true]));
        $records = json_decode($second->display(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($records);
        self::assertCount(4, $records['records'], 'the two submissions, the debounced run and the dry run are recorded');
        self::assertSame(['https://www.example.com/c'], $records['records'][0]['urls'], 'newest first: the dry run');
        self::assertSame('skipped', $records['records'][0]['status']);
        self::assertSame(['https://www.example.com/a'], $records['records'][1]['urls']);
        self::assertSame('ok', $records['records'][1]['status']);

        self::assertSame(ExitCode::SUCCESS, $second->run(['command' => 'history', '--status' => 'ok']));
        self::assertStringContainsString('2 record(s)', $second->display());

        // status: the debounce store of the state file, the history size, the last success
        self::assertSame(ExitCode::SUCCESS, $second->run(['command' => 'status']));
        $display = $second->display();
        self::assertStringContainsString('debounce: 600s, store state (SqliteCache)', $display);
        self::assertStringContainsString('history: 4 records; last successful submission', $display);
        self::assertStringContainsString('www.example.com: 0 consecutive 403', $display);

        $second->dir = Harness::tempDir(); // the destructor of $second must not remove the directory of $harness twice
    }

    #[TestDox('a 403 is counted in the state file across runs; status shows it; history --purge removes old records; history.store off keeps nothing')]
    public function testForbiddenCounterAndPurge(): void
    {
        $harness = new Harness();
        $harness->clock = new \IndexNowKit\Testing\FrozenClock('2025-01-01 12:00:00 UTC'); // the records land in the past of the wall clock history --purge counts from
        $harness->transport->willRespond(new Response(403, 'forbidden'), new Response(403, 'forbidden'));

        self::assertSame(ExitCode::FAILURE, $harness->run(['command' => 'submit', 'urls' => ['https://www.example.com/a']]));
        self::assertSame(ExitCode::FAILURE, $harness->run(['command' => 'submit', 'urls' => ['https://www.example.com/b']]));
        self::assertSame(ExitCode::SUCCESS, $harness->run(['command' => 'status', '--json' => true]));
        $status = json_decode($harness->display(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($status);
        self::assertSame(2, $status['hosts'][0]['forbidden'], 'the counter lives in the state file');
        self::assertSame('history', $status['history']['store']);
        self::assertSame(2, $status['history']['records']);

        self::assertSame(ExitCode::SUCCESS, $harness->run(['command' => 'history', '--purge' => null]));
        self::assertStringContainsString('purged 2 records', $harness->display());

        $off = new Harness(['INDEXNOW_HISTORY_STORE' => 'none']);
        self::assertSame(ExitCode::SUCCESS, $off->run(['command' => 'submit', 'urls' => ['https://www.example.com/a']]));
        self::assertSame(ExitCode::SUCCESS, $off->run(['command' => 'history']));
        self::assertStringContainsString('history.store is null, nothing is recorded', $off->display());
        self::assertSame(ExitCode::SUCCESS, $off->run(['command' => 'check']));
        self::assertStringContainsString('history: installed, no store configured', $off->display());
    }

    #[TestDox('history over a sqlite DSN of its own is created like the state file; psr16 lives in the cache of the state file')]
    public function testHistoryStores(): void
    {
        $harness = new Harness(['INDEXNOW_HISTORY_PDO_DSN' => 'sqlite:' . Harness::tempDir() . '/h.sqlite']);
        self::assertSame(ExitCode::SUCCESS, $harness->run(['command' => 'submit', 'urls' => ['https://www.example.com/a']]));
        self::assertSame(ExitCode::SUCCESS, $harness->run(['command' => 'history']));
        self::assertStringContainsString('1 record(s)', $harness->display());

        $psr16 = new Harness(['INDEXNOW_HISTORY_STORE' => 'psr16']);
        self::assertSame(ExitCode::SUCCESS, $psr16->run(['command' => 'submit', 'urls' => ['https://www.example.com/a']]));
        self::assertSame(ExitCode::SUCCESS, $psr16->run(['command' => 'check']));
        self::assertStringContainsString('history: psr16 store (500 records kept)', $psr16->display());
        self::assertStringContainsString('history: 1 records, last', $psr16->display());

        $service = new Harness(['INDEXNOW_HISTORY_PDO_SERVICE' => 'doctrine']);
        self::assertSame(ExitCode::FAILURE, $service->run(['command' => 'submit', 'urls' => ['https://www.example.com/a']]));
        self::assertStringContainsString('history.pdo.service "doctrine" names a connection of a framework', $service->errorOutput());
    }

    #[TestDox('verify.enabled: the pre-flight GETs every URL through the transport before the submission, and check --live still probes; a noindex page is skipped')]
    public function testVerify(): void
    {
        $harness = new Harness(['INDEXNOW_VERIFY_ENABLED' => 'true']);
        $harness->transport->onGet('https://www.example.com/a', new Response(200, '<html><head><title>a</title></head></html>', headers: ['Content-Type' => 'text/html']));
        $harness->transport->onGet('https://www.example.com/b', new Response(200, '<html><head><meta name="robots" content="noindex"></head></html>', headers: ['Content-Type' => 'text/html']));

        self::assertSame(ExitCode::SUCCESS, $harness->run(['command' => 'submit', 'urls' => ['https://www.example.com/a', 'https://www.example.com/b'], '--json' => true]));
        self::assertSame(['https://www.example.com/a'], $harness->sentUrls(), 'the noindex page is skipped by the pre-flight');
        self::assertContains('https://www.example.com/a', $harness->transport->gets);
        self::assertContains('https://www.example.com/b', $harness->transport->gets);

        self::assertSame(ExitCode::SUCCESS, $harness->run(['command' => 'check']));
        self::assertStringContainsString('verify: enabled (redirect: skip, non_canonical: skip, origin_error: skip)', $harness->display());
    }
}
