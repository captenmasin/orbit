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
use RuntimeException;
use SensitiveParameter;

class SecretVaultController extends Controller
{
    public function __construct(private SecretVault $vault, private WorkspacePreferences $preferences) {}

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
        $this->vault->savePinHash($hash, null);
        $this->vault->revokeUnlocks();
        $this->vault->unlock($request, $hash);

        return $this->pinSaved($request);
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
        $this->vault->savePinHash($newHash, $hash);
        $this->vault->revokeUnlocks();
        $this->vault->lock($request);

        return $this->pinSaved($request);
    }

    private function pinSaved(Request $request): JsonResponse
    {
        $response = $this->status($request);
        $response->setData([...$response->getData(true), 'revision' => $this->preferences->snapshot()['revision']]);

        return $response;
    }

    /**
     * @return array{pin: string, pin_confirmation?: string, current_pin?: string}
     */
    private function validatedPins(Request $request, bool $confirmation, bool $currentPin = false): array
    {
        $pins = $request->only(['pin', 'pin_confirmation', 'current_pin']);
        foreach (['pin', 'pin_confirmation', 'current_pin'] as $field) {
            $request->request->remove($field);
            $request->json()->remove($field);
        }
        $rules = ['pin' => ['required', 'string', 'regex:/\A[0-9]{4}\z/D']];
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
