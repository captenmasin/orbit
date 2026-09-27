<?php

namespace App\Http\Controllers;

use App\Actions\ProtectCredential;
use App\ScratchpadAi;
use App\WorkspacePreferences;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

use function Laravel\Ai\agent;

class ScratchpadAiController extends Controller
{
    public function update(Request $request, ScratchpadAi $ai, ProtectCredential $credentials, WorkspacePreferences $preferences): JsonResponse
    {
        abort_unless(config('nativephp-internal.running'), 403);
        $data = $request->validate([
            'revision' => ['required', 'integer', 'min:1'],
            'provider' => ['required', Rule::in(ScratchpadAi::PROVIDERS)],
            'model' => ['required', 'string', 'max:255', 'regex:/\S/u'],
            'key' => ['required', 'string', 'max:4096', 'regex:/\S/u'],
        ]);
        try {
            $result = $ai->using($data['provider'], trim($data['model']), $data['key'], fn (string $provider, string $model) => agent(
                instructions: 'Return ready=true to verify this structured-output connection. No user data is supplied.',
                schema: fn (JsonSchema $schema): array => ['ready' => $schema->boolean()->required()],
            )->prompt('Verify structured output.', provider: $provider, model: $model, timeout: 15));
            if (($result['ready'] ?? false) !== true) {
                throw ValidationException::withMessages(['key' => 'The provider did not return valid structured output.']);
            }
            $encrypted = $credentials->encrypt($data['key']);
        } catch (Throwable) {
            throw ValidationException::withMessages(['key' => 'Verification failed. Check the provider, API key and model ID, then try again. Your saved connection is unchanged.']);
        }
        $snapshot = $preferences->update('ai', ['provider' => $data['provider'], 'model' => trim($data['model']), 'credential' => $encrypted], (int) $data['revision']);

        return response()->json(['revision' => $snapshot['revision'], 'ai' => $ai->status()]);
    }

    public function destroy(Request $request, ScratchpadAi $ai, WorkspacePreferences $preferences): JsonResponse
    {
        abort_unless(config('nativephp-internal.running'), 403);
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1']]);
        $snapshot = $preferences->update('ai', ['provider' => null, 'model' => null, 'credential' => null], (int) $data['revision']);

        return response()->json(['revision' => $snapshot['revision'], 'ai' => $ai->status()]);
    }
}
