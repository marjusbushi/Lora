<?php

namespace App\Http\Controllers\OAuth;

use App\Models\User;
use App\Services\AiOAuthGrantManager;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Contracts\AuthorizationViewResponse;
use Laravel\Passport\Http\Controllers\AuthorizationController as PassportAuthorizationController;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class AuthorizationController extends PassportAuthorizationController
{
    public function __construct(
        AuthorizationServer $server,
        StatefulGuard $guard,
        ClientRepository $clients,
        private readonly AiOAuthGrantManager $grants,
    ) {
        parent::__construct($server, $guard, $clients);
    }

    public function authorize(
        ServerRequestInterface $psrRequest,
        Request $request,
        ResponseInterface $psrResponse,
        AuthorizationViewResponse $viewResponse,
    ): Response|AuthorizationViewResponse {
        $response = parent::authorize($psrRequest, $request, $psrResponse, $viewResponse);

        if ($response instanceof AuthorizationViewResponse) {
            $tenant = app(TenantContext::class)->tenant();
            abort_unless($tenant, 404, 'Hotel not found.');

            $request->session()->put('loraMcpOAuthApproval', [
                'auth_token' => (string) $request->session()->get('authToken'),
                'tenant_id' => $tenant->id,
                'host' => strtolower($request->getHost()),
            ]);
        }

        return $response;
    }

    protected function approveRequest(AuthorizationRequestInterface $authRequest, ResponseInterface $psrResponse): Response
    {
        return DB::transaction(function () use ($authRequest, $psrResponse): Response {
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
                    app(Request::class),
                );
            } catch (ConflictHttpException) {
                return $this->accessDeniedRedirect($authRequest);
            }

            return parent::approveRequest($authRequest, $psrResponse);
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
