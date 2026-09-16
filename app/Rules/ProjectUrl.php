<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ProjectUrl implements ValidationRule
{
    public function __construct(private bool $repository = false) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match('/[\x00-\x20\x7f\\\\]/', $value)) {
            $fail('Enter a valid URL without spaces or control characters.');

            return;
        }
        if ($this->repository && preg_match('~\A[\w.-]+@[a-zA-Z0-9.-]+:[\w./\~-]+\z~', $value)) {
            return;
        }
        $parts = parse_url($value);
        $scheme = strtolower($parts['scheme'] ?? '');
        $schemes = $this->repository ? ['http', 'https', 'ssh'] : ['http', 'https', 'mailto', 'tel', 'vscode', 'phpstorm'];
        if (! $parts || ! in_array($scheme, $schemes, true) || isset($parts['pass'])
            || (isset($parts['user']) && $scheme !== 'ssh')) {
            $fail('Use a supported URL without embedded credentials.');

            return;
        }
        if (in_array($scheme, ['http', 'https', 'ssh'], true)) {
            if (! filter_var($value, FILTER_VALIDATE_URL) || empty($parts['host'])) {
                $fail('Enter a valid repository or website URL.');
            }
        } elseif (empty($parts['path']) && empty($parts['host'])) {
            $fail('Enter a complete URL.');
        }
    }
}
