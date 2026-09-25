<?php

namespace App\Mcp\Tools;

use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Read a project asset in chunks of up to 64 KiB. Text is returned as UTF-8; binary content is base64 encoded. Secret vault values are not available here.')]
#[IsReadOnly]
class ReadAssetTool extends Tool
{
    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'project_id' => ['required', 'uuid'],
            'asset_id' => ['required', 'uuid'],
            'offset' => ['sometimes', 'integer', 'min:0'],
            'length' => ['sometimes', 'integer', 'min:1', 'max:65536'],
        ]);
        $project = Project::findOrFail($data['project_id']);
        $asset = collect($project->asset_files ?? [])->firstWhere('id', $data['asset_id']);
        abort_unless($asset && Storage::disk('local')->exists($asset['path']), 404, 'Asset not found.');
        $path = Storage::disk('local')->path($asset['path']);
        $size = filesize($path);
        abort_unless($size !== false, 404, 'Asset not found.');
        $offset = (int) ($data['offset'] ?? 0);
        if ($offset > $size) {
            throw ValidationException::withMessages(['offset' => 'Choose an offset within the file.']);
        }
        $chunk = file_get_contents($path, false, null, $offset, (int) ($data['length'] ?? 65536));
        abort_unless($chunk !== false, 404, 'Asset not found.');
        $isText = mb_check_encoding($chunk, 'UTF-8') && ! str_contains($chunk, "\0");

        return Response::json([
            'name' => $asset['name'], 'size' => $size, 'mime_type' => Project::assetMimeType($asset['path']),
            'offset' => $offset, 'next_offset' => $offset + strlen($chunk) < $size ? $offset + strlen($chunk) : null,
            'encoding' => $isText ? 'utf8' : 'base64',
            'content' => $isText ? $chunk : base64_encode($chunk),
        ]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->string()->required(),
            'asset_id' => $schema->string()->required(),
            'offset' => $schema->integer()->description('Byte offset, starting at zero.'),
            'length' => $schema->integer()->description('Bytes to read, maximum 65536.'),
        ];
    }
}
