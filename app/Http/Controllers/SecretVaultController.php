<?php

namespace App\Http\Controllers;

use App\Actions\ProtectCredential;
use App\Actions\RecordAccessEvent;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class SecretVaultController extends Controller
{
    public function status(Request $request): JsonResponse
    {
        $pinSet = DB::table('secret_vaults')->where('id', 1)->exists();

        return response()->json([
            'pin_set' => $pinSet,
            'unlocked' => $pinSet && (int) $request->session()->get('secret_pin_unlocked_until', 0) > now()->timestamp,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function setup(Request $request): JsonResponse
    {
        abort_unless(config('nativephp-internal.running'), 403);
        $pin = $this->validatedPin($request, true);
        if (DB::table('secret_vaults')->insertOrIgnore(['id' => 1, 'pin_hash' => Hash::make($pin)]) !== 1) {
            throw ValidationException::withMessages(['pin' => 'A PIN is already set. Unlock with it.']);
        }
        $this->unlockSession($request);

        return response()->json(['unlocked' => true])->header('Cache-Control', 'private, no-store');
    }

    public function unlock(Request $request): JsonResponse
    {
        abort_unless(config('nativephp-internal.running'), 403);
        $pin = $this->validatedPin($request, false);
        $hash = DB::table('secret_vaults')->where('id', 1)->value('pin_hash');
        if (! $hash) {
            throw ValidationException::withMessages(['pin' => 'Set up a PIN first.']);
        }
        if (RateLimiter::tooManyAttempts('secret-vault-pin', 5)) {
            return response()->json(['message' => 'Too many attempts. Try again in '.RateLimiter::availableIn('secret-vault-pin').' seconds.'], 429);
        }
        if (! Hash::check($pin, $hash)) {
            RateLimiter::hit('secret-vault-pin', 300);
            throw ValidationException::withMessages(['pin' => 'Incorrect PIN.']);
        }
        RateLimiter::clear('secret-vault-pin');
        $this->unlockSession($request);

        return response()->json(['unlocked' => true])->header('Cache-Control', 'private, no-store');
    }

    public function lock(Request $request): JsonResponse
    {
        $request->session()->forget('secret_pin_unlocked_until');

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

    private function validatedPin(Request $request, bool $confirmation): string
    {
        $pin = $request->input('pin');
        $confirmedPin = $request->input('pin_confirmation');
        $request->request->remove('pin');
        $request->request->remove('pin_confirmation');
        $request->json()->remove('pin');
        $request->json()->remove('pin_confirmation');
        $rules = ['pin' => ['required', 'string', 'regex:/\A[0-9]{4}\z/D']];
        if ($confirmation) {
            $rules['pin_confirmation'] = ['required', 'same:pin'];
        }

        return Validator::make(['pin' => $pin, 'pin_confirmation' => $confirmedPin], $rules)->validate()['pin'];
    }

    private function unlockSession(Request $request): void
    {
        $request->session()->regenerate();
        $request->session()->put('secret_pin_unlocked_until', now()->addMinutes(5)->timestamp);
    }
}
