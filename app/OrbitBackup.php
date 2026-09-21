<?php

namespace App;

use JsonException;
use RuntimeException;
use SensitiveParameter;

class OrbitBackup
{
    private const MAGIC = 'ORBITBACKUP1';

    private const VERSION = 1;

    private const MAX_HEADER_BYTES = 4096;

    private const MAX_FRAME_BYTES = 4 * 1024 * 1024;

    private const MAX_TOTAL_BYTES = 256 * 1024 * 1024;

    private const MAX_RECORDS = 100000;

    /**
     * @param  list<array{type: string, data: array<string, mixed>}>  $records
     */
    public function write(array $records, #[SensitiveParameter] string $password): string
    {
        if (! extension_loaded('sodium') || count($records) > self::MAX_RECORDS) {
            throw new RuntimeException('Portable backup encryption is unavailable.');
        }
        $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $opslimit = SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE;
        $memlimit = SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE;
        $key = sodium_crypto_pwhash(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES, $password, $salt, $opslimit, $memlimit, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
        try {
            [$state, $streamHeader] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
            $header = json_encode([
                'version' => self::VERSION,
                'kdf' => ['algorithm' => 'argon2id13', 'opslimit' => $opslimit, 'memlimit' => $memlimit, 'salt' => base64_encode($salt)],
                'stream' => ['algorithm' => 'xchacha20poly1305-secretstream', 'header' => base64_encode($streamHeader)],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $output = self::MAGIC.pack('N', strlen($header)).$header;
            foreach ($records as $record) {
                $output .= $this->frame($state, $this->record($record), $header, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE);
                if (strlen($output) > self::MAX_TOTAL_BYTES) {
                    throw new RuntimeException('Portable backup data is too large.');
                }
            }
            $output .= $this->frame($state, $this->record(['type' => 'complete', 'data' => ['records' => count($records)]]), $header, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL);
            if (strlen($output) > self::MAX_TOTAL_BYTES) {
                throw new RuntimeException('Portable backup data is too large.');
            }

            return $output;
        } catch (JsonException) {
            throw new RuntimeException('Portable backup data could not be encoded.');
        } finally {
            sodium_memzero($key);
            unset($password, $salt, $streamHeader, $header);
        }
    }

    /**
     * @return list<array{type: string, data: array<string, mixed>}>
     */
    public function read(string $backup, #[SensitiveParameter] string $password): array
    {
        try {
            if (! extension_loaded('sodium') || strlen($backup) > self::MAX_TOTAL_BYTES || ! str_starts_with($backup, self::MAGIC)) {
                throw new RuntimeException;
            }
            $offset = strlen(self::MAGIC);
            $headerLength = $this->length($backup, $offset, self::MAX_HEADER_BYTES);
            $header = substr($backup, $offset, $headerLength);
            $offset += $headerLength;
            $metadata = json_decode($header, true, 16, JSON_THROW_ON_ERROR);
            $key = $this->key($metadata, $password);
            try {
                $streamHeader = base64_decode($metadata['stream']['header'], true);
                if (! is_string($streamHeader) || strlen($streamHeader) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES) {
                    throw new RuntimeException;
                }
                $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($streamHeader, $key);
                $records = [];
                while ($offset < strlen($backup)) {
                    $frameLength = $this->length($backup, $offset, self::MAX_FRAME_BYTES);
                    $frame = substr($backup, $offset, $frameLength);
                    $offset += $frameLength;
                    $pull = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $frame, $header);
                    if (! is_array($pull)) {
                        throw new RuntimeException;
                    }
                    [$payload, $tag] = $pull;
                    $record = json_decode($payload, true, 16, JSON_THROW_ON_ERROR);
                    if (! is_array($record) || ! isset($record['type'], $record['data']) || ! is_string($record['type']) || ! is_array($record['data'])) {
                        throw new RuntimeException;
                    }
                    if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                        if ($record['type'] !== 'complete' || $record['data'] !== ['records' => count($records)] || $offset !== strlen($backup)) {
                            throw new RuntimeException;
                        }

                        return $records;
                    }
                    if ($tag !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE || $record['type'] === 'complete' || count($records) >= self::MAX_RECORDS) {
                        throw new RuntimeException;
                    }
                    $records[] = $record;
                }
                throw new RuntimeException;
            } finally {
                sodium_memzero($key);
            }
        } catch (\Throwable) {
            throw new RuntimeException('The backup password is incorrect or the file is damaged.');
        } finally {
            unset($password);
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function key(array $metadata, #[SensitiveParameter] string $password): string
    {
        if (($metadata['version'] ?? null) !== self::VERSION || ($metadata['kdf']['algorithm'] ?? null) !== 'argon2id13' || ($metadata['stream']['algorithm'] ?? null) !== 'xchacha20poly1305-secretstream' || ($metadata['kdf']['opslimit'] ?? null) !== SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE || ($metadata['kdf']['memlimit'] ?? null) !== SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE) {
            throw new RuntimeException;
        }
        $salt = base64_decode($metadata['kdf']['salt'] ?? '', true);
        if (! is_string($salt) || strlen($salt) !== SODIUM_CRYPTO_PWHASH_SALTBYTES) {
            throw new RuntimeException;
        }

        return sodium_crypto_pwhash(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES, $password, $salt, $metadata['kdf']['opslimit'], $metadata['kdf']['memlimit'], SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
    }

    private function frame(string &$state, string $payload, string $header, int $tag): string
    {
        $frame = sodium_crypto_secretstream_xchacha20poly1305_push($state, $payload, $header, $tag);
        if (strlen($frame) > self::MAX_FRAME_BYTES) {
            throw new RuntimeException('Portable backup data is too large.');
        }

        return pack('N', strlen($frame)).$frame;
    }

    /**
     * @param  array{type: string, data: array<string, mixed>}  $record
     */
    private function record(array $record): string
    {
        if ($record['type'] === '' || strlen($record['type']) > 100) {
            throw new RuntimeException('Portable backup data is invalid.');
        }

        return json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function length(string $source, int &$offset, int $maximum): int
    {
        if (strlen($source) - $offset < 4) {
            throw new RuntimeException;
        }
        $length = unpack('N', substr($source, $offset, 4))[1];
        $offset += 4;
        if ($length < 1 || $length > $maximum || strlen($source) - $offset < $length) {
            throw new RuntimeException;
        }

        return $length;
    }
}
