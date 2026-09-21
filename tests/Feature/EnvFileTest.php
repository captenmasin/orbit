<?php

namespace Tests\Feature;

use App\EnvFile;
use InvalidArgumentException;
use Tests\TestCase;

class EnvFileTest extends TestCase
{
    public function test_it_parses_literal_env_entries_without_resolving_values(): void
    {
        $entries = app(EnvFile::class)->parse(<<<'ENV'
# Comment
export APP_LOCALE = en # comment
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE="en_US"
APP_EMPTY=
APP_LITERAL="${NOT_RESOLVED} $(not-run)"
APP_MULTILINE="first
second"
ENV);

        $this->assertSame([
            'APP_LOCALE' => 'en',
            'APP_FALLBACK_LOCALE' => 'en',
            'APP_FAKER_LOCALE' => 'en_US',
            'APP_EMPTY' => '',
            'APP_LITERAL' => '${NOT_RESOLVED} $(not-run)',
            'APP_MULTILINE' => "first\nsecond",
        ], $entries);
    }

    public function test_it_serializes_a_round_trip_safe_literal_format(): void
    {
        $entries = ['EMPTY' => '', 'SPACE' => '  preserved  ', 'SPECIAL' => '${VALUE} \\ "'."\n".'second'];
        $serialized = app(EnvFile::class)->serialize($entries);

        $this->assertSame($entries, app(EnvFile::class)->parse($serialized));
    }

    public function test_it_rejects_ambiguous_or_malformed_entries_without_echoing_values(): void
    {
        foreach (["DUPLICATE=one\nDUPLICATE=two", 'VALUE="unterminated', 'INVALID-NAME=value'] as $content) {
            try {
                app(EnvFile::class)->parse($content);
                $this->fail('Expected invalid .env input.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('line', $exception->getMessage());
                $this->assertStringNotContainsString('one', $exception->getMessage());
            }
        }
    }
}
