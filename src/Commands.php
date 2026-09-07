<?php

declare(strict_types=1);

namespace IndexNowKit\Cli;

use Closure;
use IndexNowKit\Cli\Command\ConfigurationErrorCommand;
use IndexNowKit\Cli\Command\KeyFileCommand;
use IndexNowKit\Cli\Env\Dotenv;
use IndexNowKit\Console\CheckRunner;
use IndexNowKit\Console\Command\CheckCommand;
use IndexNowKit\Console\Command\ConfigCommand;
use IndexNowKit\Console\Command\KeyGenerateCommand;
use IndexNowKit\Console\Command\SubmitCommand;
use IndexNowKit\Console\CommandDefinition;
use IndexNowKit\Console\ConfigRunner;
use IndexNowKit\Console\Definitions as CoreDefinitions;
use IndexNowKit\Console\KeyGenerateRunner;
use IndexNowKit\Console\ResultRenderer;
use IndexNowKit\Console\SubmitRunner;
use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\History\Adapter\HistoryServices;
use IndexNowKit\History\Console\Definitions as HistoryDefinitions;
use IndexNowKit\History\Console\HistoryCommand;
use IndexNowKit\History\Console\StatusCommand;
use IndexNowKit\Sitemap\Adapter\SitemapServices;
use IndexNowKit\Sitemap\Console\Definitions as SitemapDefinitions;
use IndexNowKit\Sitemap\Console\SitemapCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LazyCommand;
use Symfony\Component\Console\CommandLoader\CommandLoaderInterface;
use Symfony\Component\Console\CommandLoader\FactoryCommandLoader;

/**
 * The eight commands of `indexnow`, the classes of `indexnowkit/console`, `sitemap` and `history` registered under
 * their names without the `indexnow:` prefix (the binary is the prefix) plus the CLI's own `key:file` — each a
 * `LazyCommand`, so `list` and `--help` build nothing, and a command builds the graph only when it runs. What varies by
 * constructor comes from {@see Wiring}: the runners, the words, the configuration source, the state.
 */
final class Commands
{
    public const CHECK = 'check';
    public const CONFIG = 'config';
    public const SUBMIT = 'submit';
    public const KEY_GENERATE = 'key:generate';
    public const KEY_FILE = 'key:file';
    public const SITEMAP = 'sitemap';
    public const HISTORY = 'history';
    public const STATUS = 'status';

    /** The name every adapter prints for `sitemap.url` in the help and the error texts: the variable of this CLI. */
    public const SITEMAP_URL_OPTION = 'INDEXNOW_SITEMAP_URL';

    /** @var list<string> */
    public const NAMES = [self::CHECK, self::CONFIG, self::SUBMIT, self::KEY_GENERATE, self::KEY_FILE, self::SITEMAP, self::HISTORY, self::STATUS];

    private ?Wiring $wiring = null;

    /**
     * @param Closure(): Wiring $factory builds the graph on the first command that needs it (never for `list` or `--version`)
     */
    public function __construct(private readonly Closure $factory) {}

    public function loader(): CommandLoaderInterface
    {
        $lazy = fn(string $name, CommandDefinition $definition, Closure $factory): Closure => fn(): Command => new LazyCommand($name, [], $definition->description, false, fn(): Command => $this->guarded($name, $definition, $factory));

        return new FactoryCommandLoader([
            self::CHECK => $lazy(self::CHECK, CoreDefinitions::check(), fn(): Command => $this->check()),
            self::CONFIG => $lazy(self::CONFIG, CoreDefinitions::config(), fn(): Command => $this->config()),
            self::SUBMIT => $lazy(self::SUBMIT, CoreDefinitions::submit(), fn(): Command => $this->submit()),
            self::KEY_GENERATE => $lazy(self::KEY_GENERATE, CoreDefinitions::keyGenerate(), fn(): Command => $this->keyGenerate()),
            self::KEY_FILE => $lazy(self::KEY_FILE, Definitions::keyFile(), fn(): Command => $this->keyFile()),
            self::SITEMAP => $lazy(self::SITEMAP, SitemapDefinitions::sitemap(self::SITEMAP_URL_OPTION), fn(): Command => $this->sitemap()),
            self::HISTORY => $lazy(self::HISTORY, HistoryDefinitions::history(), fn(): Command => $this->history()),
            self::STATUS => $lazy(self::STATUS, HistoryDefinitions::status(), fn(): Command => $this->status()),
        ]);
    }

    /**
     * The command, or — when its graph cannot be built because the configuration does not (`check` and `config` never
     * get here: they read the configuration themselves) — a stand-in with the same definition that prints the error
     * when run: `help <command>` works on a host without a key, and the error is where the operator looks.
     *
     * @param Closure(): Command $factory
     */
    private function guarded(string $name, CommandDefinition $definition, Closure $factory): Command
    {
        try {
            return $factory();
        } catch (ConfigurationException $e) {
            return new ConfigurationErrorCommand($name, $definition, $e, Wiring::words()->configLocation);
        }
    }

    /** The configuration is validated by the runner first; the checker (and the graph) is built after that. */
    public function check(): CheckCommand
    {
        $wiring = $this->wiring();

        return new CheckCommand(new CheckRunner(new LazyChecker(static fn() => $wiring->services()->checker()), Wiring::words()), $wiring->source(), $wiring->samples());
    }

    public function config(): ConfigCommand
    {
        return new ConfigCommand(new ConfigRunner(Wiring::words()), $this->wiring()->source());
    }

    public function submit(): SubmitCommand
    {
        $wiring = $this->wiring();

        return new SubmitCommand(new SubmitRunner($wiring->services()->kit(), $wiring->submitterFactory(), new ResultRenderer()));
    }

    /** `--write-env` without a value: `.env` in the working directory (the file the CLI reads). */
    public function keyGenerate(): KeyGenerateCommand
    {
        return new KeyGenerateCommand(new KeyGenerateRunner(Wiring::words()), Dotenv::DEFAULT_FILE);
    }

    public function keyFile(): KeyFileCommand
    {
        return new KeyFileCommand(new KeyFileRunner($this->wiring()->services()->keys(), Wiring::words()));
    }

    public function sitemap(): SitemapCommand
    {
        $wiring = $this->wiring();
        $services = $wiring->services();
        $sitemap = $wiring->sitemapConfig();
        $runner = SitemapServices::runner($services->kit(), SitemapServices::readerFor($sitemap, $services), $wiring->submitterFactory(), $sitemap, new ResultRenderer(), self::SITEMAP_URL_OPTION, $wiring->unverifiedSubmitterFactory(), $services->clock(), seen: $wiring->state()->seenStore());

        return SitemapServices::command($runner, self::SITEMAP_URL_OPTION);
    }

    public function history(): HistoryCommand
    {
        $wiring = $this->wiring();

        return new HistoryCommand(HistoryServices::historyRunnerFor($wiring->historyConfig(), $wiring->services()));
    }

    /** No queue facts: a process has no queue. */
    public function status(): StatusCommand
    {
        $wiring = $this->wiring();

        return new StatusCommand(HistoryServices::statusRunnerFor($wiring->services(), $wiring->debounceStoreDescription(), null));
    }

    private function wiring(): Wiring
    {
        return $this->wiring ??= ($this->factory)();
    }
}
