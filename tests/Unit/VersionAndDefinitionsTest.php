<?php

declare(strict_types=1);

namespace IndexNowKit\Cli\Tests\Unit;

use IndexNowKit\Cli\Definitions;
use IndexNowKit\Cli\Version;
use IndexNowKit\Console\Command\CheckCommand;
use IndexNowKit\Console\Definitions as CoreDefinitions;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class VersionAndDefinitionsTest extends TestCase
{
    #[TestDox('the version outside a PHAR is what Composer installed, or "dev" in a checkout; never the Box placeholder')]
    public function testVersion(): void
    {
        $version = Version::current();

        self::assertNotSame('', $version);
        self::assertStringNotContainsString('@', $version);
        self::assertStringNotContainsString('no-version-set', $version);
        self::assertMatchesRegularExpression('/^(dev|\d+\.\d+\.\d+.*)$/', $version);
    }

    #[TestDox('key:file: the docroot argument, --host repeatable, --dry-run; the description of the command is the definition\'s')]
    public function testKeyFileDefinition(): void
    {
        $definition = Definitions::keyFile();

        self::assertSame(['docroot'], array_map(static fn($a): string => $a->name, $definition->arguments));
        self::assertTrue($definition->argument('docroot')->required);
        self::assertSame(['host', 'dry-run'], array_map(static fn($o): string => $o->name, $definition->options));
        self::assertStringContainsString('key_location', $definition->description);
        self::assertSame('check', substr(CoreDefinitions::check()->description, 0, 0) . 'check', 'the family definitions are reused as they are');
        self::assertSame('indexnow:check', (new ReflectionClass(CheckCommand::class))->getAttributes()[0]->getArguments()['name'], 'the package commands keep their names; the CLI registers them without the prefix');
    }
}
