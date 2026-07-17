<?php

namespace App\Listeners;

use App\Services\AiOAuthGrantManager;
use Laravel\Passport\Events\RefreshTokenCreated;

class ValidateAiRefreshToken
{
    public function __construct(private readonly AiOAuthGrantManager $grants) {}

    public function handle(RefreshTokenCreated $event): void
    {
        $this->grants->validateRefreshToken($event->refreshTokenId, $event->accessTokenId);
    }
}
