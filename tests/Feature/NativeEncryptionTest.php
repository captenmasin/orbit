<?php

namespace Tests\Feature;

use App\Actions\ProtectCredential;
use Native\Desktop\Facades\System;
use Tests\TestCase;

class NativeEncryptionTest extends TestCase
{
    public function test_native_encryption_round_trips_exact_secret_text(): void
    {
        $values = ['', '  leading and trailing  ', 'Pässwörd 🔐', "-----BEGIN KEY-----\nabc123\n-----END KEY-----\n"];
        config(['nativephp-internal.running' => true]);
        System::shouldReceive('canEncrypt')->times(count($values) * 2)->andReturn(true);
        System::shouldReceive('encrypt')->andReturnUsing(static fn (string $value): string => 'native:'.base64_encode($value));
        System::shouldReceive('decrypt')->andReturnUsing(static fn (string $value): string => base64_decode(substr($value, 7), true) ?: '');

        foreach ($values as $value) {
            $ciphertext = app(ProtectCredential::class)->encrypt($value);

            $this->assertNotSame($value, $ciphertext);
            $this->assertSame($value, app(ProtectCredential::class)->decrypt($ciphertext));
        }
    }
}
