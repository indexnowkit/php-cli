<?php

declare(strict_types=1);

namespace IndexNowKit\Cli\Check;

use IndexNowKit\Check\CheckInterface;
use IndexNowKit\Check\CheckReport;
use IndexNowKit\Cli\State\State;

/**
 * The `cli.state` line of `check`: where the state file is and whether it can be written — read when the check runs,
 * after the debounce probe may have created it. A memory state says that nothing persists; a path that cannot be
 * written is a warning (the debounce line says what that does to the window).
 */
final class StateCheck implements CheckInterface
{
    public const CODE = 'cli.state';

    public function __construct(private readonly State $state) {}

    public function check(CheckReport $report): void
    {
        $line = \sprintf('state: %s', $this->state->describe());
        $this->state->isReadOnly() ? $report->warning($line, self::CODE) : $report->ok($line, self::CODE);
    }
}
