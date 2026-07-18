<?php

namespace Tests\Feature;

use App\Mail\ScheduledReportMail;
use App\Models\SavedReport;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SavedReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_save_list_schedule_and_delete_a_report(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->postJson(route('reports.saved.store'), [
            'name' => 'Revenue javor',
            'route_name' => 'reports.executive',
            'filters' => ['from' => '2026-07-01', 'to' => '2026-07-07'],
            'frequency' => 'weekly',
            'delivery_email' => 'owner@example.test',
        ])->assertCreated();

        $id = $response->json('saved_report.id');
        $this->assertNotNull(SavedReport::find($id)?->next_delivery_at);
        $this->actingAs($admin)->getJson(route('reports.saved.index'))
            ->assertOk()
            ->assertJsonPath('saved_reports.0.name', 'Revenue javor');
        $this->actingAs($admin)->deleteJson(route('reports.saved.destroy', $id))->assertNoContent();
        $this->assertDatabaseMissing('saved_reports', ['id' => $id]);
    }

    public function test_scheduler_delivers_only_due_whitelisted_reports(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        $report = SavedReport::create([
            'user_id' => $user->id,
            'name' => 'Pasqyra ditore',
            'route_name' => 'reports.executive',
            'filters' => ['from' => '2026-07-18', 'to' => '2026-07-18'],
            'frequency' => 'daily',
            'delivery_email' => 'owner@example.test',
            'next_delivery_at' => now()->subMinute(),
        ]);

        $this->artisan('reports:deliver-scheduled')->assertSuccessful();

        Mail::assertSent(ScheduledReportMail::class, fn (ScheduledReportMail $mail) => $mail->hasTo('owner@example.test'));
        $report->refresh();
        $this->assertNotNull($report->last_delivered_at);
        $this->assertTrue($report->next_delivery_at->isFuture());
    }
}
