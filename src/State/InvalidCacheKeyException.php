<?php

declare(strict_types=1);

namespace IndexNowKit\Cli\State;

use InvalidArgumentException;
use Psr\SimpleCache\InvalidArgumentException as Psr16InvalidArgument;

/**
 * A key {@see SqliteCache} refuses: empty, or holding one of the characters PSR-16 reserves (`{}()/\@:`).
 *
 * @internal the state file and its stores are not API before 1.0 (docs/bc.md)
 */
final class InvalidCacheKeyException extends InvalidArgumentException implements Psr16InvalidArgument {}
