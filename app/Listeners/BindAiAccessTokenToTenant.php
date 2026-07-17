<?php

namespace App\Listeners;

use App\Services\AiOAuthGrantManager;
use Laravel\Passport\Events\AccessTokenCreated;

class BindAiAccessTokenToTenant
{
    public function __construct(private readonly AiOAuthGrantManager $grants) {}

    public function handle(AccessTokenCreated $event): void
    {
        if (! $event->userId || ! $event->clientId) {
            return;
        }

        $this->grants->bindAccessToken($event->tokenId, $event->userId, $event->clientId);
    }
}
