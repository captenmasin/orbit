<?php

use App\Mcp\Servers\OrbitServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::local('orbit', OrbitServer::class);
