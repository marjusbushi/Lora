<?php

namespace Tests\Feature;

use App\Models\Guest;
use App\Models\GuestDocument;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class GuestDirectoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-07-10 12:00:00');
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_directory_derives_operational_states_and_attention_without_financial_data(): void
    {
        $admin = $this->user('admin');
        $rooms = $this->rooms(4);

        $inHouse = $this->guest('In', 'House', 'in@example.test', '+355690001001', 'ALB');
        $arrivingToday = $this->guest('Arriving', 'Today', 'today@example.test', '+355690001002', 'ITA');
        $arrivingSoon = $this->guest('Arriving', 'Soon', 'soon@example.test', '+355690001003', 'DEU');
        $past = $this->guest('Past', 'Stay', 'past@example.test', '+355690009999', 'FRA');
        $duplicate = $this->guest('Possible', 'Duplicate', 'duplicate@example.test', '+355690009999', 'GBR');
        $incomplete = Guest::create(['first_name' => 'Needs', 'last_name' => 'Contact']);
        $noShow = $this->guest('No', 'Show', 'no-show@example.test', '+355690001004', 'ROU');

        $this->reservation($rooms[0], $inHouse, $admin, 'checked_in', '2026-07-09', '2026-07-12');
        $this->reservation($rooms[1], $arrivingToday, $admin, 'confirmed', '2026-07-10', '2026-07-12');
        $this->reservation($rooms[2], $arrivingSoon, $admin, 'confirmed', '2026-07-14', '2026-07-16');
        $this->reservation($rooms[3], $past, $admin, 'checked_out', '2026-06-20', '2026-06-23');
        $noShowReservation = $this->reservation($rooms[0], $noShow, $admin, 'confirmed', '2026-07-11', '2026-07-13');
        $noShowReservation->forceFill(['no_show_at' => '2026-07-10 10:00:00'])->saveQuietly();

        $props = $this->props($this->actingAs($admin)->get(route('guests.index', ['sort' => 'name']))->assertOk());
        $rows = collect($props['guests']['data'])->keyBy('id');

        $this->assertSame(7, $props['stats']['total']);
        $this->assertSame(1, $props['stats']['in_house']);
        $this->assertSame(2, $props['stats']['arriving_7_days']);
        $this->assertSame(1, $props['stats']['incomplete']);
        $this->assertSame(2, $props['stats']['duplicate_profiles']);
        $this->assertSame(3, $props['stats']['attention']);

        $this->assertSame('in_house', $rows[$inHouse->id]['state']);
        $this->assertSame('101', $rows[$inHouse->id]['current_stay']['room_number']);
        $this->assertSame('arriving_today', $rows[$arrivingToday->id]['state']);
        $this->assertSame('arriving_soon', $rows[$arrivingSoon->id]['state']);
        $this->assertSame('past', $rows[$past->id]['state']);
        $this->assertSame('new', $rows[$duplicate->id]['state']);
        $this->assertSame('new', $rows[$noShow->id]['state']);
        $this->assertTrue($rows[$past->id]['is_duplicate']);
        $this->assertTrue($rows[$duplicate->id]['is_duplicate']);
        $this->assertSame(0, $rows[$incomplete->id]['profile_completeness']);
        $this->assertSame(['email', 'phone', 'nationality'], $rows[$incomplete->id]['missing_fields']);
        $this->assertArrayNotHasKey('lifetime_spend', $rows[$past->id]);

        $duplicateProps = $this->props($this->actingAs($admin)->get(route('guests.index', [
            'segment' => 'duplicates',
            'sort' => 'name',
        ]))->assertOk());
        $this->assertSame('duplicates', $duplicateProps['filters']['segment']);
        $this->assertEqualsCanonicalizing(
            [$past->id, $duplicate->id],
            collect($duplicateProps['guests']['data'])->pluck('id')->all(),
        );
    }

    public function test_multi_room_booking_group_counts_as_one_completed_stay(): void
    {
        $admin = $this->user('admin');
        $rooms = $this->rooms(3);
        $guest = $this->guest('Repeat', 'Guest', 'repeat@example.test', '+355690002001', 'ALB');

        $this->reservation($rooms[0], $guest, $admin, 'checked_out', '2026-05-01', '2026-05-03', 'group-one');
        $this->reservation($rooms[1], $guest, $admin, 'checked_out', '2026-05-01', '2026-05-03', 'group-one');
        $this->reservation($rooms[2], $guest, $admin, 'checked_out', '2026-06-01', '2026-06-04');
        $this->reservation($rooms[0], $guest, $admin, 'confirmed', '2026-07-12', '2026-07-14');

        $props = $this->props($this->actingAs($admin)->get(route('guests.index', [
            'segment' => 'returning',
            'sort' => 'stays',
        ]))->assertOk());

        $this->assertSame(1, $props['stats']['returning']);
        $this->assertSame(1, $props['stats']['arriving_returning']);
        $this->assertCount(1, $props['guests']['data']);
        $this->assertSame($guest->id, $props['guests']['data'][0]['id']);
        $this->assertSame(2, $props['guests']['data'][0]['completed_stays']);
        $this->assertSame(5, $props['guests']['data'][0]['total_nights']);
    }

    public function test_search_matches_a_full_name_and_preserves_other_filters(): void
    {
        $admin = $this->user('admin');
        $match = $this->guest('Luca', 'Bianchi', 'luca@example.test', '+390001001', 'IT');
        $this->guest('Luca', 'Other', 'other@example.test', '+390001002', 'IT');
        $this->guest('Luca', 'Bianchi', 'albanian@example.test', '+3550001003', 'ALB');
        $legacy = $this->guest('Mario', 'Rossi', 'mario@example.test', '+390001004', 'ITA');

        $props = $this->props($this->actingAs($admin)->get(route('guests.index', [
            'search' => 'Luca Bianchi',
            'nationality' => 'IT',
            'segment' => 'all',
            'sort' => 'name',
        ]))->assertOk());

        $this->assertCount(1, $props['guests']['data']);
        $this->assertSame($match->id, $props['guests']['data'][0]['id']);
        $this->assertSame('Luca Bianchi', $props['filters']['search']);
        $this->assertSame('IT', $props['filters']['nationality']);
        $this->assertStringContainsString('search=Luca%20Bianchi', $props['guests']['first_page_url']);
        $this->assertStringContainsString('nationality=IT', $props['guests']['first_page_url']);

        $legacyProps = $this->props($this->actingAs($admin)->get(route('guests.index', [
            'search' => 'Mario Rossi',
            'nationality' => 'IT',
        ]))->assertOk());

        $this->assertCount(1, $legacyProps['guests']['data']);
        $this->assertSame($legacy->id, $legacyProps['guests']['data'][0]['id']);
        $this->assertSame('Itali', $legacyProps['guests']['data'][0]['nationality_label']);
    }

    public function test_view_only_role_does_not_receive_sensitive_edit_payload(): void
    {
        $role = Role::create(['name' => 'guest_viewer', 'guard_name' => 'web']);
        $role->givePermissionTo('view_guests');
        $viewer = User::factory()->create();
        $viewer->assignRole($role);

        $guest = Guest::create([
            'first_name' => 'Private',
            'last_name' => 'Profile',
            'email' => 'private@example.test',
            'phone' => '+355690003001',
            'nationality' => 'ALB',
            'document_type' => 'passport',
            'document_number' => 'SECRET-DOCUMENT',
            'date_of_birth' => '1990-01-01',
            'notes' => 'SECRET-NOTES',
        ]);

        $props = $this->props($this->actingAs($viewer)->get(route('guests.index'))->assertOk());
        $row = collect($props['guests']['data'])->firstWhere('id', $guest->id);
        $serialized = json_encode($props['guests']['data'], JSON_THROW_ON_ERROR);

        $this->assertFalse($props['permissions']['create']);
        $this->assertFalse($props['permissions']['update']);
        $this->assertFalse($props['permissions']['delete']);
        $this->assertNull($row['edit_data']);
        $this->assertStringNotContainsString('SECRET-DOCUMENT', $serialized);
        $this->assertStringNotContainsString('SECRET-NOTES', $serialized);
        $this->assertStringNotContainsString('1990-01-01', $serialized);
    }

    public function test_admin_receives_complete_edit_payload_so_saving_does_not_blank_hidden_fields(): void
    {
        $admin = $this->user('admin');
        $guest = Guest::create([
            'first_name' => 'Edit',
            'last_name' => 'Safe',
            'email' => 'edit-safe@example.test',
            'phone' => '+355690004001',
            'nationality' => 'ITA',
            'document_type' => 'passport',
            'document_number' => 'EDIT-SAFE-1',
            'date_of_birth' => '1991-02-03',
            'notes' => 'Keep this note',
        ]);

        $props = $this->props($this->actingAs($admin)->get(route('guests.index'))->assertOk());
        $editData = collect($props['guests']['data'])->firstWhere('id', $guest->id)['edit_data'];

        $this->assertSame('EDIT-SAFE-1', $editData['document_number']);
        $this->assertSame('1991-02-03', $editData['date_of_birth']);
        $this->assertSame('Keep this note', $editData['notes']);
        $this->assertSame('IT', $editData['nationality']);

        $editData['first_name'] = 'Edited';
        $this->actingAs($admin)
            ->put(route('guests.update', $guest), $editData)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $guest->refresh();
        $this->assertSame('Edited', $guest->first_name);
        $this->assertSame('EDIT-SAFE-1', $guest->document_number);
        $this->assertSame('1991-02-03', $guest->date_of_birth?->toDateString());
        $this->assertSame('Keep this note', $guest->notes);
        $this->assertSame('IT', $guest->nationality);
    }

    public function test_guest_with_reservation_history_cannot_be_deleted(): void
    {
        $admin = $this->user('admin');
        $room = $this->rooms(1)[0];
        $guest = $this->guest('Protected', 'Guest', 'protected@example.test', '+355690005001', 'ALB');
        $reservation = $this->reservation($room, $guest, $admin, 'checked_out', '2026-06-01', '2026-06-03');
        $reservation->delete();

        $props = $this->props($this->actingAs($admin)->get(route('guests.index'))->assertOk());
        $row = collect($props['guests']['data'])->firstWhere('id', $guest->id);
        $this->assertTrue($row['has_reservations']);

        $this->actingAs($admin)
            ->delete(route('guests.destroy', $guest))
            ->assertRedirect()
            ->assertSessionHasErrors('guest');

        $this->assertNotSoftDeleted('guests', ['id' => $guest->id]);

        $documentGuest = $this->guest('Document', 'Protected', 'document@example.test', '+355690005003', 'ALB');
        GuestDocument::create([
            'guest_id' => $documentGuest->id,
            'type' => 'passport',
            'original_name' => 'passport.pdf',
            'path' => 'guest-documents/test/passport.pdf',
            'mime' => 'application/pdf',
            'size' => 100,
            'uploaded_by' => $admin->id,
        ]);

        $documentProps = $this->props($this->actingAs($admin)->get(route('guests.index', [
            'search' => 'Document Protected',
        ]))->assertOk());
        $documentRow = $documentProps['guests']['data'][0];
        $this->assertTrue($documentRow['has_documents']);
        $this->assertFalse($documentRow['can_delete']);

        $this->actingAs($admin)
            ->delete(route('guests.destroy', $documentGuest))
            ->assertRedirect()
            ->assertSessionHasErrors('guest');
        $this->assertNotSoftDeleted('guests', ['id' => $documentGuest->id]);

        $disposable = $this->guest('Disposable', 'Guest', 'disposable@example.test', '+355690005002', 'ALB');
        $this->actingAs($admin)
            ->delete(route('guests.destroy', $disposable))
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->assertSoftDeleted('guests', ['id' => $disposable->id]);
    }

    public function test_directory_query_count_stays_bounded_with_many_guests(): void
    {
        $admin = $this->user('admin');
        $room = $this->rooms(1)[0];

        foreach (range(1, 30) as $index) {
            $guest = $this->guest(
                'Guest'.$index,
                'Scale',
                "scale{$index}@example.test",
                '+35569'.str_pad((string) $index, 7, '0', STR_PAD_LEFT),
                'ALB',
            );
            $this->reservation($room, $guest, $admin, 'checked_out', '2026-06-01', '2026-06-03');
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($admin)->get(route('guests.index'))->assertOk();

        // 36 = previous 35 + the single pricing.currency read the shared
        // settings payload performs on a settings-cache miss.
        $this->assertLessThanOrEqual(36, count(DB::getQueryLog()));
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function guest(string $first, string $last, string $email, string $phone, string $nationality): Guest
    {
        return Guest::create([
            'first_name' => $first,
            'last_name' => $last,
            'email' => $email,
            'phone' => $phone,
            'nationality' => $nationality,
        ]);
    }

    /** @return list<Room> */
    private function rooms(int $count): array
    {
        $type = RoomType::create([
            'name' => 'Deluxe',
            'base_price' => 100,
            'max_occupancy' => 3,
            'amenities' => [],
        ]);

        return collect(range(1, $count))->map(fn (int $index) => Room::create([
            'room_type_id' => $type->id,
            'room_number' => (string) (100 + $index),
            'floor' => 1,
            'status' => 'available',
        ]))->all();
    }

    private function reservation(
        Room $room,
        Guest $guest,
        User $creator,
        string $status,
        string $checkIn,
        string $checkOut,
        ?string $bookingGroupId = null,
    ): Reservation {
        return Reservation::create([
            'room_id' => $room->id,
            'guest_id' => $guest->id,
            'created_by' => $creator->id,
            'check_in_date' => $checkIn,
            'check_out_date' => $checkOut,
            'status' => $status,
            'total_amount' => 100,
            'adults' => 2,
            'children' => 0,
            'channel' => 'direct',
            'booking_group_id' => $bookingGroupId,
        ]);
    }

    /** @return array<string, mixed> */
    private function props(TestResponse $response): array
    {
        $page = json_decode(
            json_encode($response->viewData('page'), JSON_THROW_ON_ERROR),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        return $page['props'];
    }
}
