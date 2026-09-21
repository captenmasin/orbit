<?php

namespace App;

use InvalidArgumentException;

class EnvFile
{
    /**
     * @return array<string, string>
     */
    public function parse(string $content): array
    {
        if (! mb_check_encoding($content, 'UTF-8') || str_contains($content, "\0")) {
            throw new InvalidArgumentException('The file must be valid UTF-8 text.');
        }
        $lines = preg_split('/\r\n|\r|\n/', $content);
        $entries = [];
        for ($index = 0; $index < count($lines); $index++) {
            $line = $lines[$index];
            if (trim($line) === '' || str_starts_with(ltrim($line), '#')) {
                continue;
            }
            if (! preg_match('/\A(?:export[ \t]+)?([A-Za-z_][A-Za-z0-9_]*)[ \t]*=(.*)\z/D', $line, $match)) {
                $this->invalid($index + 1);
            }
            $name = $match[1];
            if (isset($entries[$name])) {
                $this->invalid($index + 1, 'Duplicate name');
            }
            $value = ltrim($match[2], " \t");
            if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
                [$value, $index] = $this->quoted($value, $lines, $index);
            } elseif (str_contains($value, '"') || str_contains($value, "'")) {
                $this->invalid($index + 1);
            } else {
                $value = preg_replace('/[ \t]+#.*/', '', $value) ?? '';
                $value = rtrim($value, " \t");
            }
            $entries[$name] = $value;
        }

        return $entries;
    }

    /**
     * @param  array<string, string>  $entries
     */
    public function serialize(array $entries): string
    {
        $lines = [];
        foreach ($entries as $name => $value) {
            if (! is_string($name) || ! preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $name) || ! is_string($value) || ! mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0")) {
                throw new InvalidArgumentException('Entries must use valid names and UTF-8 values.');
            }
            $lines[] = $name.'="'.str_replace(['\\', '"', '$', "\n", "\r", "\t"], ['\\\\', '\\"', '\\$', '\\n', '\\r', '\\t'], $value).'"';
        }

        return $lines ? implode("\n", $lines)."\n" : '';
    }

    /**
     * @param  list<string>  $lines
     * @return array{string, int}
     */
    private function quoted(string $value, array $lines, int $line): array
    {
        $quote = $value[0];
        $value = substr($value, 1);
        $parsed = '';
        for (; $line < count($lines); $line++) {
            for ($offset = 0; $offset < strlen($value); $offset++) {
                $character = $value[$offset];
                if ($character === $quote) {
                    $remaining = ltrim(substr($value, $offset + 1), " \t");
                    if ($remaining !== '' && ! str_starts_with($remaining, '#')) {
                        $this->invalid($line + 1);
                    }

                    return [$parsed, $line];
                }
                if ($quote === '"' && $character === '\\') {
                    $next = $value[++$offset] ?? null;
                    if (! is_string($next) || ! array_key_exists($next, ['"' => '"', '\\' => '\\', '$' => '$', 'n' => "\n", 'r' => "\r", 't' => "\t"])) {
                        $this->invalid($line + 1);
                    }
                    $parsed .= ['"' => '"', '\\' => '\\', '$' => '$', 'n' => "\n", 'r' => "\r", 't' => "\t"][$next];
                } else {
                    $parsed .= $character;
                }
            }
            if ($line + 1 < count($lines)) {
                $parsed .= "\n";
                $value = $lines[$line + 1];
            }
        }
        $this->invalid(count($lines), 'Missing closing quote');
    }

    private function invalid(int $line, string $reason = 'Invalid entry'): never
    {
        throw new InvalidArgumentException($reason.' on line '.$line.'.');
    }
}
