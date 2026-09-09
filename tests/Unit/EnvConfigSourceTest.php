<?php

declare(strict_types=1);

namespace IndexNowKit\Cli\Tests\Unit;

use Composer\InstalledVersions;
use IndexNowKit\Cli\Config\EnvBlocks;
use IndexNowKit\Cli\Config\EnvConfigSource;
use IndexNowKit\Cli\Tests\Support\Harness;
use IndexNowKit\Config;
use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\History\HistoryConfig;
use IndexNowKit\Sitemap\SitemapConfig;
use IndexNowKit\Verify\VerifyConfig;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

final class EnvConfigSourceTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = Harness::tempDir();
    }

    protected function tearDown(): void
    {
        Harness::remove($this->dir);
    }

    #[TestDox('every core variable of CORE_VARIABLES is read by Config::arrayFromEnv(), and every variable arrayFromEnv() documents is in the list')]
    public function testCoreVariablesAreTheOnesTheCoreReads(): void
    {
        foreach (EnvConfigSource::CORE_VARIABLES as $name) {
            $value = $name === 'HOSTS' ? 'a.test=abcdefgh' : '1';
            self::assertNotSame([], Config::arrayFromEnv([EnvBlocks::PREFIX . $name => $value]), $name . ' is not read by Config::arrayFromEnv(): drop it from CORE_VARIABLES');
        }
        $doc = (string) file_get_contents((string) InstalledVersions::getInstallPath('indexnowkit/core') . '/src/Config.php');
        preg_match_all('/INDEXNOW_([A-Z_]+)/', $doc, $found);
        foreach (array_unique($found[1]) as $name) {
            self::assertContains($name, EnvConfigSource::CORE_VARIABLES, 'the core documents INDEXNOW_' . $name . ', CORE_VARIABLES does not know it');
        }
    }

    #[TestDox('raw(): the JSON file under the environment, the environment winning key by key; INDEXNOW_HOSTS replaces the hosts map whole; the package blocks follow the one rule')]
    public function testRawMergesFileAndEnvironment(): void
    {
        $file = $this->dir . '/indexnow.json';
        file_put_contents($file, json_encode([
            'key' => 'filekey12345', 'base_url' => 'https://file.test', 'hosts' => ['file.test' => 'filekey12345', 'other.test' => 'otherkey1234'],
            'debounce' => ['per_url' => 5, 'key_prefix' => 'f_'], 'sitemap' => ['max_depth' => 1, 'url' => 'https://file.test/s.xml'], 'verify' => ['enabled' => true],
        ]));
        $source = new EnvConfigSource([
            'INDEXNOW_KEY' => 'envkey123456', 'INDEXNOW_HOSTS' => 'env.test=envkey123456', 'INDEXNOW_DEBOUNCE_PER_URL' => '9',
            'INDEXNOW_SITEMAP_MAX_DEPTH' => '4', 'INDEXNOW_HISTORY_STORE' => 'pdo', 'INDEXNOW_HISTORY_PDO_TABLE' => 'my_log',
        ], $file, ['config_file' => $file, 'env_file' => null, 'state' => 'memory']);
        $raw = $source->raw();

        self::assertSame('envkey123456', $raw['key'], 'the environment wins');
        self::assertSame('https://file.test', $raw['base_url'], 'the file fills what the environment does not set');
        self::assertSame(['env.test' => 'envkey123456'], $raw['hosts'], 'INDEXNOW_HOSTS is the whole map');
        self::assertSame(['per_url' => '9', 'key_prefix' => 'f_'], $raw['debounce'], 'blocks merge key by key');
        self::assertSame(['max_depth' => '4', 'url' => 'https://file.test/s.xml'], $raw['sitemap']);
        self::assertSame(['enabled' => true], $raw['verify']);
        self::assertSame(['store' => 'pdo', 'pdo' => ['table' => 'my_log']], $raw['history']);
        self::assertSame(['config_file' => $file, 'env_file' => null, 'state' => 'memory'], $raw['cli'], 'the cli block config prints');
        self::assertSame($file, $source->configFile());

        $config = $source->build();
        self::assertSame('envkey123456', $config->key);
        self::assertSame(9, $config->debouncePerUrl);
        self::assertSame(4, $source->sitemap()->maxDepth);
        self::assertTrue($source->verify()->enabled);
        self::assertSame('my_log', $source->history()->pdoTable);
        self::assertSame(['verify', 'history'], array_keys($source->packages()));
        self::assertSame([], $source->unknownOptions());
    }

    #[TestDox('INDEXNOW_CONFIG names the file when --config does not; a missing or broken file is a ConfigurationException naming it; a JSON list is refused')]
    public function testConfigFile(): void
    {
        $missing = new EnvConfigSource(['INDEXNOW_CONFIG' => $this->dir . '/none.json', 'INDEXNOW_KEY' => Harness::KEY]);
        self::assertSame($this->dir . '/none.json', $missing->configFile());
        try {
            $missing->raw();
            self::fail('no exception');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString($this->dir . '/none.json does not exist', $e->getMessage());
        }

        $broken = $this->dir . '/broken.json';
        file_put_contents($broken, '{not json');
        try {
            (new EnvConfigSource([], $broken))->raw();
            self::fail('no exception');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('is not valid JSON', $e->getMessage());
        }

        $list = $this->dir . '/list.json';
        file_put_contents($list, '[1, 2]');
        try {
            (new EnvConfigSource([], $list))->raw();
            self::fail('no exception');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('must hold a JSON object', $e->getMessage());
        }

        self::assertNull((new EnvConfigSource(['INDEXNOW_CONFIG' => '']))->configFile(), 'an empty variable is no file');
        self::assertSame([], (new EnvConfigSource([]))->raw(), 'no file, no variable: nothing');
    }

    #[TestDox('history.store defaults to pdo; an empty INDEXNOW_HISTORY_STORE, none, null or off switches it off; psr16 and pdo pass through')]
    public function testHistoryDefault(): void
    {
        self::assertSame(HistoryConfig::STORE_PDO, (new EnvConfigSource([]))->history()->store);
        self::assertNull((new EnvConfigSource(['INDEXNOW_HISTORY_STORE' => '']))->history()->store);
        self::assertNull((new EnvConfigSource(['INDEXNOW_HISTORY_STORE' => 'none']))->history()->store);
        self::assertNull((new EnvConfigSource(['INDEXNOW_HISTORY_STORE' => 'OFF']))->history()->store);
        self::assertSame('psr16', (new EnvConfigSource(['INDEXNOW_HISTORY_STORE' => 'psr16']))->history()->store);
        self::assertSame(HistoryConfig::STORE_PDO, (new EnvConfigSource(['INDEXNOW_HISTORY_STORE' => 'pdo']))->history()->store);
        self::assertSame(HistoryConfig::STORE_PDO, (new EnvConfigSource([]))->packages()['history']['store']);
        self::assertFalse((new EnvConfigSource([]))->verify()->enabled, 'verify stays off by default');
        self::assertInstanceOf(SitemapConfig::class, (new EnvConfigSource([]))->sitemap());
    }

    #[TestDox('unknownVariables(): every INDEXNOW_* nothing reads, sorted; the core, the CLI and the package variables are known')]
    public function testUnknownVariables(): void
    {
        $source = new EnvConfigSource([
            'INDEXNOW_KEY' => Harness::KEY, 'INDEXNOW_STATE' => 'memory', 'INDEXNOW_CONFIG' => '', 'INDEXNOW_VERIFY_MAX_BATCH' => '5',
            'INDEXNOW_SITEMAP_URL' => 'https://x.test/s.xml', 'INDEXNOW_HISTORY_PDO_DSN' => 'sqlite::memory:',
            'INDEXNOW_DEBOUNCE_PER_URLS' => '5', 'INDEXNOW_QUEUE_CONNECTION' => 'redis', 'INDEXNOWX' => '1', 'OTHER' => '1',
        ]);

        self::assertSame(['INDEXNOW_DEBOUNCE_PER_URLS', 'INDEXNOW_QUEUE_CONNECTION'], $source->unknownVariables());
        self::assertSame([], (new EnvConfigSource(['INDEXNOW_KEY' => Harness::KEY]))->unknownVariables());
    }

    #[TestDox('build(): the strict build of the core ConfigFactory — dispatch is sync or none, the package options are owned, a typo in the file is an unknown option')]
    public function testBuild(): void
    {
        $file = $this->dir . '/c.json';
        file_put_contents($file, json_encode(['key' => Harness::KEY, 'dispatch' => 'queue']));
        try {
            (new EnvConfigSource([], $file))->build();
            self::fail('no exception');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('"dispatch" must be one of sync, none', $e->getMessage());
        }
        file_put_contents($file, json_encode(['key' => Harness::KEY, 'debounce' => ['per_urls' => 5], 'sitemap' => ['max_depht' => 1], 'queue' => ['connection' => 'x']]));
        $source = new EnvConfigSource([], $file);
        self::assertSame(['debounce.per_urls', 'sitemap.max_depht', 'queue.connection'], $source->unknownOptions());
        self::assertSame(Harness::KEY, $source->build()->key);
        self::assertContains('sitemap.url', EnvConfigSource::ownedOptions());
        self::assertContains('verify.enabled', EnvConfigSource::ownedOptions());
        self::assertContains('history.pdo.dsn', EnvConfigSource::ownedOptions());
        self::assertContains('cli.state', EnvConfigSource::ownedOptions());
        self::assertSame(VerifyConfig::OPTIONS, array_values(array_intersect(EnvConfigSource::ownedOptions(), VerifyConfig::OPTIONS)));
    }
}
