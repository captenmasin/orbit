<?php

namespace Tests\Feature;

use App\OrbitBackup;
use RuntimeException;
use Tests\TestCase;

class OrbitBackupTest extends TestCase
{
    public function test_it_round_trips_authenticated_literal_records(): void
    {
        $records = [
            ['type' => 'project', 'data' => ['id' => 'project-1', 'name' => 'Orbit']],
            ['type' => 'secret', 'data' => ['name' => 'PRIVATE_KEY', 'value' => "Pässwörd\n-----END KEY-----"]],
        ];
        $backup = app(OrbitBackup::class)->write($records, 'correct horse battery staple');

        $this->assertSame($records, app(OrbitBackup::class)->read($backup, 'correct horse battery staple'));
    }

    public function test_it_rejects_wrong_passwords_and_tampered_incomplete_or_trailing_data(): void
    {
        $backup = app(OrbitBackup::class)->write([['type' => 'project', 'data' => ['id' => 'project-1']]], 'passphrase');
        $variants = ['wrong password' => [$backup, 'wrong'], 'bit flip' => [substr_replace($backup, "\x00", -1, 1), 'passphrase'], 'truncated' => [substr($backup, 0, -1), 'passphrase'], 'trailing' => [$backup.'x', 'passphrase']];

        foreach ($variants as [$input, $password]) {
            try {
                app(OrbitBackup::class)->read($input, $password);
                $this->fail('Expected invalid backup.');
            } catch (RuntimeException $exception) {
                $this->assertSame('The backup password is incorrect or the file is damaged.', $exception->getMessage());
            }
        }
    }
}
