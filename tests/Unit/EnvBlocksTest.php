<?php

declare(strict_types=1);

namespace IndexNowKit\Cli\Tests\Unit;

use IndexNowKit\Cli\Config\EnvBlocks;
use IndexNowKit\History\HistoryConfig;
use IndexNowKit\Sitemap\SitemapConfig;
use IndexNowKit\Verify\VerifyConfig;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

final class EnvBlocksTest extends TestCase
{
    #[TestDox('the variable of a dotted option is the prefix plus the path upper-cased, dots as underscores')]
    public function testVariable(): void
    {
        self::assertSame('INDEXNOW_SITEMAP_MAX_DEPTH', EnvBlocks::variable('sitemap.max_depth'));
        self::assertSame('INDEXNOW_HISTORY_PDO_DSN', EnvBlocks::variable('history.pdo.dsn'));
        self::assertSame('MY_VERIFY_ENABLED', EnvBlocks::variable('verify.enabled', 'MY_'));
        self::assertSame(['INDEXNOW_HISTORY_STORE', 'INDEXNOW_HISTORY_LIMIT'], EnvBlocks::variables(['history.store', 'history.limit']));
    }

    #[TestDox('read(): the blocks of the three packages as their fromArray() take them, only what is set, values as strings, an empty value kept')]
    public function testRead(): void
    {
        $env = [
            'INDEXNOW_SITEMAP_MAX_DEPTH' => '2',
            'INDEXNOW_SITEMAP_ALLOW_FOREIGN_HOSTS' => 'true',
            'INDEXNOW_VERIFY_ENABLED' => '1',
            'INDEXNOW_VERIFY_TIMEOUT' => '2.5',
            'INDEXNOW_HISTORY_PDO_DSN' => 'sqlite:/tmp/h.sqlite',
            'INDEXNOW_HISTORY_STORE' => '',
            'INDEXNOW_KEY' => 'notablock',
            'INDEXNOW_HISTORY_LIMIT' => 42,
            'INDEXNOW_HISTORY_KEY_PREFIX' => ['not', 'scalar'],
            'INDEXNOW_SITEMAP_ENABLED' => true,
        ];
        $options = [...SitemapConfig::OPTIONS, ...VerifyConfig::OPTIONS, ...HistoryConfig::OPTIONS];

        self::assertSame([
            'sitemap' => ['enabled' => 'true', 'max_depth' => '2', 'allow_foreign_hosts' => 'true'],
            'verify' => ['enabled' => '1', 'timeout' => '2.5'],
            'history' => ['store' => '', 'limit' => '42', 'pdo' => ['dsn' => 'sqlite:/tmp/h.sqlite']],
        ], EnvBlocks::read($env, $options));
        self::assertSame([], EnvBlocks::read([], $options));
        self::assertSame(['sitemap' => ['url' => 'https://x.test/s.xml']], EnvBlocks::read(['MY_SITEMAP_URL' => 'https://x.test/s.xml'], SitemapConfig::OPTIONS, 'MY_'));

        // what the rule produces is what the packages parse
        $blocks = EnvBlocks::read($env, $options);
        self::assertSame(2, SitemapConfig::fromArray($blocks['sitemap'])->maxDepth);
        self::assertTrue(SitemapConfig::fromArray($blocks['sitemap'])->allowForeignHosts);
        self::assertTrue(VerifyConfig::fromArray($blocks['verify'])->enabled);
        self::assertSame(2.5, VerifyConfig::fromArray($blocks['verify'])->timeout);
        self::assertNull(HistoryConfig::fromArray($blocks['history'])->store, 'an empty INDEXNOW_HISTORY_STORE is "no store"');
        self::assertSame('sqlite:/tmp/h.sqlite', HistoryConfig::fromArray($blocks['history'])->pdoDsn);
    }
}
