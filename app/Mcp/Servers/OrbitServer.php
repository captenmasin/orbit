<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\AddSecretTool;
use App\Mcp\Tools\ManageAssetTool;
use App\Mcp\Tools\ManageBoardTool;
use App\Mcp\Tools\ManageDocumentTool;
use App\Mcp\Tools\ManageProjectTool;
use App\Mcp\Tools\ReadAssetTool;
use App\Mcp\Tools\ReadProjectTool;
use App\Mcp\Tools\ReadWorkspaceTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Orbit Server')]
#[Version('1.0.0')]
#[Instructions('Read and manage Orbit projects, documents, boards, links, local folders, and assets. Secret values may be added but are never returned. Read a project before editing so you can supply its current revision.')]
class OrbitServer extends Server
{
    protected array $tools = [
        ReadWorkspaceTool::class,
        ReadProjectTool::class,
        ReadAssetTool::class,
        ManageProjectTool::class,
        ManageDocumentTool::class,
        ManageBoardTool::class,
        ManageAssetTool::class,
        AddSecretTool::class,
    ];
}
