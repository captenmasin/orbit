<?php

namespace App\Http\Controllers;

use App\Actions\ProtectCredential;
use App\Actions\ProviderHttp;
use App\Actions\QueueProviderRefresh;
use App\Actions\ReadProvider;
use App\Models\Project;
use App\Models\ProviderConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use RuntimeException;
use Throwable;

class ProviderController extends Controller
{
    public function save(Request $request, ProtectCredential $crypto, ReadProvider $reader, ?ProviderConnection $connection = null): JsonResponse
    {
        // Tokens leave the request before validation/exception rendering can serialize it.
        $token = $request->input('token');
        $request->request->remove('token');
        $request->json()->remove('token');
        $data = $request->validate(['provider' => ['required', Rule::in(['github', 'gitlab'])], 'label' => ['required', 'string', 'max:100'], 'revision' => ['required_with:id', 'nullable', 'integer', 'min:1']]);
        if ($connection && ($connection->revision !== ($data['revision'] ?? null) || $connection->provider !== $data['provider'])) {
            return response()->json(['message' => 'Connection changed. Reload before replacing it.'], 409);
        }
        if (Validator::make(['token' => $token], ['token' => ['required', 'string', 'max:4096', 'regex:/^[\x21-\x7E]+$/D']])->fails()) {
            return response()->json(['message' => 'Enter a token without spaces or line breaks.', 'errors' => ['connection' => 'Enter a token without spaces or line breaks.']], 422);
        }
        try {
            $encrypted = $crypto->encrypt($token);
            if (! hash_equals($token, $crypto->decrypt($encrypted))) {
                throw new RuntimeException('Native credential storage is unavailable. Open the desktop app and try again.');
            }
            $lock = $connection ? Cache::lock('provider:'.$connection->id, 45) : null;
            if ($lock && ! $lock->get()) {
                throw new RuntimeException('Connection busy. Try again.');
            }
            try {
                $identity = $reader->identity($data['provider'], $token);
            } finally {
                $lock?->release();
            }
            if ($connection && $connection->account_id !== $identity['account_id']) {
                return response()->json(['message' => 'This token belongs to another account. Add a separate connection.', 'errors' => ['connection' => 'This token belongs to another account. Add a separate connection.']], 422);
            }
            $saved = DB::transaction(function () use ($connection, $data, $identity, $encrypted): ProviderConnection {
                $values = [...$identity, 'label' => $data['label'], 'provider' => $data['provider'], 'encrypted_token' => $encrypted,
                    'verified_at' => now(), 'retry_at' => null, 'state' => 'Current'];
                if (! $connection) {
                    return ProviderConnection::create($values);
                }
                if (! ProviderConnection::whereKey($connection->id)->where('revision', $data['revision'])->update([...$values, 'revision' => $connection->revision + 1])) {
                    throw new RuntimeException('Connection changed. Reload and try again.', 409);
                }
                foreach ($connection->repositories()->get() as $repository) {
                    $repository->providerSnapshots()->delete();
                    $repository->forceFill(['provider_revision' => $repository->provider_revision + 1, 'remote_commit_at' => null])->save();
                }

                return $connection->fresh();
            });
            $saved->recordAccess($connection ? 'replace' : 'create', 'Succeeded');

            return response()->json(['saved' => true]);
        } catch (Throwable $exception) {
            $message = get_class($exception) === RuntimeException::class ? $exception->getMessage() : 'Connection could not be verified.';

            return response()->json(['message' => $message, 'errors' => ['connection' => $message]], $exception->getCode() === 409 ? 409 : 422);
        } finally {
            unset($token, $encrypted);
        }
    }

    public function destroy(Request $request, ProviderConnection $connection): JsonResponse
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1']]);

        return DB::transaction(function () use ($connection, $data): JsonResponse {
            if ($connection->fresh()?->revision !== $data['revision']) {
                return response()->json(['message' => 'Connection changed. Reload before removing it.'], 409);
            }
            foreach ($connection->repositories()->get() as $repository) {
                $repository->disconnectProvider();
                $repository->save();
            }
            $connection->recordAccess('remove', 'Succeeded');
            $connection->delete();

            return response()->json(['removed' => true]);
        });
    }

    public function repositories(Request $request, ProviderConnection $connection, ProviderHttp $http): JsonResponse
    {
        if ($connection->provider !== 'github') {
            return response()->json(['message' => 'Choose a GitHub connection.'], 422);
        }
        $page = $request->validate(['page' => ['sometimes', 'integer', 'min:1', 'max:10000']])['page'] ?? 1;

        try {
            $result = $http->using($connection, fn (string $token): array => $http->get('github', '/user/repos', $token, ['per_page' => 100, 'page' => $page, 'sort' => 'updated']));
            if (! array_is_list($result['data'])) {
                throw new RuntimeException('Invalid provider response');
            }
            $repositories = [];
            foreach ($result['data'] as $row) {
                if (! is_array($row) || ! is_int($row['id'] ?? null) || $row['id'] < 1 || ! is_string($row['full_name'] ?? null) || ! is_bool($row['private'] ?? null)
                    || ! preg_match('~^([A-Za-z0-9-]+)/([A-Za-z0-9_.-]+)$~D', $row['full_name'], $name) || in_array($name[2], ['.', '..'], true)) {
                    continue;
                }
                $repositories[] = [
                    'id' => (string) $row['id'],
                    'full_name' => $row['full_name'],
                    'name' => $name[2],
                    'remote_url' => 'https://github.com/'.$row['full_name'].'.git',
                    'private' => $row['private'],
                    'description' => is_string($row['description'] ?? null) ? $row['description'] : null,
                ];
            }

            return response()->json(['repositories' => $repositories, 'next_page' => $result['next_page']])->header('Cache-Control', 'no-store, private');
        } catch (Throwable $exception) {
            $message = get_class($exception) === RuntimeException::class ? $exception->getMessage() : 'Repositories could not be loaded.';

            return response()->json(['message' => $message, 'errors' => ['connection' => [$message]]], 422);
        }
    }

    public function associate(Request $request, Project $project, string $repository, ReadProvider $reader, ProviderHttp $http): JsonResponse
    {
        $repository = $project->repositories()->findOrFail($repository);
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1'], 'connection_id' => ['nullable', 'uuid', 'exists:provider_connections,id'], 'name' => ['required_with:connection_id', 'nullable', 'string', 'max:255']]);
        if ($repository->provider_revision !== $data['revision']) {
            return response()->json(['message' => 'Repository connection changed. Reload and try again.'], 409);
        }
        $connection = isset($data['connection_id']) ? ProviderConnection::findOrFail($data['connection_id']) : null;
        try {
            $metadata = $connection ? $http->using($connection, fn (string $credential): array => $reader->repository($connection->provider, $data['name'], $credential)) : [];

            return DB::transaction(function () use ($repository, $connection, $metadata, $data): JsonResponse {
                $current = $repository->fresh();
                if (! $current || $current->provider_revision !== $data['revision'] || ($connection && $connection->fresh()?->revision !== $connection->revision)) {
                    return response()->json(['message' => 'Connection changed. Reload and try again.'], 409);
                }
                $current->disconnectProvider();
                $current->forceFill([...$metadata, 'provider_connection_id' => $connection?->id])->save();
                if ($connection) {
                    app(QueueProviderRefresh::class)->handle($current);
                }

                return response()->json(['saved' => true]);
            });
        } catch (RuntimeException $exception) {
            $message = get_class($exception) === RuntimeException::class ? $exception->getMessage() : 'Repository connection could not be saved.';

            return response()->json(['message' => $message, 'errors' => ['connection' => $message]], 422);
        }
    }

    public function refresh(Request $request, Project $project, string $repository, QueueProviderRefresh $queue): JsonResponse
    {
        $repository = $project->repositories()->findOrFail($repository);
        $data = $request->validate(['resource' => ['required', Rule::in(['overview', 'issues', 'requests', 'checks', 'statuses'])], 'only_stale' => ['boolean'], 'more' => ['boolean']]);
        if ($data['resource'] === 'statuses' && $repository->providerConnection?->provider !== 'github') {
            abort(422);
        }

        return response()->json(['queued' => $queue->handle($repository, $data['resource'], $data['only_stale'] ?? false, $data['more'] ?? false)]);
    }
}
