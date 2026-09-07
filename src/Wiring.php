<?php

declare(strict_types=1);

namespace IndexNowKit\Cli;

use IndexNowKit\Adapter\Services;
use IndexNowKit\Adapter\ServicesBuilder;
use IndexNowKit\Adapter\SubmitterFactoryInterface;
use IndexNowKit\Check\CheckInterface;
use IndexNowKit\Check\DebounceStoreCheck;
use IndexNowKit\Check\SampleGateCheck;
use IndexNowKit\Check\SampleOptions;
use IndexNowKit\Cli\Check\StateCheck;
use IndexNowKit\Cli\Check\UnknownConfigCheck;
use IndexNowKit\Cli\Config\EnvConfigSource;
use IndexNowKit\Cli\State\State;
use IndexNowKit\Clock\SystemClock;
use IndexNowKit\Config;
use IndexNowKit\Console\Vocabulary;
use IndexNowKit\Debounce\DebounceStoreFactory;
use IndexNowKit\Debounce\DebounceStoreInterface;
use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\History\Adapter\HistoryServices;
use IndexNowKit\History\HistoryConfig;
use IndexNowKit\History\Pdo\PdoSubmissionStore;
use IndexNowKit\Http\LazyTransport;
use IndexNowKit\Http\Psr18Transport;
use IndexNowKit\Http\TransportInterface;
use IndexNowKit\Sitemap\Adapter\SitemapServices;
use IndexNowKit\Sitemap\SitemapConfig;
use IndexNowKit\Submission\SubmissionStoreInterface;
use IndexNowKit\Submitter;
use IndexNowKit\SubmitterInterface;
use IndexNowKit\Verify\Adapter\VerifyServices;
use IndexNowKit\Verify\RobotsCache;
use IndexNowKit\Verify\VerifyConfig;
use IndexNowKit\Verify\VerifyingSubmitterFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use PDO;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;

/**
 * The graph of `indexnow`: the core's `Adapter\ServicesBuilder` over the configuration of {@see EnvConfigSource} and the
 * stores of the {@see State} file, the way the Yii3 package's `Wiring` describes its graph over the container. What is
 * the CLI's here: the transport is `symfony/http-client` with `nyholm/psr7`, required and passed explicitly (nothing is
 * discovered, a PHAR cannot rely on what the classmap happens to hold); `debounce.store` defaults to `state`, the cache of
 * the state file, and is the only id besides `memory` and `none`; `history.store` defaults to `pdo` over the same file;
 * the pre-flight of `indexnowkit/verify` gets a transport of its own (`verify.timeout`, no redirects); and the `check`
 * lines are the state, the unknown variables and the three packages. Nothing is built before a command asks for it.
 */
final class Wiring
{
    private ?Services $services = null;
    private ?Config $config = null;
    private ?SitemapConfig $sitemap = null;
    private ?VerifyConfig $verify = null;
    private ?HistoryConfig $history = null;
    private ?TransportInterface $verifyTransport = null;
    private ?RobotsCache $robots = null;
    private ?SubmitterFactoryInterface $submitters = null;
    private readonly ClockInterface $clock;
    private readonly SampleOptions $samples;

    /**
     * @param TransportInterface|null $transport the transport of the submissions and the key file check (tests: `Testing\FakeTransport`);
     *                                           null = symfony/http-client over `http.timeout`. The pre-flight uses it too when given
     * @param ClockInterface|null     $clock     the graph's clock (`Testing\FrozenClock`); null = the system clock
     */
    public function __construct(
        private readonly EnvConfigSource $source,
        private readonly State $state,
        private readonly LoggerInterface $logger,
        private readonly ?TransportInterface $transport = null,
        ?ClockInterface $clock = null,
    ) {
        $this->clock = $clock ?? new SystemClock();
        $this->samples = new SampleOptions();
    }

    public static function words(): Vocabulary
    {
        return new Vocabulary(
            subject: 'url',
            subjects: 'urls',
            cli: 'indexnow',
            submitSubjects: '',
            configLocation: 'the INDEXNOW_* variables (.env in the working directory) or the --config file',
            keyFileServedBy: 'by the <key>.txt file that "indexnow key:file <docroot>" writes',
            check: 'check',
            submit: 'submit',
            explain: '',
        );
    }

    public function source(): EnvConfigSource
    {
        return $this->source;
    }

    public function state(): State
    {
        return $this->state;
    }

    public function logger(): LoggerInterface
    {
        return $this->logger;
    }

    public function clock(): ClockInterface
    {
        return $this->clock;
    }

    /** The `--sample` / `--sample-class` holder of `check`; no class sampler (there is no ORM here). */
    public function samples(): SampleOptions
    {
        return $this->samples;
    }

    /**
     * The strict configuration, built once (a broken one throws with the place to fix it).
     *
     * @throws ConfigurationException
     */
    public function config(): Config
    {
        return $this->config ??= $this->source->build();
    }

    /** @throws ConfigurationException */
    public function sitemapConfig(): SitemapConfig
    {
        return $this->sitemap ??= $this->source->sitemap();
    }

    /** @throws ConfigurationException */
    public function verifyConfig(): VerifyConfig
    {
        return $this->verify ??= $this->source->verify();
    }

    /** @throws ConfigurationException */
    public function historyConfig(): HistoryConfig
    {
        return $this->history ??= $this->source->history();
    }

    /**
     * The graph, described once and built lazily: every node the core's factory over the others, the five below the
     * CLI's own.
     *
     * @throws ConfigurationException
     */
    public function services(): Services
    {
        if ($this->services !== null) {
            return $this->services;
        }
        $config = $this->config();
        $state = $this->state;
        $builder = (new ServicesBuilder($config, $this->logger))
            ->clock($this->clock)
            ->transport(fn(): TransportInterface => $this->transport())
            ->debounceStore(fn(Services $s): DebounceStoreInterface => DebounceStoreFactory::fromConfig($s->config, $this->cacheLocator(...), State::STORE_ID, $s->clock()))
            ->checks(fn(Services $s): iterable => $this->checks($s));
        if (DebounceStoreFactory::isShared($config->debounceStore ?? State::STORE_ID)) {
            // the 403 counters and the robots cache of verify share the cache of the state file, as a framework's shared cache
            $builder->failureCache(static fn(): CacheInterface => $state->cache());
        }
        if ($this->historyConfig()->store !== null) {
            $builder->submissionStore(fn(Services $s): SubmissionStoreInterface => $this->submissionStore($s->config));
        }
        if ($this->verifyConfig()->enabled) {
            $builder->submitter(fn(Services $s): SubmitterInterface => VerifyServices::submitterFor(
                new Submitter($s->client(), $s->config, $s->debounceStore(), $s->logger, $s->normalizer(), $s->events(), $s->submissionStore(), $s->clock()),
                $this->verifyConfig(),
                $s,
                $this->verifyTransport(),
                $this->robots(),
                false,
            ));
        }

        return $this->services = $builder->build();
    }

    /**
     * The transport of the submissions and the key file check: the given one, else `symfony/http-client` over
     * `http.timeout` and `nyholm/psr7`, built on first use. `http.client` names a container id, and there is none.
     *
     * @throws ConfigurationException
     */
    public function transport(): TransportInterface
    {
        $config = $this->config();
        if ($config->httpClient !== null) {
            throw new ConfigurationException(\sprintf('"http.client" is "%s": this CLI has no container to resolve an http.client id, it always sends through symfony/http-client; unset INDEXNOW_HTTP_CLIENT.', $config->httpClient));
        }
        if ($this->transport !== null) {
            return $this->transport;
        }

        return new LazyTransport(fn(): TransportInterface => self::psr18($this->client($config->httpTimeout), $config->httpTimeout));
    }

    /**
     * The pre-flight transport of `indexnowkit/verify` (spec 19b §7.2): the same explicit client with `verify.timeout`
     * and no redirects, the package's `User-Agent` and body limit — `VerifyServices::transport()` would discover one.
     *
     * @throws ConfigurationException
     */
    public function verifyTransport(): TransportInterface
    {
        if ($this->verifyTransport !== null) {
            return $this->verifyTransport;
        }
        $verify = $this->verifyConfig();
        $timeout = $verify->transportConfig($this->config())->httpTimeout;
        $headers = ['User-Agent' => $verify->userAgent()];

        return $this->verifyTransport = $this->transport ?? new LazyTransport(fn(): TransportInterface => self::psr18($this->client($timeout), $timeout, $headers, VerifyConfig::BODY_LIMIT));
    }

    /** @throws ConfigurationException */
    public function robots(): RobotsCache
    {
        return $this->robots ??= VerifyServices::robotsFor($this->verifyConfig(), $this->services(), $this->verifyTransport());
    }

    /**
     * The command submitters (`--force`, `--dry-run`): the core's factory, decorated with the pre-flight when `verify.enabled`.
     *
     * @throws ConfigurationException
     */
    public function submitterFactory(): SubmitterFactoryInterface
    {
        if ($this->submitters !== null) {
            return $this->submitters;
        }
        $plain = $this->services()->submitterFactory();

        return $this->submitters = $this->verifyConfig()->enabled ? VerifyServices::submitterFactoryFor($plain, $this->verifyConfig(), $this->services(), $this->verifyTransport(), $this->robots()) : $plain;
    }

    /** The plain factory `sitemap --no-verify` submits through when the pre-flight decorates the other one; null otherwise. */
    public function unverifiedSubmitterFactory(): ?SubmitterFactoryInterface
    {
        $factory = $this->submitterFactory();

        return $factory instanceof VerifyingSubmitterFactory ? $factory->inner() : null;
    }

    /** `debounce.store` as `status` describes it: `state (SqliteCache)`, `memory`, `none`. */
    public function debounceStoreDescription(): string
    {
        return HistoryServices::describeStore($this->config()->debounceStore, State::STORE_ID, fn(string $id): ?object => $id === State::STORE_ID ? $this->state->cache() : null);
    }

    /**
     * `debounce.store`: `state` is the cache of the state file; any other id has no container to be looked up in.
     *
     * @throws ConfigurationException
     */
    private function cacheLocator(string $id): CacheInterface
    {
        if ($id !== State::STORE_ID) {
            throw new ConfigurationException(\sprintf('"debounce.store" "%s": this CLI has no container to resolve a cache id; use "%s" (the state file), "memory" or "none".', $id, State::STORE_ID));
        }

        return $this->state->cache();
    }

    /**
     * The store of `history.store`: `pdo` over the state file (`history.pdo.dsn` unset), over a DSN, or `psr16` over the
     * cache of the state file; a connection or cache id names nothing here.
     *
     * @throws ConfigurationException
     */
    private function submissionStore(Config $config): SubmissionStoreInterface
    {
        $history = $this->historyConfig();
        if ($history->store === HistoryConfig::STORE_PDO && $history->pdoDsn === null && $history->pdoService === null) {
            return $this->state->submissionStore($history);
        }
        $store = HistoryServices::storeFor(
            $history,
            $config,
            static fn(?string $id): PDO => throw new ConfigurationException(\sprintf('history.pdo.service "%s" names a connection of a framework; this CLI has none: unset it, or give history.pdo.dsn', (string) $id)),
            fn(?string $id): CacheInterface => $this->cacheLocator($id ?? State::STORE_ID),
            State::STORE_ID,
        );
        if ($store instanceof PdoSubmissionStore && str_starts_with((string) $history->pdoDsn, 'sqlite:')) {
            $store->createTable(); // a sqlite file of the operator's choosing: created like the state file, no migration to run
        }

        return $store;
    }

    /**
     * The lines of `check` beyond the core's own: the configuration sources, the state file, the debounce store, the
     * three packages (no dispatch line of verify: a process has no queue to recommend).
     *
     * @return list<CheckInterface>
     */
    private function checks(Services $services): array
    {
        $state = $this->state;
        $verify = $this->verifyConfig();

        return [
            new UnknownConfigCheck($this->source),
            new StateCheck($state),
            new DebounceStoreCheck($services->config, static function (string $id) use ($state): string {
                $state->cache()->set(DebounceStoreCheck::PROBE_KEY, 1, 5);

                return \sprintf('store "%s" (%s)', $id, $state->path());
            }, State::STORE_ID),
            SitemapServices::spoolCheck($this->sitemapConfig()),
            VerifyServices::installedCheck($verify),
            VerifyServices::transportCheck($verify, $services->config),
            SampleGateCheck::withPackage($this->samples, VerifyServices::sampleCheck($this->verifyTransport(), $verify, $services->normalizer(), $services->keys(), null, $this->robots())),
            ...HistoryServices::checksFor($this->historyConfig(), $services),
        ];
    }

    /** `symfony/http-client` with a timeout and no redirects (the key file check and the pre-flight must see a 3xx). */
    private function client(float $timeout): ClientInterface
    {
        return new Psr18Client(HttpClient::create(['max_redirects' => 0, 'timeout' => $timeout, 'max_duration' => $timeout * 2]), new Psr17Factory(), new Psr17Factory());
    }

    /**
     * @param array<string, string> $headers
     */
    private static function psr18(ClientInterface $client, float $timeout, array $headers = [], ?int $getBodyLimit = null): Psr18Transport
    {
        return Psr18Transport::discover($client, $timeout, $headers, $getBodyLimit, new Psr17Factory(), new Psr17Factory());
    }
}
