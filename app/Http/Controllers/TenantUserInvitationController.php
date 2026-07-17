<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\TenantUserInvitation;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

class TenantUserInvitationController extends Controller
{
    public function show(
        Request $request,
        TenantUserInvitation $invitation,
        TenantContext $context,
    ): View {
        $this->assertRecipient($request->user(), $invitation);
        $this->assertInvitationTenant($invitation, $context);

        $invitation->loadMissing(['tenant', 'role']);
        abort_unless($invitation->tenant?->status === 'active', 403);
        abort_unless((int) $invitation->role?->team_id === (int) $invitation->tenant_id, 403);

        if (! $invitation->accepted_at) {
            abort_if($invitation->expires_at->isPast(), 403, 'Ftesa ka skaduar.');
        }

        return view('tenant-invitations.show', [
            'invitation' => $invitation,
            'acceptUrl' => $invitation->accepted_at
                ? null
                : $this->signedUrl($request, 'tenant-invitations.accept', $invitation),
        ]);
    }

    public function accept(
        Request $request,
        TenantUserInvitation $invitation,
        TenantContext $context,
    ): RedirectResponse {
        $this->assertRecipient($request->user(), $invitation);
        $this->assertInvitationTenant($invitation, $context);

        DB::transaction(function () use ($request, $invitation, $context) {
            $locked = TenantUserInvitation::query()
                ->with(['tenant', 'role'])
                ->lockForUpdate()
                ->findOrFail($invitation->getKey());

            $user = $request->user();
            $this->assertRecipient($user, $locked);
            $this->assertInvitationTenant($locked, $context);

            if ($locked->accepted_at) {
                abort_unless((int) $locked->accepted_by === (int) $user->id, 403);

                return;
            }

            abort_if($locked->expires_at->isPast(), 403, 'Ftesa ka skaduar.');
            abort_unless($locked->tenant?->status === 'active', 403);
            abort_unless((int) $locked->role?->team_id === (int) $locked->tenant_id, 403);

            $context->run($locked->tenant, function () use ($locked, $user) {
                $membership = DB::table('tenant_user')
                    ->where('tenant_id', $locked->tenant_id)
                    ->where('user_id', $user->id)
                    ->lockForUpdate()
                    ->first();

                if ($membership) {
                    DB::table('tenant_user')
                        ->where('tenant_id', $locked->tenant_id)
                        ->where('user_id', $user->id)
                        ->update(['is_active' => true, 'updated_at' => now()]);
                } else {
                    DB::table('tenant_user')->insert([
                        'tenant_id' => $locked->tenant_id,
                        'user_id' => $user->id,
                        'is_owner' => false,
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                $user->unsetRelation('roles')->syncRoles([$locked->role]);

                $locked->forceFill([
                    'accepted_by' => $user->id,
                    'accepted_at' => now(),
                ])->save();

                AuditLog::record('user.invitation.accept', $user, [
                    'role' => $locked->role->name,
                ]);
            });
        });

        return redirect()->to($this->signedUrl(
            $request,
            'tenant-invitations.show',
            $invitation->fresh(),
        ))->with('success', 'Ftesa u pranua me sukses.');
    }

    private function assertRecipient(User $user, TenantUserInvitation $invitation): void
    {
        $authenticatedEmail = strtolower(trim((string) $user->email));

        abort_unless(
            (int) $invitation->user_id === (int) $user->id
                && hash_equals($invitation->email, $authenticatedEmail),
            403,
        );
    }

    private function assertInvitationTenant(TenantUserInvitation $invitation, TenantContext $context): void
    {
        abort_unless($context->id() === (int) $invitation->tenant_id, 403);
    }

    private function signedUrl(
        Request $request,
        string $route,
        TenantUserInvitation $invitation,
    ): string {
        $relative = URL::temporarySignedRoute(
            $route,
            $invitation->expires_at,
            ['invitation' => $invitation],
            absolute: false,
        );

        return $request->getSchemeAndHttpHost().$relative;
    }
}
