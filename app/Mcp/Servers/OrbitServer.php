<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\ReadProjectTool;
use App\Mcp\Tools\ReadWorkspaceTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Orbit Server')]
#[Version('1.0.0')]
#[Instructions('Read Orbit project metadata. Secret values and credential material are never available through this server.')]
class OrbitServer extends Server
{
    protected array $tools = [
        ReadWorkspaceTool::class,
        ReadProjectTool::class,
    ];
}
