<?php

namespace App\Http\Controllers\OAuth;

use App\Models\User;
use App\Services\AiOAuthGrantManager;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Http\Controllers\ApproveAuthorizationController as PassportApproveAuthorizationController;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ApproveAuthorizationController extends PassportApproveAuthorizationController
{
    public function __construct(
        AuthorizationServer $server,
        private readonly AiOAuthGrantManager $grants,
    ) {
        parent::__construct($server);
    }

    public function approve(Request $request, ResponseInterface $psrResponse): Response
    {
        return DB::transaction(function () use ($request, $psrResponse): Response {
            $authRequest = $this->getAuthRequestFromSession($request);
            $approval = $request->session()->pull('loraMcpOAuthApproval');
            $tenant = app(TenantContext::class)->tenant();
            $approvalMatches = is_array($approval)
                && isset($approval['auth_token'], $approval['tenant_id'], $approval['host'])
                && hash_equals((string) $approval['auth_token'], (string) $request->input('auth_token'))
                && $tenant
                && (int) $approval['tenant_id'] === (int) $tenant->id
                && hash_equals((string) $approval['host'], strtolower($request->getHost()));

            abort_unless($approvalMatches, 403, 'OAuth approval must be completed on the hotel domain where it started.');

            $user = User::withoutGlobalScopes()->findOrFail($authRequest->getUser()->getIdentifier());
            $scopes = collect($authRequest->getScopes())
                ->map(fn ($scope): string => $scope->getIdentifier())
                ->values()
                ->all();

            try {
                $this->grants->approve(
                    $user,
                    $authRequest->getClient()->getIdentifier(),
                    $scopes,
                    $request,
                );
            } catch (ConflictHttpException) {
                return $this->accessDeniedRedirect($authRequest);
            }

            $authRequest->setAuthorizationApproved(true);

            return $this->withErrorHandling(fn () => $this->convertResponse(
                $this->server->completeAuthorizationRequest($authRequest, $psrResponse)
            ), $authRequest->getGrantTypeId() === 'implicit');
        });
    }

    private function accessDeniedRedirect(AuthorizationRequestInterface $authRequest): Response
    {
        $redirectUri = (string) $authRequest->getRedirectUri();
        $query = http_build_query(array_filter([
            'error' => 'access_denied',
            'error_description' => 'This OAuth client is already connected to another hotel. Disconnect it there first.',
            'state' => $authRequest->getState(),
        ], static fn ($value) => $value !== null));

        return redirect()->away($redirectUri.(str_contains($redirectUri, '?') ? '&' : '?').$query);
    }
}
