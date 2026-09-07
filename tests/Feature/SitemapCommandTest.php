<?php

declare(strict_types=1);

namespace IndexNowKit\Cli\Tests\Feature;

use IndexNowKit\Cli\Tests\Support\Harness;
use IndexNowKit\Console\ExitCode;
use IndexNowKit\Http\Response;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

final class SitemapCommandTest extends TestCase
{
    private const URLSET = '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

    #[TestDox('sitemap --new-only twice: the first run submits everything, the second nothing ("0 new or changed"); a changed lastmod is submitted again; the state file is what remembers')]
    public function testNewOnly(): void
    {
        $harness = new Harness();
        $file = $harness->file('sitemap.xml', self::URLSET . '<url><loc>https://www.example.com/a</loc><lastmod>2026-01-01</lastmod></url><url><loc>https://www.example.com/b</loc></url></urlset>');

        self::assertSame(ExitCode::SUCCESS, $harness->run(['command' => 'sitemap', 'sitemap' => $file, '--new-only' => true]));
        self::assertSame(['https://www.example.com/a', 'https://www.example.com/b'], $harness->sentUrls());
        self::assertStringContainsString('2 URL(s) found in ' . $file . ', 2 new or changed', $harness->display());

        $second = new Harness();
        $second->dir = $harness->dir;
        self::assertSame(ExitCode::SUCCESS, $second->run(['command' => 'sitemap', 'sitemap' => $file, '--new-only' => true]));
        self::assertSame([], $second->transport->posts);
        self::assertStringContainsString('2 URL(s) found in ' . $file . ', 0 new or changed', $second->display());

        file_put_contents($file, self::URLSET . '<url><loc>https://www.example.com/a</loc><lastmod>2026-02-01</lastmod></url><url><loc>https://www.example.com/b</loc></url></urlset>');
        self::assertSame(ExitCode::SUCCESS, $second->run(['command' => 'sitemap', 'sitemap' => $file, '--new-only' => true, '--json' => true]));
        self::assertSame(['https://www.example.com/a'], $second->sentUrls());

        // --dry-run lists what is new and remembers nothing; --changed-since and --new-only add up
        file_put_contents($file, self::URLSET . '<url><loc>https://www.example.com/a</loc><lastmod>2026-02-01</lastmod></url><url><loc>https://www.example.com/c</loc><lastmod>2026-03-01</lastmod></url></urlset>');
        $second->transport->posts = [];
        self::assertSame(ExitCode::SUCCESS, $second->run(['command' => 'sitemap', 'sitemap' => $file, '--new-only' => true, '--dry-run' => true]));
        self::assertStringContainsString(' * https://www.example.com/c', $second->display());
        self::assertStringNotContainsString(' * https://www.example.com/a', $second->display());
        self::assertSame(ExitCode::SUCCESS, $second->run(['command' => 'sitemap', 'sitemap' => $file, '--new-only' => true, '--dry-run' => true]));
        self::assertStringContainsString('1 new or changed', $second->display(), 'a dry run remembered nothing');
        self::assertSame(ExitCode::SUCCESS, $second->run(['command' => 'sitemap', 'sitemap' => $file, '--new-only' => true, '--changed-since' => '2026-02-15']));
        self::assertSame(['https://www.example.com/c'], $second->sentUrls());
        self::assertStringContainsString('1 URL(s) found in ' . $file . ' changed since 2026-02-15T00:00:00+00:00, 1 new or changed', $second->display());

        $second->dir = Harness::tempDir();
    }

    #[TestDox('the default sitemap is INDEXNOW_SITEMAP_URL, else <base_url>/sitemap.xml over the transport; without either the error names the variable; --state memory remembers nothing between runs')]
    public function testDefaultsAndMemoryState(): void
    {
        $harness = new Harness();
        $harness->transport->onGet('https://www.example.com/sitemap.xml', new Response(200, self::URLSET . '<url><loc>https://www.example.com/d</loc></url></urlset>'));
        $harness->transport->onGet('https://www.example.com/news.xml', new Response(200, self::URLSET . '<url><loc>https://www.example.com/n</loc></url></urlset>'));

        self::assertSame(ExitCode::SUCCESS, $harness->run(['command' => 'sitemap', '--new-only' => true, '--state' => 'memory']));
        self::assertSame(['https://www.example.com/d'], $harness->sentUrls());
        self::assertSame(ExitCode::SUCCESS, $harness->run(['command' => 'sitemap', '--new-only' => true, '--state' => 'memory']));
        self::assertSame(['https://www.example.com/d', 'https://www.example.com/d'], $harness->sentUrls(), 'memory: every run is the first');

        $harness->env['INDEXNOW_SITEMAP_URL'] = 'https://www.example.com/news.xml';
        self::assertSame(ExitCode::SUCCESS, $harness->run(['command' => 'sitemap', '--state' => 'memory']));
        self::assertSame('https://www.example.com/n', $harness->sentUrls()[2]);

        $none = new Harness(['INDEXNOW_KEY' => Harness::KEY, 'INDEXNOW_HOSTS' => 'www.example.com=' . Harness::KEY], false);
        self::assertSame(ExitCode::INVALID, $none->run(['command' => 'sitemap']));
        self::assertStringContainsString('configure INDEXNOW_SITEMAP_URL or base_url', $none->display());
    }
}
