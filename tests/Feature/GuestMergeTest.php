<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Guest;
use App\Models\GuestDocument;
use App\Models\GuestMerge;
use App\Models\Invoice;
use App\Models\Reservation;
use App\Models\Review;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class GuestMergeTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    /** @return array{Guest,Guest} */
    private function duplicates(): array
    {
        $primary = Guest::create([
            'first_name' => 'Ana',
            'last_name' => 'Kola',
            'email' => 'same@example.test',
            'nationality' => 'ALB',
            'tags' => ['vip'],
            'preferences' => ['floor' => 'high'],
            'marketing_consent' => false,
        ]);
        $secondary = Guest::create([
            'first_name' => 'Anna',
            'last_name' => 'Kola',
            'email' => 'same@example.test',
            'phone' => '+355690001111',
            'date_of_birth' => '1990-05-10',
            'document_type' => 'passport',
            'document_number' => 'DOC-MERGE-2',
            'tags' => ['late-arrival'],
            'preferences' => ['pillow' => 'soft'],
            'marketing_consent' => true,
        ]);

        return [$primary, $secondary];
    }

    private function fieldSources(Guest $primary, Guest $secondary): array
    {
        return [
            'first_name' => $primary->id,
            'last_name' => $primary->id,
            'email' => $primary->id,
            'phone' => $secondary->id,
            'nationality' => $primary->id,
            'date_of_birth' => $secondary->id,
            'document_type' => $secondary->id,
            'document_number' => $secondary->id,
            'notes' => $primary->id,
        ];
    }

    public function test_merge_page_shows_a_safe_preview_for_detected_duplicates(): void
    {
        $admin = $this->admin();
        [$primary, $secondary] = $this->duplicates();

        $this->actingAs($admin)
            ->get(route('guests.merge.show', [$primary, $secondary]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Guests/Merge')
                ->has('profiles', 2)
                ->where('suggestion.primary_id', $secondary->id)
                ->where('suggestion.source', 'fallback'));

        $this->assertSame(2, Guest::count());
        $this->assertSame(0, GuestMerge::count());
    }

    public function test_ai_suggestion_receives_only_anonymized_metrics(): void
    {
        $admin = $this->admin();
        [$primary, $secondary] = $this->duplicates();
        config()->set('services.gemini.key', 'merge-secret-key');
        config()->set('services.gemini.base_url', 'https://generativelanguage.googleapis.com/v1beta');
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [[
                        'functionCall' => [
                            'name' => 'suggest_guest_merge',
                            'args' => ['primary' => 'B', 'reason_key' => 'more_complete'],
                        ],
                    ]]],
                ]],
            ]),
        ]);

        $this->actingAs($admin)
            ->postJson(route('guests.merge.suggest', [$primary, $secondary]))
            ->assertOk()
            ->assertJsonPath('suggestion.primary_id', $secondary->id)
            ->assertJsonPath('suggestion.source', 'ai');

        Http::assertSent(function ($request) {
            $body = $request->body();

            return $request->hasHeader('x-goog-api-key', 'merge-secret-key')
                && str_contains($body, 'completeness')
                && ! str_contains($body, 'same@example.test')
                && ! str_contains($body, '+355690001111')
                && ! str_contains($body, 'DOC-MERGE-2');
        });
    }

    public function test_confirmed_merge_moves_all_history_and_archives_secondary(): void
    {
        $admin = $this->admin();
        [$primary, $secondary] = $this->duplicates();
        $roomType = RoomType::create(['name' => 'Double', 'base_price' => 100, 'max_occupancy' => 2, 'amenities' => []]);
        $room = Room::create(['room_number' => 'M1', 'room_type_id' => $roomType->id, 'floor' => 1, 'status' => 'available']);
        $reservation = Reservation::create([
            'room_id' => $room->id,
            'guest_id' => $secondary->id,
            'created_by' => $admin->id,
            'check_in_date' => now()->addDay()->toDateString(),
            'check_out_date' => now()->addDays(2)->toDateString(),
            'status' => 'confirmed',
            'total_amount' => 100,
            'adults' => 2,
            'channel' => 'direct',
        ]);
        $document = GuestDocument::create([
            'guest_id' => $secondary->id,
            'type' => 'passport',
            'original_name' => 'passport.pdf',
            'path' => 'guest-documents/merge/passport.pdf',
            'mime' => 'application/pdf',
            'size' => 100,
            'uploaded_by' => $admin->id,
        ]);
        $invoice = Invoice::create([
            'number' => '2026-009999',
            'guest_id' => $secondary->id,
            'issue_date' => now()->toDateString(),
            'currency' => 'EUR',
            'total' => 100,
            'status' => 'open',
        ]);
        $review = Review::create(['guest_id' => $secondary->id, 'rating' => 5, 'comment' => 'Great']);

        $this->actingAs($admin)
            ->post(route('guests.merge.store', [$primary, $secondary]), [
                'primary_id' => $primary->id,
                'field_sources' => $this->fieldSources($primary, $secondary),
                'suggestion_source' => 'ai',
            ])
            ->assertRedirect(route('guests.show', $primary));

        $primary->refresh();
        $archived = Guest::withTrashed()->findOrFail($secondary->id);
        $this->assertSame('Ana', $primary->first_name);
        $this->assertSame('+355690001111', $primary->phone);
        $this->assertSame('DOC-MERGE-2', $primary->document_number);
        $this->assertEqualsCanonicalizing(['vip', 'late-arrival'], $primary->tags);
        $this->assertSame(['pillow' => 'soft', 'floor' => 'high'], $primary->preferences);
        $this->assertFalse($primary->marketing_consent, 'Consent must never be promoted implicitly during merge.');

        $this->assertSame($primary->id, $reservation->fresh()->guest_id);
        $this->assertSame($primary->id, $document->fresh()->guest_id);
        $this->assertSame($primary->id, $invoice->fresh()->guest_id);
        $this->assertSame($primary->id, $review->fresh()->guest_id);
        $this->assertNotNull($archived->deleted_at);
        $this->assertSame($primary->id, $archived->merged_into_guest_id);
        $this->assertSame($admin->id, $archived->merged_by);
        $this->assertNull($archived->document_number, 'A moved unique document number must be cleared from the archived row.');

        $merge = GuestMerge::firstOrFail();
        $this->assertSame($primary->id, $merge->primary_guest_id);
        $this->assertSame($secondary->id, $merge->secondary_guest_id);
        $this->assertSame(1, $merge->moved_counts['reservations']);
        $this->assertSame('DOC-MERGE-2', $merge->secondary_snapshot['document_number']);
        $this->assertTrue(AuditLog::where('action', 'guest.merged')->where('subject_id', $primary->id)->exists());
    }

    public function test_non_duplicates_cannot_be_merged(): void
    {
        $admin = $this->admin();
        $first = Guest::create(['first_name' => 'A', 'last_name' => 'One', 'email' => 'one@example.test']);
        $second = Guest::create(['first_name' => 'B', 'last_name' => 'Two', 'email' => 'two@example.test']);

        $this->actingAs($admin)
            ->get(route('guests.merge.show', [$first, $second]))
            ->assertNotFound();
        $this->actingAs($admin)
            ->post(route('guests.merge.store', [$first, $second]), [
                'primary_id' => $first->id,
                'field_sources' => $this->fieldSources($first, $second),
                'suggestion_source' => 'manual',
            ])
            ->assertNotFound();

        $this->assertSame(2, Guest::count());
    }

    public function test_staff_without_delete_permission_cannot_merge_profiles(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $staff = User::factory()->create();
        $staff->givePermissionTo(['view_guests', 'update_guests']);
        [$first, $second] = $this->duplicates();

        $this->actingAs($staff)
            ->get(route('guests.merge.show', [$first, $second]))
            ->assertForbidden();

        $this->assertSame(2, Guest::count());
    }
}
