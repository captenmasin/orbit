<?php

namespace App\Http\Controllers;

use App\Actions\ProtectCredential;
use App\Actions\RecordAccessEvent;
use App\Models\Project;
use App\SecretVault;
use App\WorkspacePreferences;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Native\Desktop\Client\Client;
use Native\Desktop\Facades\System;
use RuntimeException;
use SensitiveParameter;
use Throwable;

class SecretVaultController extends Controller
{
    public function __construct(private SecretVault $vault, private WorkspacePreferences $preferences, private Client $nativeClient) {}

    public function status(Request $request): JsonResponse
    {
        $unlockedUntil = $this->vault->unlockedUntil($request);

        return response()->json([
            'pin_set' => $this->vault->pinHash() !== null,
            'unlocked' => $unlockedUntil !== null,
            'unlocked_until' => $unlockedUntil,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function setup(Request $request): JsonResponse
    {
        abort_unless(config('nativephp-internal.running'), 403);
        $pins = $this->validatedPins($request, true);
        if ($this->vault->pinHash() !== null) {
            throw ValidationException::withMessages(['pin' => 'A PIN is already set. Unlock with it.']);
        }
        $hash = Hash::make($pins['pin']);
        $recoveryCode = $this->recoveryCode();
        $this->vault->savePinHash($hash, null, $this->recoveryDigest($recoveryCode));
        $this->vault->revokeUnlocks();
        $this->vault->unlock($request, $hash);

        return $this->pinSaved($request, $recoveryCode);
    }

    public function unlock(Request $request): JsonResponse
    {
        abort_unless(config('nativephp-internal.running'), 403);
        $pins = $this->validatedPins($request, false);
        $hash = $this->vault->pinHash();
        if (! $hash) {
            throw ValidationException::withMessages(['pin' => 'Set up a PIN first.']);
        }
        $this->verifyPin($pins['pin'], $hash, 'pin');
        $this->vault->unlock($request, $hash);

        return $this->status($request);
    }

    public function lock(Request $request): JsonResponse
    {
        $this->vault->lock($request);

        return response()->json(['locked' => true]);
    }

    public function values(Project $project, ProtectCredential $crypto, RecordAccessEvent $events): JsonResponse
    {
        $values = [];
        try {
            foreach ($project->secrets as $secret) {
                $values[$secret->id] = $crypto->decrypt($secret->ciphertext);
                $events->handle('reveal', 'Succeeded', secretId: $secret->id);
            }

            return response()->json(['values' => $values])->header('Cache-Control', 'private, no-store');
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422)->header('Cache-Control', 'private, no-store');
        } finally {
            unset($values);
        }
    }

    public function update(Request $request): JsonResponse
    {
        abort_unless(config('nativephp-internal.running'), 403);
        $pins = $this->validatedPins($request, true, true);
        $hash = $this->vault->pinHash();
        if (! $hash) {
            throw ValidationException::withMessages(['current_pin' => 'Set up a PIN first.']);
        }
        $this->verifyPin($pins['current_pin'], $hash, 'current_pin');
        $newHash = Hash::make($pins['pin']);
        $recoveryCode = $this->recoveryCode();
        $this->vault->savePinHash($newHash, $hash, $this->recoveryDigest($recoveryCode));
        $this->vault->revokeUnlocks();
        $this->vault->lock($request);

        return $this->pinSaved($request, $recoveryCode);
    }

    public function recoveryStatus(): JsonResponse
    {
        abort_unless(config('nativephp-internal.running'), 403);
        $hash = $this->vault->pinHash();

        return response()->json([
            'touch_id_available' => $hash !== null && $this->touchIdAvailable(),
            'windows_hello_available' => $hash !== null && $this->windowsHelloAvailable(),
            'recovery_code_set' => $hash !== null && $this->vault->recoveryHash($hash) !== null,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function recover(Request $request): JsonResponse
    {
        abort_unless(config('nativephp-internal.running'), 403);
        $data = $this->validatedPins($request, true, extraRules: [
            'method' => ['required', 'in:touch_id,windows_hello,recovery_code'],
            'recovery_code' => ['required_if:method,recovery_code', 'nullable', 'string', 'max:200'],
        ]);
        $this->limitRecovery('secret-vault-recovery');
        $hash = $this->vault->pinHash();
        if ($hash === null) {
            throw ValidationException::withMessages(['pin' => 'Set up a PIN first.']);
        }
        if ($data['method'] !== 'recovery_code') {
            try {
                $verified = $data['method'] === 'windows_hello'
                    ? $this->windowsHelloAvailable() && $this->nativeClient->post('system/windows-hello')->throw()->json('verified') === true
                    : $this->touchIdAvailable() && System::promptTouchID('Reset your Orbit secrets PIN');
            } catch (Throwable) {
                $verified = false;
            }
            if (! $verified) {
                $name = $data['method'] === 'windows_hello' ? 'Windows Hello' : 'Touch ID';
                throw ValidationException::withMessages(['method' => $name.' was cancelled or is unavailable. Try again or use your recovery code.']);
            }
        } else {
            $recoveryHash = $this->vault->recoveryHash($hash);
            if ($recoveryHash === null || ! hash_equals($recoveryHash, $this->recoveryDigest($data['recovery_code']))) {
                throw ValidationException::withMessages(['recovery_code' => 'Incorrect recovery code.']);
            }
        }
        $recoveryCode = $this->recoveryCode();
        $this->vault->savePinHash(Hash::make($data['pin']), $hash, $this->recoveryDigest($recoveryCode));
        $this->vault->revokeUnlocks();
        $this->clearRecoverySession($request);
        RateLimiter::clear('secret-vault-pin');
        RateLimiter::clear('secret-vault-recovery');

        return $this->pinSaved($request, $recoveryCode);
    }

    public function reset(Request $request): JsonResponse
    {
        abort_unless(config('nativephp-internal.running'), 403);
        $data = $this->validatedPins($request, true, extraRules: [
            'confirmation' => ['required', 'in:DELETE ALL SECRETS'],
        ]);
        $this->limitRecovery('secret-vault-reset');
        $hash = $this->vault->pinHash();
        if ($hash === null) {
            throw ValidationException::withMessages(['pin' => 'Set up a PIN first.']);
        }
        $recoveryCode = $this->recoveryCode();
        $this->clearRecoverySession($request);
        $this->vault->savePinHash(Hash::make($data['pin']), $hash, $this->recoveryDigest($recoveryCode), resetSecrets: true);
        RateLimiter::clear('secret-vault-pin');
        RateLimiter::clear('secret-vault-recovery');

        return $this->pinSaved($request, $recoveryCode);
    }

    private function touchIdAvailable(): bool
    {
        if (PHP_OS_FAMILY !== 'Darwin') {
            return false;
        }
        try {
            return System::canPromptTouchID();
        } catch (Throwable) {
            return false;
        }
    }

    private function windowsHelloAvailable(): bool
    {
        try {
            return $this->nativeClient->get('system/windows-hello')->throw()->json('available') === true;
        } catch (Throwable) {
            return false;
        }
    }

    private function recoveryCode(): string
    {
        return implode('-', str_split(strtoupper(bin2hex(random_bytes(32))), 8));
    }

    private function recoveryDigest(#[SensitiveParameter] string $code): string
    {
        return hash('sha256', strtoupper(preg_replace('/[\\s-]+/', '', $code)));
    }

    private function limitRecovery(string $key): void
    {
        abort_if(RateLimiter::tooManyAttempts($key, 5), 429, 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.');
        RateLimiter::hit($key, 300);
    }

    private function clearRecoverySession(Request $request): void
    {
        $this->vault->lock($request);
        foreach (array_keys($request->session()->all()) as $key) {
            if (str_starts_with($key, 'secret-import:') || str_starts_with($key, 'secret-export:')) {
                $request->session()->forget($key);
            }
        }
        $request->session()->migrate(true);
    }

    private function pinSaved(Request $request, #[SensitiveParameter] string $recoveryCode): JsonResponse
    {
        $response = $this->status($request);
        $response->setData([...$response->getData(true), 'revision' => $this->preferences->snapshot()['revision'], 'recovery_code' => $recoveryCode]);

        return $response;
    }

    /**
     * @param  array<string, list<string>>  $extraRules
     * @return array<string, string>
     */
    private function validatedPins(Request $request, bool $confirmation, bool $currentPin = false, array $extraRules = []): array
    {
        $fields = ['pin', 'pin_confirmation', 'current_pin', ...array_keys($extraRules)];
        $pins = $request->only($fields);
        foreach ($fields as $field) {
            $request->request->remove($field);
            $request->json()->remove($field);
        }
        $rules = ['pin' => ['required', 'string', 'regex:/\A[0-9]{4}\z/D'], ...$extraRules];
        if ($confirmation) {
            $rules['pin_confirmation'] = ['required', 'same:pin'];
        }
        if ($currentPin) {
            $rules['current_pin'] = $rules['pin'];
        }

        return Validator::make($pins, $rules)->validate();
    }

    private function verifyPin(#[SensitiveParameter] string $pin, string $hash, string $field): void
    {
        abort_if(RateLimiter::tooManyAttempts('secret-vault-pin', 5), 429, 'Too many attempts. Try again in '.RateLimiter::availableIn('secret-vault-pin').' seconds.');
        if (! Hash::check($pin, $hash)) {
            RateLimiter::hit('secret-vault-pin', 300);
            throw ValidationException::withMessages([$field => 'Incorrect PIN.']);
        }
        RateLimiter::clear('secret-vault-pin');
    }
}
