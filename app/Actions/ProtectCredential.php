<?php

namespace App\Actions;

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

    private function transform(#[SensitiveParameter] string $value, bool $decrypt): string
    {
        try {
            if (! config('nativephp-internal.running') || ! System::canEncrypt()) {
                throw new RuntimeException;
            }
            $result = $decrypt ? System::decrypt($value) : System::encrypt($value);
            if (! is_string($result) || (! $decrypt && ($result === '' || hash_equals($value, $result)))) {
                throw new RuntimeException;
            }

            return $result;
        } catch (Throwable) {
            throw new RuntimeException('Native credential storage is unavailable. Open the desktop app and try again.');
        }
    }
}
