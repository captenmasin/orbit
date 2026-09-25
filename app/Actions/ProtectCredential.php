<?php

namespace App\Actions;

use Illuminate\Support\Facades\Crypt;
use Native\Desktop\Facades\System;
use RuntimeException;
use SensitiveParameter;
use Throwable;

class ProtectCredential
{
    public function encrypt(#[SensitiveParameter] string $value): string
    {
        return $this->transform($value, false);
    }

    public function decrypt(#[SensitiveParameter] string $value): string
    {
        return $this->transform($value, true);
    }

    public function encryptForMcp(#[SensitiveParameter] string $value): string
    {
        return 'orbit-laravel-v1:'.Crypt::encryptString($value);
    }

    private function transform(#[SensitiveParameter] string $value, bool $decrypt): string
    {
        try {
            if (! config('nativephp-internal.running') || ! System::canEncrypt()) {
                throw new RuntimeException;
            }
            if ($decrypt && str_starts_with($value, 'orbit-laravel-v1:')) {
                $result = Crypt::decryptString(substr($value, strlen('orbit-laravel-v1:')));
            } else {
                $result = $decrypt ? System::decrypt($value) : System::encrypt($value);
            }
            if (! is_string($result) || (! $decrypt && $value !== '' && ($result === '' || hash_equals($value, $result)))) {
                throw new RuntimeException;
            }

            return $result;
        } catch (Throwable) {
            throw new RuntimeException('Native credential storage is unavailable. Open the desktop app and try again.');
        }
    }
}
