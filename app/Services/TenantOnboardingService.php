<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\TenantOnboarding;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class TenantOnboardingService
{
    public function findOrCreate(Tenant $tenant): TenantOnboarding
    {
        $onboarding = TenantOnboarding::query()->firstOrCreate(
            ['tenant_id' => $tenant->id],
            [
                'status' => 'not_started',
                'progress' => 0,
                'steps' => $this->defaultSteps($tenant),
            ],
        );

        $steps = $this->mergeDefinitions($onboarding->steps, $tenant);

        return $onboarding->wasRecentlyCreated || $steps !== $onboarding->steps
            ? $this->saveSteps($onboarding, $steps)
            : $onboarding;
    }

    public function defaultSteps(Tenant $tenant): array
    {
        $steps = [];
        foreach (config('onboarding.steps') as $stepKey => $definition) {
            $tasks = [];
            foreach ($definition['tasks'] as $taskKey => $task) {
                $tasks[$taskKey] = ['completed' => false, 'completed_at' => null, 'completed_by' => null];
            }
            $steps[$stepKey] = [
                'status' => 'pending',
                'notes' => null,
                'due_date' => null,
                'assigned_to' => null,
                'tasks' => $tasks,
            ];
        }

        $steps['hotel']['tasks']['localization']['completed'] = filled($tenant->timezone)
            && filled($tenant->currency);
        $steps['hotel']['tasks']['domain']['completed'] = $tenant->domains()->exists();
        $steps['users']['tasks']['owner']['completed'] = $tenant->users()
            ->where('users.is_super_admin', false)
            ->wherePivot('is_owner', true)->exists();

        return $this->normalizeSteps($steps);
    }

    public function updateTask(
        TenantOnboarding $onboarding,
        string $stepKey,
        string $taskKey,
        bool $completed,
        ?int $userId,
    ): TenantOnboarding {
        $this->assertTaskExists($stepKey, $taskKey);
        $steps = $onboarding->steps;
        $steps[$stepKey]['tasks'][$taskKey] = [
            'completed' => $completed,
            'completed_at' => $completed ? now()->toIso8601String() : null,
            'completed_by' => $completed ? $userId : null,
        ];

        return $this->saveSteps($onboarding, $steps);
    }

    public function updateStep(TenantOnboarding $onboarding, string $stepKey, array $data): TenantOnboarding
    {
        $this->assertStepExists($stepKey);
        $steps = $onboarding->steps;
        foreach (['status', 'notes', 'due_date', 'assigned_to'] as $field) {
            if (array_key_exists($field, $data)) {
                $steps[$stepKey][$field] = $data[$field];
            }
        }

        return $this->saveSteps($onboarding, $steps);
    }

    public function saveSteps(TenantOnboarding $onboarding, array $steps): TenantOnboarding
    {
        $steps = $this->normalizeSteps($steps);
        $progress = $this->progress($steps);
        $status = $progress === 100 ? 'ready' : ($progress > 0 ? 'in_progress' : 'not_started');

        $onboarding->forceFill([
            'steps' => $steps,
            'progress' => $progress,
            'status' => $onboarding->status === 'completed' && $progress === 100 ? 'completed' : $status,
            'completed_at' => $progress === 100 ? $onboarding->completed_at : null,
        ])->save();

        return $onboarding->refresh();
    }

    public function activate(TenantOnboarding $onboarding): TenantOnboarding
    {
        if ($this->progress($onboarding->steps) !== 100) {
            throw ValidationException::withMessages([
                'onboarding' => 'Përfundo të gjitha detyrat para aktivizimit të hotelit.',
            ]);
        }

        $onboarding->forceFill([
            'status' => 'completed',
            'progress' => 100,
            'completed_at' => now(),
            'activated_at' => now(),
        ])->save();

        return $onboarding->refresh();
    }

    public function progress(array $steps): int
    {
        $tasks = collect($steps)->flatMap(fn (array $step) => array_values(Arr::get($step, 'tasks', [])));
        if ($tasks->isEmpty()) {
            return 0;
        }

        return (int) round($tasks->filter(fn (array $task) => (bool) ($task['completed'] ?? false))->count() / $tasks->count() * 100);
    }

    public function present(TenantOnboarding $onboarding): array
    {
        $definitions = config('onboarding.steps');
        $steps = collect($definitions)->map(function (array $definition, string $stepKey) use ($onboarding) {
            $state = $onboarding->steps[$stepKey] ?? [];
            $tasks = collect($definition['tasks'])->map(function (array $task, string $taskKey) use ($state) {
                $taskState = $state['tasks'][$taskKey] ?? [];

                return [
                    'key' => $taskKey,
                    ...$task,
                    'completed' => (bool) ($taskState['completed'] ?? false),
                    'completed_at' => $taskState['completed_at'] ?? null,
                    'completed_by' => $taskState['completed_by'] ?? null,
                ];
            })->values();

            $completed = $tasks->where('completed', true)->count();

            return [
                'key' => $stepKey,
                'title' => $definition['title'],
                'description' => $definition['description'],
                'status' => $completed === $tasks->count()
                    ? 'done'
                    : (($state['status'] ?? null) === 'waiting_client' ? 'waiting_client' : ($completed > 0 ? 'in_progress' : 'pending')),
                'completed_tasks' => $completed,
                'total_tasks' => $tasks->count(),
                'notes' => $state['notes'] ?? null,
                'due_date' => $state['due_date'] ?? null,
                'assigned_to' => $state['assigned_to'] ?? null,
                'tasks' => $tasks,
            ];
        })->values();

        return [
            'id' => $onboarding->id,
            'status' => $onboarding->status,
            'progress' => $onboarding->progress,
            'due_date' => $onboarding->due_date?->toDateString(),
            'notes' => $onboarding->notes,
            'completed_at' => $onboarding->completed_at?->toIso8601String(),
            'activated_at' => $onboarding->activated_at?->toIso8601String(),
            'assignee' => $onboarding->assignee ? [
                'id' => $onboarding->assignee->id,
                'name' => $onboarding->assignee->name,
                'email' => $onboarding->assignee->email,
            ] : null,
            'steps' => $steps,
        ];
    }

    public function tenantDestination(?string $destination): string
    {
        if (! is_string($destination) || $destination === '') {
            return '/dashboard';
        }

        $logicalDestination = str_starts_with($destination, '/pms/')
            ? substr($destination, 4)
            : $destination;

        $allowed = collect(config('onboarding.steps'))
            ->flatMap(fn (array $step) => $step['tasks'] ?? [])
            ->pluck('action')
            ->filter(fn ($action) => is_array($action) && ($action['type'] ?? null) === 'tenant')
            ->pluck('path');

        return $allowed->containsStrict($logicalDestination)
            ? '/pms'.$logicalDestination
            : '/dashboard';
    }

    private function mergeDefinitions(array $steps, Tenant $tenant): array
    {
        $defaults = $this->defaultSteps($tenant);

        foreach ($defaults as $stepKey => $defaultStep) {
            if (! isset($steps[$stepKey]) || ! is_array($steps[$stepKey])) {
                $steps[$stepKey] = $defaultStep;
                continue;
            }

            foreach ($defaultStep['tasks'] as $taskKey => $defaultTask) {
                if (! isset($steps[$stepKey]['tasks'][$taskKey])) {
                    $steps[$stepKey]['tasks'][$taskKey] = $defaultTask;
                }
            }
        }

        return $this->normalizeSteps($steps);
    }

    private function normalizeSteps(array $steps): array
    {
        foreach ($steps as &$step) {
            $tasks = collect($step['tasks'] ?? []);
            if ($tasks->isNotEmpty() && $tasks->every(fn (array $task) => (bool) ($task['completed'] ?? false))) {
                $step['status'] = 'done';
            } elseif (($step['status'] ?? null) === 'done') {
                $step['status'] = 'in_progress';
            }
        }

        return $steps;
    }

    private function assertStepExists(string $stepKey): void
    {
        if (! array_key_exists($stepKey, config('onboarding.steps'))) {
            abort(404);
        }
    }

    private function assertTaskExists(string $stepKey, string $taskKey): void
    {
        $this->assertStepExists($stepKey);
        if (! array_key_exists($taskKey, config("onboarding.steps.{$stepKey}.tasks", []))) {
            abort(404);
        }
    }
}
