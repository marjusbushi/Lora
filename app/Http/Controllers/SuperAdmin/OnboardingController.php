<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\TenantOnboarding;
use App\Models\TenantOnboardingDocument;
use App\Models\User;
use App\Services\TenantOnboardingService;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OnboardingController extends Controller
{
    public function index(Request $request, TenantOnboardingService $service): Response
    {
        $status = $request->string('status')->toString();
        $search = trim($request->string('q')->toString());

        $tenants = Tenant::query()
            ->with(['onboarding.assignee' => fn ($query) => $query->withoutGlobalScopes()])
            ->when($search !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('name', 'like', "%{$search}%")
                ->orWhere('slug', 'like', "%{$search}%")))
            ->orderBy('name')
            ->get()
            ->map(function (Tenant $tenant) use ($service) {
                $onboarding = $tenant->onboarding ?? $service->findOrCreate($tenant);

                return [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                    'slug' => $tenant->slug,
                    'tenant_status' => $tenant->status,
                    'onboarding' => $service->present($onboarding),
                ];
            })
            ->when($status !== '', fn ($items) => $items->where('onboarding.status', $status))
            ->values();

        return Inertia::render('SuperAdmin/Onboarding/Index', [
            'tenants' => $tenants,
            'filters' => ['q' => $search, 'status' => $status],
        ]);
    }

    public function show(Tenant $tenant, TenantOnboardingService $service): Response
    {
        $tenant->load(['domains' => fn ($query) => $query->orderByDesc('is_primary')]);
        $onboarding = $service->findOrCreate($tenant);
        $onboarding->load(['assignee', 'documents.uploader']);

        return Inertia::render('SuperAdmin/Onboarding/Show', [
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'status' => $tenant->status,
                'timezone' => $tenant->timezone,
                'currency' => $tenant->currency,
                'primary_domain' => $tenant->domains->firstWhere('is_primary', true)?->domain,
            ],
            'onboarding' => $service->present($onboarding),
            'documents' => $onboarding->documents->sortByDesc('id')->values()->map(fn (TenantOnboardingDocument $document) => [
                'id' => $document->id,
                'step_key' => $document->step_key,
                'name' => $document->name,
                'mime_type' => $document->mime_type,
                'size' => $document->size,
                'uploaded_by' => $document->uploader?->name ?? 'Sistemi',
                'created_at' => $document->created_at?->toIso8601String(),
                'download_url' => route('super-admin.onboarding.documents.download', [$tenant, $document]),
            ]),
            'staff' => User::withoutGlobalScopes()
                ->where('is_super_admin', true)
                ->whereNull('deleted_at')
                ->orderBy('name')
                ->get(['id', 'name', 'email']),
        ]);
    }

    public function update(
        Request $request,
        Tenant $tenant,
        TenantOnboardingService $service,
        TenantContext $context,
    ): RedirectResponse {
        $data = $request->validate([
            'assigned_to' => ['nullable', Rule::exists('users', 'id')->where('is_super_admin', true)],
            'due_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:10000'],
        ]);

        $onboarding = $service->findOrCreate($tenant);
        $before = $onboarding->only(['assigned_to', 'due_date', 'notes']);
        $onboarding->update($data);
        $this->audit($context, $tenant, 'tenant.onboarding.update', $onboarding, [
            'before' => $before,
            'after' => $onboarding->fresh()->only(['assigned_to', 'due_date', 'notes']),
        ]);

        return back()->with('success', 'Onboarding-u u përditësua.');
    }

    public function updateStep(
        Request $request,
        Tenant $tenant,
        string $step,
        TenantOnboardingService $service,
        TenantContext $context,
    ): RedirectResponse {
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(['pending', 'in_progress', 'waiting_client'])],
            'notes' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'due_date' => ['sometimes', 'nullable', 'date'],
            'assigned_to' => ['sometimes', 'nullable', Rule::exists('users', 'id')->where('is_super_admin', true)],
        ]);

        $onboarding = $service->updateStep($service->findOrCreate($tenant), $step, $data);
        $this->audit($context, $tenant, 'tenant.onboarding.step.update', $onboarding, [
            'step' => $step,
            'changes' => $data,
        ]);

        return back()->with('success', 'Hapi u përditësua.');
    }

    public function updateTask(
        Request $request,
        Tenant $tenant,
        string $step,
        string $task,
        TenantOnboardingService $service,
        TenantContext $context,
    ): RedirectResponse {
        $data = $request->validate(['completed' => ['required', 'boolean']]);
        $onboarding = $service->updateTask(
            $service->findOrCreate($tenant),
            $step,
            $task,
            (bool) $data['completed'],
            $request->user()?->id,
        );
        $this->audit($context, $tenant, 'tenant.onboarding.task.update', $onboarding, [
            'step' => $step,
            'task' => $task,
            'completed' => (bool) $data['completed'],
        ]);

        return back()->with('success', $data['completed'] ? 'Detyra u përfundua.' : 'Detyra u rihap.');
    }

    public function storeDocument(
        Request $request,
        Tenant $tenant,
        TenantOnboardingService $service,
        TenantContext $context,
    ): RedirectResponse {
        $data = $request->validate([
            'step_key' => ['nullable', Rule::in(array_keys(config('onboarding.steps')))],
            'document' => ['required', 'file', 'max:10240', 'mimes:pdf,xls,xlsx,csv,doc,docx,png,jpg,jpeg,webp'],
        ]);

        $onboarding = $service->findOrCreate($tenant);
        $file = $data['document'];
        $storedName = Str::uuid().'.'.$file->getClientOriginalExtension();
        $path = $file->storeAs("onboarding/tenant-{$tenant->id}", $storedName, 'local');
        $document = $onboarding->documents()->create([
            'uploaded_by' => $request->user()?->id,
            'step_key' => $data['step_key'] ?? null,
            'name' => $file->getClientOriginalName(),
            'disk' => 'local',
            'path' => $path,
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
        ]);
        $this->audit($context, $tenant, 'tenant.onboarding.document.create', $onboarding, [
            'document_id' => $document->id,
            'name' => $document->name,
            'step' => $document->step_key,
        ]);

        return back()->with('success', 'Dokumenti u ngarkua.');
    }

    public function downloadDocument(Tenant $tenant, TenantOnboardingDocument $document): StreamedResponse
    {
        $this->assertDocumentTenant($tenant, $document);
        abort_unless(Storage::disk($document->disk)->exists($document->path), 404);

        return Storage::disk($document->disk)->download($document->path, $document->name);
    }

    public function destroyDocument(
        Tenant $tenant,
        TenantOnboardingDocument $document,
        TenantContext $context,
    ): RedirectResponse {
        $this->assertDocumentTenant($tenant, $document);
        Storage::disk($document->disk)->delete($document->path);
        $name = $document->name;
        $onboarding = $document->onboarding;
        $document->delete();
        $this->audit($context, $tenant, 'tenant.onboarding.document.delete', $onboarding, ['name' => $name]);

        return back()->with('success', 'Dokumenti u hoq.');
    }

    public function activate(
        Tenant $tenant,
        TenantOnboardingService $service,
        TenantContext $context,
    ): RedirectResponse {
        $onboarding = $service->activate($service->findOrCreate($tenant));
        $this->audit($context, $tenant, 'tenant.onboarding.activate', $onboarding, [
            'progress' => $onboarding->progress,
        ]);

        return back()->with('success', 'Onboarding-u u përfundua dhe hoteli është gati për dorëzim.');
    }

    private function assertDocumentTenant(Tenant $tenant, TenantOnboardingDocument $document): void
    {
        abort_unless($document->onboarding()->where('tenant_id', $tenant->id)->exists(), 404);
    }

    private function audit(
        TenantContext $context,
        Tenant $tenant,
        string $action,
        TenantOnboarding $subject,
        array $properties,
    ): void {
        $context->run($tenant, fn () => AuditLog::record($action, $subject, $properties));
    }
}
