<?php

use App\Http\Controllers\OAuth\RegisterController;
use App\Http\Middleware\ResolveMcpTenant;
use App\Mcp\Servers\LoraHotelServer;
use App\Support\McpOAuthMetadata;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server\Registrar;
use Laravel\Passport\Http\Middleware\CheckToken;

Registrar::ensureMcpScope();

Route::middleware('throttle:mcp-oauth-public')->group(function (): void {
    Route::get('/.well-known/oauth-protected-resource', fn () => response()->json(McpOAuthMetadata::protectedResource()))
        ->name('mcp.oauth.protected-resource');
    Route::get('/.well-known/oauth-authorization-server', fn () => response()->json(McpOAuthMetadata::authorizationServer()))
        ->name('mcp.oauth.authorization-server');
    Route::get('/.well-known/oauth-protected-resource/{path}', fn (string $path) => response()->json(McpOAuthMetadata::protectedResource($path)))
        ->where('path', '.*')
        ->name('mcp.oauth.protected-resource.nested');
    Route::get('/.well-known/oauth-authorization-server/{path}', fn () => response()->json(McpOAuthMetadata::authorizationServer()))
        ->where('path', '.*')
        ->name('mcp.oauth.authorization-server.nested');
    Route::post('/oauth/register', RegisterController::class)->name('mcp.oauth.register');
});

Mcp::web('/mcp/lora-hotel', LoraHotelServer::class)
    ->middleware([
        'auth:api',
        CheckToken::using('mcp:use'),
        ResolveMcpTenant::class,
        'throttle:mcp',
    ]);
