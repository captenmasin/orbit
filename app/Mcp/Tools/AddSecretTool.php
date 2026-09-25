<?php

namespace App\Mcp\Tools;

use App\Actions\ProtectCredential;
use App\Actions\RecordAccessEvent;
use App\Models\Project;
use App\Rules\ProjectUrl;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

#[Description('Add an encrypted secret to a project. This is write-only: the secret value and ciphertext are never returned by Orbit MCP.')]
class AddSecretTool extends Tool
{
    public function handle(Request $request, ProtectCredential $crypto, RecordAccessEvent $events): Response
    {
        $data = $request->validate([
            'project_id' => ['required', 'uuid'],
            'project_revision' => ['required', 'integer', 'min:1'],
            'environment' => ['required', 'string', 'max:100', 'regex:/\S/u'],
            'name' => ['required', 'string', 'max:255', 'regex:/\A[A-Za-z_][A-Za-z0-9_]*\z/D'],
            'value' => ['present', 'string', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8') || strlen($value) > 1048576) {
                    $fail('The value must be valid UTF-8 text no larger than 1 MB.');
                }
            }],
            'service' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            'management_url' => ['nullable', 'string', 'max:2048', new ProjectUrl],
        ]);
        $project = Project::findOrFail($data['project_id']);
        $data['environment'] = trim($data['environment']);
        $ciphertext = $crypto->encryptForMcp($data['value']);
        unset($data['value']);

        $secret = DB::transaction(function () use ($project, $data, $ciphertext, $events) {
            if ($project->secrets()->where('environment', $data['environment'])->where('name', $data['name'])->exists()) {
                throw ValidationException::withMessages(['name' => 'A secret with this name already exists in this environment.']);
            }
            if (! Project::whereKey($project->id)->where('revision', $data['project_revision'])->update(['revision' => DB::raw('revision + 1')])) {
                throw new ConflictHttpException('This project changed. Reload before adding its secret.');
            }
            $secret = $project->secrets()->create([
                'environment' => $data['environment'],
                'name' => $data['name'],
                'ciphertext' => $ciphertext,
                'service' => isset($data['service']) ? (trim($data['service']) ?: null) : null,
                'description' => $data['description'] ?? null,
                'management_url' => $data['management_url'] ?? null,
            ]);
            $events->handle('create', 'Succeeded', secretId: $secret->id);

            return $secret;
        });

        unset($ciphertext);

        return Response::json(['id' => $secret->id, 'name' => $secret->name, 'environment' => $secret->environment, 'project_revision' => $project->fresh()->revision]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->string()->required(),
            'project_revision' => $schema->integer()->description('Current project revision.')->required(),
            'environment' => $schema->string()->required(),
            'name' => $schema->string()->description('Environment variable style name, such as API_KEY.')->required(),
            'value' => $schema->string()->description('Secret text to store; never returned.')->required(),
            'service' => $schema->string()->nullable(),
            'description' => $schema->string()->nullable(),
            'management_url' => $schema->string()->nullable(),
        ];
    }
}
