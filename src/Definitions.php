<?php

declare(strict_types=1);

namespace IndexNowKit\Cli;

use IndexNowKit\Console\ArgumentDefinition;
use IndexNowKit\Console\CommandDefinition;
use IndexNowKit\Console\OptionDefinition;

/**
 * The arguments and options of the one command that exists here and nowhere else, declared the way
 * `Console\Definitions` declares the family's (should an adapter want `key:file`, the definition moves there with it).
 */
final class Definitions
{
    private function __construct() {}

    /** `key:file <docroot>`: {@see KeyFileRunner::run()}. */
    public static function keyFile(): CommandDefinition
    {
        return new CommandDefinition(
            'Write the key file of every configured host into a document root ({docroot}/{key}.txt, or the path of key_location): the first step on a host without a framework',
            [ArgumentDefinition::required('docroot', 'The directory the web server serves as / (the document root of the site)')],
            [
                OptionDefinition::list('host', 'Write the file of this host only (repeatable; multi-domain setups)'),
                OptionDefinition::flag('dry-run', 'Print what would be written, touch nothing'),
            ],
        );
    }
}
