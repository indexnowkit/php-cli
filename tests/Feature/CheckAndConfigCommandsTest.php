<?php

declare(strict_types=1);

namespace IndexNowKit\Cli\Tests\Feature;

use IndexNowKit\Cli\Tests\Support\Harness;
use IndexNowKit\Console\ExitCode;
use IndexNowKit\Http\Response;
use JsonSchema\Validator;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

final class CheckAndConfigCommandsTest extends TestCase
{
    #[TestDox('check --json is valid by the check.schema.json of indexnowkit/console and carries the lines of the CLI: the sources, the state, debounce over the state file, history pdo, sitemap spool, verify off')]
    public function testCheckJson(): void
    {
        $harness = new Harness(['INDEXNOW_DEBOUNCE_PER_URL' => '600']);

        self::assertSame(ExitCode::SUCCESS, $harness->run(['command' => 'check', '--json' => true]), $harness->display());
        $document = json_decode($harness->display());
        $validator = new Validator();
        $validator->validate($document, json_decode((string) file_get_contents(\dirname(__DIR__, 3) . '/console/docs/check.schema.json')));
        self::assertTrue($validator->isValid(), json_encode($validator->getErrors()));
        $report = json_decode($harness->display(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($report);
        self::assertSame('ok', $report['status']);
        self::assertSame('prod', $report['environment']);
        $byCode = [];
        foreach ($report['items'] as $item) {
            $byCode[$item['code']][] = $item['message'];
        }
        self::assertSame(['configuration: the INDEXNOW_* variables'], $byCode['cli.sources']);
        self::assertSame(['state: ' . $harness->statePath() . ' (writable)'], $byCode['cli.state']);
        self::assertSame(['debounce: 600s per URL, shared through store "state" (' . $harness->statePath() . ')'], $byCode['debounce.store']);
        self::assertSame(['history: pdo store (indexnow_submissions)'], $byCode['history.store']);
        self::assertSame(['history: no records yet'], $byCode['history.records']);
        self::assertStringStartsWith('sitemap: documents are spooled to temp files', $byCode['sitemap.spool'][0]);
        self::assertSame(['verify: installed, disabled (verify.enabled: false)'], $byCode['verify.installed']);
        self::assertSame(['verify sample: no sample given (check --sample=<url>, --sample-class=<class>)'], $byCode['verify.sample']);
        self::assertArrayNotHasKey('config.unknown', $byCode);
        self::assertArrayNotHasKey('verify.dispatch', $byCode, 'no queue to recommend');
        self::assertSame(['https://www.example.com/' . Harness::KEY . '.txt', 'https://www.example.com/robots.txt'], $harness->transport->gets);
        self::assertFileExists($harness->statePath(), 'the debounce probe wrote the state file');
    }

    #[TestDox('check: an unknown INDEXNOW_* variable and an unknown key of the JSON file are warnings with the code config.unknown; --strict fails on them')]
    public function testUnknownConfiguration(): void
    {
        $harness = new Harness(['INDEXNOW_DEBOUNCE_PER_URLS' => '5', 'INDEXNOW_QUEUE_CONNECTION' => 'redis']);
        $file = $harness->file('indexnow.json', json_encode(['sitemap' => ['max_depht' => 2]]));

        self::assertSame(ExitCode::SUCCESS, $harness->run(['command' => 'check', '--config' => $file]));
        $display = $harness->display();
        self::assertStringContainsString('unknown variable(s) INDEXNOW_DEBOUNCE_PER_URLS, INDEXNOW_QUEUE_CONNECTION', $display);
        self::assertStringContainsString('unknown option(s) in ' . $file . ': sitemap.max_depht', $display);
        self::assertStringContainsString('configuration: the INDEXNOW_* variables, over ' . $file, $display);
        self::assertStringContainsString('IndexNow is ready.', $display);
        self::assertSame(ExitCode::FAILURE, $harness->run(['command' => 'check', '--config' => $file, '--strict' => true]));
    }

    #[TestDox('check --live sends the probe through the explicit transport; --sample fetches the page through the pre-flight transport (verify is installed); --sample-class says there is no ORM here')]
    public function testLiveAndSample(): void
    {
        $harness = new Harness(['INDEXNOW_ENGINES' => 'yandex']);
        $harness->transport->onGet('https://www.example.com/about', new Response(200, '<html><head><title>x</title></head></html>', headers: ['Content-Type' => 'text/html']));

        self::assertSame(ExitCode::SUCCESS, $harness->run(['command' => 'check', '--live' => true, '--sample' => ['https://www.example.com/about'], '--sample-class' => ['App\\Post']]));
        $display = $harness->display();
        self::assertStringContainsString('accepted probe (200)', $display);
        self::assertCount(1, $harness->transport->posts);
        self::assertSame(['https://www.example.com/'], $harness->transport->posts[0]['body']['urlList']);
        self::assertStringContainsString('verify sample https://www.example.com/about: HTTP 200, index, canonical: self, robots: allowed', $display);
        self::assertStringContainsString('--sample-class is not supported by this adapter; give URLs with --sample', $display);
    }

    #[TestDox('check: a state file that cannot be written is a warning line, a memory state says so; --state memory keeps nothing')]
    public function testStateLines(): void
    {
        $harness = new Harness();
        self::assertSame(ExitCode::SUCCESS, $harness->run(['command' => 'check', '--state' => 'memory']));
        self::assertStringContainsString('state: memory (nothing persists between runs)', $harness->display());
        self::assertFileDoesNotExist($harness->statePath());

        if (posix_geteuid() !== 0) {
            mkdir($harness->dir . '/ro', 0o500);
            self::assertSame(ExitCode::SUCCESS, $harness->run(['command' => 'check', '--state' => $harness->dir . '/ro/s.sqlite']));
            self::assertStringContainsString('! state: ' . $harness->dir . '/ro/s.sqlite (not created yet, the directory is not writable)', $harness->display());
            self::assertStringContainsString('debounce: store "state" is not usable', $harness->display());
            chmod($harness->dir . '/ro', 0o700);
        }
    }

    #[TestDox('config: the effective values with the keys masked, the cli block, the verify and history sections; --json the same as a document')]
    public function testConfig(): void
    {
        $harness = new Harness(['INDEXNOW_HISTORY_PDO_DSN' => 'sqlite:/tmp/x.sqlite', 'INDEXNOW_VERIFY_ENABLED' => 'true']);

        self::assertSame(ExitCode::SUCCESS, $harness->run(['command' => 'config']));
        $display = $harness->display();
        self::assertStringContainsString('abcd********', $display);
        self::assertStringNotContainsString(Harness::KEY, $display);
        self::assertStringContainsString('read from the INDEXNOW_* variables (.env in the working directory) or the --config file', $display);
        self::assertStringContainsString('cli.state', $display);
        self::assertStringContainsString('history.pdo.dsn', $display);
        self::assertStringContainsString('verify.enabled', $display);

        self::assertSame(ExitCode::SUCCESS, $harness->run(['command' => 'config', '--json' => true]));
        $document = json_decode($harness->display(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($document);
        self::assertSame('abcd********', $document['config']['key']);
        self::assertTrue($document['verify']['enabled']);
        self::assertSame('pdo', $document['history']['store']);
        self::assertSame($harness->statePath(), $document['adapter']['cli']['state']);
        self::assertSame(['https://api.indexnow.org/indexnow'], $document['endpoints']);
    }
}
