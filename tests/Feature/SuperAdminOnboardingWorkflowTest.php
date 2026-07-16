<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantOnboarding;
use App\Models\User;
use App\Services\TenantOnboardingService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class SuperAdminOnboardingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        config(['lora.control_panel_hosts' => ['localhost']]);
        $this->tenant = Tenant::query()->sole();
        app(TenantContext::class)->set($this->tenant);
        $this->superAdmin = User::factory()->create([
            'is_super_admin' => true,
            'current_tenant_id' => $this->tenant->id,
        ]);
        app(TenantContext::class)->clear();
    }

    public function test_super_admin_can_open_the_onboarding_workspace(): void
    {
        $this->actingAs($this->superAdmin)
            ->get(route('super-admin.onboarding.show', $this->tenant))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('SuperAdmin/Onboarding/Show')
                ->where('tenant.id', $this->tenant->id)
                ->has('onboarding.steps', 8)
                ->has('staff', 1));

        $this->assertDatabaseHas('tenant_onboardings', ['tenant_id' => $this->tenant->id]);
    }

    public function test_task_progress_ownership_and_audit_are_persisted(): void
    {
        $service = app(TenantOnboardingService::class);
        $onboarding = $service->findOrCreate($this->tenant);

        $this->actingAs($this->superAdmin)
            ->patch(route('super-admin.onboarding.update', $this->tenant), [
                'assigned_to' => $this->superAdmin->id,
                'due_date' => '2026-07-22',
                'notes' => 'Klienti po dërgon politikat.',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->actingAs($this->superAdmin)
            ->patch(route('super-admin.onboarding.tasks.update', [$this->tenant, 'pricing', 'taxes']), [
                'completed' => true,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $onboarding->refresh();
        $this->assertSame($this->superAdmin->id, $onboarding->assigned_to);
        $this->assertSame('2026-07-22', $onboarding->due_date->toDateString());
        $this->assertTrue($onboarding->steps['pricing']['tasks']['taxes']['completed']);
        $this->assertGreaterThan(0, $onboarding->progress);
        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $this->tenant->id,
            'action' => 'tenant.onboarding.task.update',
        ]);
    }

    public function test_activation_is_blocked_until_every_task_is_complete(): void
    {
        $service = app(TenantOnboardingService::class);
        $onboarding = $service->findOrCreate($this->tenant);

        $this->actingAs($this->superAdmin)
            ->post(route('super-admin.onboarding.activate', $this->tenant))
            ->assertSessionHasErrors('onboarding');

        $steps = $onboarding->steps;
        foreach ($steps as &$step) {
            foreach ($step['tasks'] as &$task) {
                $task['completed'] = true;
                $task['completed_at'] = now()->toIso8601String();
                $task['completed_by'] = $this->superAdmin->id;
            }
        }
        $service->saveSteps($onboarding, $steps);

        $this->actingAs($this->superAdmin)
            ->post(route('super-admin.onboarding.activate', $this->tenant))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $onboarding->refresh();
        $this->assertSame('completed', $onboarding->status);
        $this->assertSame(100, $onboarding->progress);
        $this->assertNotNull($onboarding->activated_at);
    }

    public function test_onboarding_documents_are_private_and_scoped_to_the_tenant(): void
    {
        Storage::fake('local');

        $this->actingAs($this->superAdmin)
            ->post(route('super-admin.onboarding.documents.store', $this->tenant), [
                'step_key' => 'pricing',
                'document' => UploadedFile::fake()->create('politikat.pdf', 120, 'application/pdf'),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $document = TenantOnboarding::query()->whereBelongsTo($this->tenant)->firstOrFail()->documents()->firstOrFail();
        Storage::disk('local')->assertExists($document->path);

        $this->actingAs($this->superAdmin)
            ->get(route('super-admin.onboarding.documents.download', [$this->tenant, $document]))
            ->assertOk();

        $otherTenant = Tenant::factory()->create();
        $this->actingAs($this->superAdmin)
            ->get(route('super-admin.onboarding.documents.download', [$otherTenant, $document]))
            ->assertNotFound();

        $this->actingAs($this->superAdmin)
            ->delete(route('super-admin.onboarding.documents.destroy', [$this->tenant, $document]))
            ->assertRedirect();
        Storage::disk('local')->assertMissing($document->path);
    }
}
