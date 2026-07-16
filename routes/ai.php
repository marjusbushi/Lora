<?php

use App\Http\Middleware\ResolveMcpTenant;
use App\Mcp\Servers\LoraHotelServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::oauthRoutes();

Mcp::web('/mcp/lora-hotel', LoraHotelServer::class)
    ->middleware(['auth:api', ResolveMcpTenant::class, 'throttle:mcp']);
