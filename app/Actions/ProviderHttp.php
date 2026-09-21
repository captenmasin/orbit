<?php

namespace App\Actions;

use App\Models\ProviderConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use SensitiveParameter;
use Throwable;

class ProviderHttp
{
    /** @return array{data: array, status: int, etag: ?string, next_page: ?int} */
    public function get(string $provider, string $path, #[SensitiveParameter] string $token, array $query = [], ?string $etag = null): array
    {
        $origin = match ($provider) {
            'github' => 'https://api.github.com',
            'gitlab' => 'https://gitlab.com/api/v4',
            default => throw new RuntimeException('Unsupported provider'),
        };
        if (! preg_match('~^/(user|repositories/[0-9]+(?:/[^?#]*)?|repos/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+|projects/[A-Za-z0-9%_.-]+(?:/[^?#]*)?)$~D', $path) || str_contains($path, '..') || str_contains($path, '\\')) {
            throw new RuntimeException('Invalid provider request');
        }
        try {
            $headers = $provider === 'github'
                ? ['Authorization' => 'Bearer '.$token, 'X-GitHub-Api-Version' => '2026-03-10', 'Accept' => 'application/vnd.github+json']
                : ['PRIVATE-TOKEN' => $token, 'Accept' => 'application/json'];
            if ($etag && strlen($etag) <= 512 && ! preg_match('/[\r\n]/', $etag)) {
                $headers['If-None-Match'] = $etag;
            }
            $response = Http::withHeaders($headers)->withUserAgent('Orbit/0.5.2')->connectTimeout(5)->timeout(15)
                ->withoutRedirecting()->withHeaders(['Accept-Encoding' => 'identity'])->withOptions([
                    'decode_content' => false,
                    'on_headers' => function (ResponseInterface $response): void {
                        if ((int) $response->getHeaderLine('Content-Length') > 2 * 1024 * 1024) {
                            throw new RuntimeException('Response too large');
                        }
                    },
                    'progress' => function (float $total, float $downloaded): void {
                        if ($downloaded > 2 * 1024 * 1024) {
                            throw new RuntimeException('Response too large');
                        }
                    },
                ])->get($origin.$path, $query);
        } catch (Throwable) {
            throw new RuntimeException('Provider unavailable or response too large');
        }
        if ($response->status() === 401) {
            throw new RuntimeException('Token required', 401);
        }
        if ($response->status() === 429 || ($response->status() === 403 && ($response->header('X-RateLimit-Remaining') === '0' || $response->header('Retry-After')))) {
            $retry = $response->header('Retry-After');
            $at = ctype_digit((string) $retry) ? time() + (int) $retry : (strtotime($retry ?: '') ?: 0);
            $reset = $response->header('X-RateLimit-Reset') ?: $response->header('RateLimit-Reset');
            $at = max(time() + 60, $at, ctype_digit((string) $reset) ? (int) $reset : 0);
            throw new RuntimeException('Rate limited', $at);
        }
        if ($response->status() === 403) {
            throw new RuntimeException('Access unavailable. Check token permissions and organization approval.', 403);
        }
        if ($response->status() === 404) {
            throw new RuntimeException('Repository unavailable or inaccessible', 404);
        }
        if ($response->status() === 409) {
            throw new RuntimeException('Empty repository', 409);
        }
        if ($response->status() === 304) {
            return ['data' => [], 'status' => 304, 'etag' => $etag, 'next_page' => null];
        }
        if (! $response->successful()) {
            throw new RuntimeException('Provider unavailable');
        }
        try {
            if (strlen($response->body()) > 2 * 1024 * 1024) {
                throw new RuntimeException;
            }
            $data = json_decode($response->body(), true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($data)) {
                throw new RuntimeException;
            }
            $next = null;
            if (preg_match('/<([^>]+)>;\s*rel="next"/', $response->header('Link') ?? '', $match)) {
                $parts = parse_url($match[1]);
                $expected = parse_url($origin.$path);
                if (($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== $expected['host'] || ($parts['path'] ?? '') !== $expected['path'] || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['fragment'])) {
                    throw new RuntimeException;
                }
                parse_str($parts['query'] ?? '', $nextQuery);
                $next = $nextQuery['page'] ?? null;
            } elseif ($provider === 'gitlab' && $response->header('X-Next-Page')) {
                $next = $response->header('X-Next-Page');
            }
            if ($next !== null && (! ctype_digit((string) $next) || (int) $next <= (int) ($query['page'] ?? 1) || (int) $next > 10000)) {
                throw new RuntimeException;
            }
            $validator = $response->header('ETag');

            return ['data' => $data, 'status' => 200, 'etag' => is_string($validator) && strlen($validator) <= 512 ? $validator : null, 'next_page' => $next ? (int) $next : null];
        } catch (Throwable) {
            throw new RuntimeException('Invalid provider response');
        }
    }

    public function using(ProviderConnection $connection, callable $read): array
    {
        $lock = Cache::lock('provider:'.$connection->id, 45);
        if (! $lock->get()) {
            throw new RuntimeException('Connection busy. Try again.');
        }
        try {
            $current = $connection->fresh();
            if (! $current || $current->revision !== $connection->revision) {
                throw new RuntimeException('Connection changed. Reload and try again.');
            }
            if ($current->state === 'Token required' || $current->retry_at?->isFuture()) {
                throw new RuntimeException($current->state);
            }
            $token = app(ProtectCredential::class)->decrypt($current->encrypted_token);
            $result = $read($token);
            $current->recordAccess('read', 'Succeeded');

            return $result;
        } catch (RuntimeException $exception) {
            $values = match (true) {
                $exception->getCode() === 401 => ['state' => 'Token required', 'retry_at' => null],
                $exception->getMessage() === 'Rate limited' && $exception->getCode() > time() => ['state' => 'Rate limited', 'retry_at' => date('Y-m-d H:i:s', $exception->getCode())],
                default => [],
            };
            if ($values) {
                ProviderConnection::whereKey($connection->id)->where('revision', $connection->revision)->update($values);
            }
            $connection->recordAccess('read', $exception->getCode() === 401 ? 'Token required' : 'Failed');
            throw $exception;
        } finally {
            unset($token);
            $lock->release();
        }
    }
}
