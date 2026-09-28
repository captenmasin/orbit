<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;
use Symfony\Component\Filesystem\Path;

class AbsoluteLocalPath implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::isAbsolute($value)) {
            $fail('Choose an absolute local path.');
        }
    }

    public static function isAbsolute(string $path): bool
    {
        return ! str_contains($path, "\0") && ! str_contains($path, '://') && ! preg_match('/\A[A-Za-z]:\z/D', $path) && Path::isAbsolute($path);
    }
}
