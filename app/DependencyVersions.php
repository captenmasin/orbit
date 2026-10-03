<?php

namespace App;

use RuntimeException;

class DependencyVersions
{
    public const OSV = ['composer' => 'Packagist', 'npm' => 'npm', 'python' => 'PyPI', 'rust' => 'crates.io', 'go' => 'Go', 'ruby' => 'RubyGems', 'nuget' => 'NuGet', 'dart' => 'Pub', 'maven' => 'Maven'];

    public static function name(string $ecosystem, string $name): string
    {
        return match ($ecosystem) {
            'python' => strtolower(preg_replace('/[-_.]+/', '-', $name)),
            'nuget' => strtolower($name),
            default => $name,
        };
    }

    public static function validName(string $ecosystem, string $name): bool
    {
        $pattern = match ($ecosystem) {
            'composer' => '/\A[a-z0-9_.-]+\/[a-z0-9_.-]+\z/i',
            'npm' => '/\A(?:@[a-z0-9_.-]+\/)?[a-z0-9_.-]+\z/i',
            'go' => '/\A[a-zA-Z0-9][a-zA-Z0-9_.~\/-]*\z/',
            'maven' => '/\A[a-zA-Z0-9_.-]+:[a-zA-Z0-9_.-]+\z/',
            default => '/\A[a-zA-Z0-9][a-zA-Z0-9_.-]*\z/',
        };

        return strlen($name) <= 255 && (bool) preg_match($pattern, $name) && ! array_intersect(['.', '..'], explode('/', $name));
    }

    public static function valid(string $ecosystem, mixed $version, bool $stable = false): bool
    {
        if (! is_string($version) || strlen($version) > 255) {
            return false;
        }
        preg_match_all('/\d+/', $version, $numbers);
        if (array_any($numbers[0], fn (string $number): bool => strlen(ltrim($number, '0')) > 18)) {
            return false;
        }
        if ($ecosystem === 'python') {
            $parts = self::python($version);

            return $parts !== null && (! $stable || (! isset($parts['pre']) && ! isset($parts['dev']) && ! isset($parts['local'])));
        }
        if ($ecosystem === 'maven' && $stable) {
            $parts = self::maven($version);

            return $parts !== null && $parts['rank'] >= 5;
        }
        if (in_array($ecosystem, ['rust', 'go', 'dart'], true)) {
            $number = '(?:0|[1-9][0-9]*)';
            $identifier = '(?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*)';
            $prerelease = $stable ? '' : '(?:-'.$identifier.'(?:\.'.$identifier.')*)?';

            return (bool) preg_match('/\Av?'.$number.'\.'.$number.'\.'.$number.$prerelease.'(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?\z/', $version);
        }
        $pattern = match ($ecosystem) {
            'ruby' => $stable ? '/\Av?\d+(?:\.\d+)*\z/' : '/\Av?\d+(?:\.[a-z0-9]+)*(?:-[a-z0-9-]+(?:\.[a-z0-9-]+)*)?\z/i',
            'maven' => '/\Av?\d[a-zA-Z0-9._+-]*\z/',
            'composer' => $stable ? '/\Av?\d+(?:\.\d+)*(?:\+[\w.-]+)?\z/' : '/\Av?\d+(?:[._+-][a-zA-Z0-9]+)*\z/',
            default => $stable ? '/\Av?\d+\.\d+\.\d+(?:\.\d+)?(?:\+[\w.-]+)?\z/' : '/\Av?\d+\.\d+\.\d+(?:\.\d+)?(?:[-+][\w.+-]+)?\z/',
        };

        return (bool) preg_match($pattern, $version);
    }

    public static function comparable(string $ecosystem, mixed $version): bool
    {
        return self::valid($ecosystem, $version) && ($ecosystem !== 'maven' || self::maven($version) !== null);
    }

    public static function compare(string $ecosystem, string $first, string $second): int
    {
        if ($ecosystem === 'maven') {
            $left = self::maven($first);
            $right = self::maven($second);
            if ($left === null || $right === null) {
                throw new RuntimeException('Unsupported version comparison');
            }
            $length = max(count($left['release']), count($right['release']));

            return array_pad($left['release'], $length, 0) <=> array_pad($right['release'], $length, 0)
                ?: $left['rank'] <=> $right['rank']
                ?: strcmp($left['qualifier'], $right['qualifier'])
                ?: $left['number'] <=> $right['number'];
        }
        if ($ecosystem === 'ruby') {
            $left = self::rubySegments($first);
            $right = self::rubySegments($second);
            for ($index = 0; $index < max(count($left), count($right)); $index++) {
                $a = $left[$index] ?? 0;
                $b = $right[$index] ?? 0;
                $difference = is_int($a) && is_int($b) ? $a <=> $b
                    : (is_int($a) !== is_int($b) ? is_int($a) <=> is_int($b) : strcmp($a, $b));
                if ($difference !== 0) {
                    return $difference;
                }
            }

            return 0;
        }
        if ($ecosystem === 'python') {
            $left = self::python($first);
            $right = self::python($second);
            foreach (['epoch', 'release', 'pre', 'post', 'dev'] as $field) {
                $a = match ($field) {
                    'epoch' => [(int) ($left['epoch'] ?? 0)],
                    'release' => array_map('intval', explode('.', $left['release'])),
                    'pre' => self::pythonPre($left),
                    'post' => [isset($left['post']) ? (int) ($left['postnum'] ?? 0) : -1],
                    'dev' => [isset($left['dev']) ? (int) ($left['devnum'] ?? 0) : PHP_INT_MAX],
                };
                $b = match ($field) {
                    'epoch' => [(int) ($right['epoch'] ?? 0)],
                    'release' => array_map('intval', explode('.', $right['release'])),
                    'pre' => self::pythonPre($right),
                    'post' => [isset($right['post']) ? (int) ($right['postnum'] ?? 0) : -1],
                    'dev' => [isset($right['dev']) ? (int) ($right['devnum'] ?? 0) : PHP_INT_MAX],
                };
                $length = max(count($a), count($b));
                $difference = array_pad($a, $length, 0) <=> array_pad($b, $length, 0);
                if ($difference !== 0) {
                    return $difference;
                }
            }
            $a = $left['local'] ?? null;
            $b = $right['local'] ?? null;
            if ($a === null || $b === null) {
                return ($a !== null) <=> ($b !== null);
            }
            $a = preg_split('/[._-]/', strtolower($a));
            $b = preg_split('/[._-]/', strtolower($b));
            foreach ($a as $index => $part) {
                if (! isset($b[$index])) {
                    return 1;
                }
                $other = $b[$index];
                $difference = ctype_digit($part) && ctype_digit($other) ? (int) $part <=> (int) $other
                    : (ctype_digit($part) !== ctype_digit($other) ? ctype_digit($part) <=> ctype_digit($other) : strcmp($part, $other));
                if ($difference !== 0) {
                    return $difference;
                }
            }

            return count($a) <=> count($b);
        }
        $first = preg_replace('/\+.*/', '', ltrim($first, 'v'));
        $second = preg_replace('/\+.*/', '', ltrim($second, 'v'));
        if (in_array($ecosystem, ['npm', 'rust', 'go', 'nuget', 'dart'], true)) {
            [$releaseA, $preA] = array_pad(explode('-', $first, 2), 2, null);
            [$releaseB, $preB] = array_pad(explode('-', $second, 2), 2, null);
            $a = array_map('intval', explode('.', $releaseA));
            $b = array_map('intval', explode('.', $releaseB));
            $length = max(count($a), count($b));
            $difference = array_pad($a, $length, 0) <=> array_pad($b, $length, 0);
            if ($difference !== 0) {
                return $difference;
            }
            if ($preA === null || $preB === null) {
                return ($preA === null) <=> ($preB === null);
            }
            $a = explode('.', $preA);
            $b = explode('.', $preB);
            foreach ($a as $index => $part) {
                if (! isset($b[$index])) {
                    return 1;
                }
                $other = $b[$index];
                $difference = ctype_digit($part) && ctype_digit($other) ? (int) $part <=> (int) $other
                    : (ctype_digit($part) !== ctype_digit($other) ? ctype_digit($other) <=> ctype_digit($part) : ($ecosystem === 'nuget' ? strcasecmp($part, $other) : strcmp($part, $other)));
                if ($difference !== 0) {
                    return $difference;
                }
            }

            return count($a) <=> count($b);
        }

        return version_compare($first, $second);
    }

    /** @return array{release: array, rank: int, qualifier: string, number: int}|null */
    private static function maven(string $version): ?array
    {
        // ponytail: compare one Maven qualifier; reject nested qualifiers until a full ComparableVersion port is needed.
        if (! preg_match('/\Av?(?<release>\d+(?:\.\d+)*)(?:[.-]?(?<qualifier>[a-z]+)(?<number>\d+)?)?\z/i', $version, $parts, PREG_UNMATCHED_AS_NULL)) {
            return null;
        }
        $qualifier = strtolower($parts['qualifier'] ?? '');
        $qualifier = match ($qualifier) {
            'a' => 'alpha', 'b' => 'beta', 'm' => 'milestone', 'cr' => 'rc', 'ga', 'final', 'release' => '',
            default => $qualifier,
        };
        $rank = array_search($qualifier, ['alpha', 'beta', 'milestone', 'rc', 'snapshot', '', 'sp'], true);

        return ['release' => array_map('intval', explode('.', $parts['release'])), 'rank' => $rank === false ? 7 : $rank, 'qualifier' => $rank === false ? $qualifier : '', 'number' => (int) ($parts['number'] ?? 0)];
    }

    private static function rubySegments(string $version): array
    {
        $version = str_replace('-', '.pre.', ltrim($version, 'v'));
        preg_match_all('/[0-9]+|[a-z]+/i', $version, $matches);
        $segments = array_map(fn (string $part): int|string => ctype_digit($part) ? (int) $part : $part, $matches[0]);
        $firstQualifier = array_find_key($segments, fn (int|string $part): bool => is_string($part));
        if ($firstQualifier !== null) {
            while ($firstQualifier > 0 && $segments[$firstQualifier - 1] === 0) {
                array_splice($segments, --$firstQualifier, 1);
            }
        }
        while ($segments && end($segments) === 0) {
            array_pop($segments);
        }

        return $segments;
    }

    private static function python(string $version): ?array
    {
        $pattern = '/\Av?(?:(?<epoch>\d+)!)?(?<release>\d+(?:\.\d+)*)(?:[-_.]?(?<pre>a|b|rc|alpha|beta|c|pre|preview)[-_.]?(?<prenum>\d+)?)?(?:(?:-(?<implicitpost>\d+))|(?<post>[-_.]?(?:post|rev|r))[-_.]?(?<postnum>\d+)?)?(?:(?<dev>[-_.]?dev)[-_.]?(?<devnum>\d+)?)?(?:\+(?<local>[a-z0-9]+(?:[-_.][a-z0-9]+)*))?\z/i';
        if (! preg_match($pattern, $version, $parts, PREG_UNMATCHED_AS_NULL)) {
            return null;
        }
        if (isset($parts['implicitpost'])) {
            $parts['post'] = 'post';
            $parts['postnum'] = $parts['implicitpost'];
        }

        return $parts;
    }

    private static function pythonPre(array $parts): array
    {
        if (! isset($parts['pre'])) {
            return [! isset($parts['post']) && isset($parts['dev']) ? -1 : 3, 0];
        }
        $stage = match (strtolower($parts['pre'])) {
            'a', 'alpha' => 0,
            'b', 'beta' => 1,
            default => 2,
        };

        return [$stage, (int) ($parts['prenum'] ?? 0)];
    }
}
