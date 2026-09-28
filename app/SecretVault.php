<?php

namespace App;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Native\Desktop\Facades\Settings;
use Throwable;

class SecretVault
{
    public function __construct(private WorkspacePreferences $preferences) {}

    public function pinHash(): ?string
    {
        if (! config('nativephp-internal.running')) {
            return DB::table('secret_vaults')->where('id', 1)->value('pin_hash');
        }
        try {
            $hash = Settings::get('secrets.pin_hash');
        } catch (Throwable) {
            throw ValidationException::withMessages(['pin' => 'PIN settings are unavailable. Try again.']);
        }
        if ($hash === null && ($legacy = DB::table('secret_vaults')->where('id', 1)->value('pin_hash'))) {
            $this->savePinHash($legacy, null);

            return $legacy;
        }

        return is_string($hash) && $hash !== '' ? $hash : null;
    }

    /**
     * Commit destructive resets before publishing credentials: native settings cannot roll back with the database.
     * Keep the current recovery hash until the new PIN is saved, so interrupted writes remain recoverable.
     */
    public function savePinHash(string $hash, ?string $previousHash, ?string $recoveryHash = null, bool $resetSecrets = false): void
    {
        abort_unless(config('nativephp-internal.running'), 403);
        $secretsDeleted = false;
        try {
            Cache::lock('secret-vault-pin-write', 30)->block(3, function () use ($hash, $previousHash, $recoveryHash, $resetSecrets, &$secretsDeleted): void {
                if (Settings::get('secrets.pin_hash') !== $previousHash) {
                    throw ValidationException::withMessages(['pin' => 'The PIN changed. Try again.']);
                }
                if ($resetSecrets) {
                    DB::transaction(function (): void {
                        DB::table('projects')->increment('revision');
                        DB::table('project_secrets')->delete();
                        $this->revokeUnlocks();
                    });
                    $secretsDeleted = true;
                }
                if ($recoveryHash !== null) {
                    $recovery = [hash('sha256', $hash) => $recoveryHash];
                    if ($previousHash !== null && ($previousRecoveryHash = $this->recoveryHash($previousHash)) !== null) {
                        $recovery[hash('sha256', $previousHash)] = $previousRecoveryHash;
                    }
                    Settings::set('secrets.recovery', $recovery);
                    if (Settings::get('secrets.recovery') !== $recovery) {
                        throw ValidationException::withMessages(['pin' => 'Your recovery code could not be saved. Try again.']);
                    }
                }
                Settings::set('secrets.pin_hash', $hash);
                if (Settings::get('secrets.pin_hash') !== $hash) {
                    throw ValidationException::withMessages(['pin' => 'Your PIN could not be saved. Try again.']);
                }
                DB::table('secret_vaults')->where('id', 1)->where('pin_hash', $previousHash ?? $hash)->delete();
            });
        } catch (Throwable $exception) {
            if ($secretsDeleted) {
                throw ValidationException::withMessages(['pin' => 'Secrets were deleted, but your new PIN could not be saved. Try resetting again.']);
            }
            throw $exception instanceof ValidationException ? $exception : ValidationException::withMessages(['pin' => 'Your PIN could not be saved. Try again.']);
        }
    }

    public function recoveryHash(string $pinHash): ?string
    {
        abort_unless(config('nativephp-internal.running'), 403);
        try {
            $recovery = Settings::get('secrets.recovery');
        } catch (Throwable) {
            throw ValidationException::withMessages(['recovery_code' => 'Recovery settings are unavailable. Try again.']);
        }

        $hash = is_array($recovery) ? ($recovery[hash('sha256', $pinHash)] ?? null) : null;

        return is_string($hash) && $hash !== '' ? $hash : null;
    }

    public function unlockedUntil(Request $request): ?int
    {
        $until = (int) $request->session()->get('secret_pin_unlocked_until', 0);
        $version = $request->session()->get('secret_pin_version');
        $security = $this->preferences->get('security');
        if ($until <= now()->timestamp || ! is_string($version)
            || $request->session()->get('secret_pin_generation') !== $security['generation']
            || $request->session()->get('secret_pin_lock_minutes') !== $security['lock_minutes']) {
            $this->lock($request);

            return null;
        }
        $hash = $this->pinHash();

        if (! $hash || ! hash_equals(hash('sha256', $hash), $version)) {
            $this->lock($request);

            return null;
        }

        return $until;
    }

    public function unlock(Request $request, string $hash): void
    {
        $security = $this->preferences->get('security');
        $request->session()->regenerate();
        $request->session()->put([
            'secret_pin_unlocked_until' => now()->addMinutes($security['lock_minutes'])->timestamp,
            'secret_pin_version' => hash('sha256', $hash),
            'secret_pin_generation' => $security['generation'],
            'secret_pin_lock_minutes' => $security['lock_minutes'],
        ]);
    }

    public function lock(Request $request): void
    {
        $request->session()->forget(['secret_pin_unlocked_until', 'secret_pin_version', 'secret_pin_generation', 'secret_pin_lock_minutes']);
    }

    public function revokeUnlocks(): void
    {
        $this->preferences->merge(['security' => ['generation' => random_int(1, 2147483647)]]);
    }
}
