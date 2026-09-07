<?php

declare(strict_types=1);

namespace IndexNowKit\Cli\Tests\Unit;

use IndexNowKit\Cli\Config\EnvConfigSource;
use IndexNowKit\Testing\Conformance\ReadmeAssertions;
use PHPUnit\Framework\TestCase;

/**
 * The "Notes for AI assistants" section of the README (EN and RU): present, with a complete snippet, naming only
 * commands and configuration keys that exist (spec 17 §3.1). The CLI's own command names carry no `indexnow:` prefix;
 * the notes name the family's prefixed ones as well, which is what the assertions check against.
 */
final class ReadmeAiNotesTest extends TestCase
{
    public function testTheNotesForAiAssistantsAreConsistentWithTheCode(): void
    {
        ReadmeAssertions::assertAiNotes(\dirname(__DIR__, 2), ['indexnow:check', 'indexnow:config', 'indexnow:submit', 'indexnow:sitemap', 'indexnow:key:generate', 'indexnow:history', 'indexnow:status'], EnvConfigSource::ownedOptions());
    }
}
