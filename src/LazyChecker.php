<?php

declare(strict_types=1);

namespace IndexNowKit\Cli;

use Closure;
use IndexNowKit\Check\CheckerInterface;
use IndexNowKit\Check\CheckReport;

/**
 * The checker of `check`, built when the command runs and not when it is registered: `Console\CheckRunner` validates
 * the configuration first and prints why it does not build; the graph behind the checker needs that configuration,
 * so it is built only after the runner asked for the report.
 */
final class LazyChecker implements CheckerInterface
{
    private ?CheckerInterface $checker = null;

    /**
     * @param Closure(): CheckerInterface $factory
     */
    public function __construct(private readonly Closure $factory) {}

    public function run(bool $liveProbe = false, ?string $onlyHost = null, ?string $probeUrl = null): CheckReport
    {
        $this->checker ??= ($this->factory)();

        return $this->checker->run($liveProbe, $onlyHost, $probeUrl);
    }
}
