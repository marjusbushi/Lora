<?php

namespace Tests\Feature;

use App\Models\RoomType;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Room-type photo upload. The client optimizes photos before upload (HEIC→JPG + downscale),
 * so the server receives small JPEGs; these tests cover the server-side guard: it accepts a
 * normal photo, accepts photos far above the OLD 3MB cap (up to 15MB), and rejects anything
 * bigger with a clear Albanian message instead of failing silently.
 */
class RoomTypeImageUploadTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function tripleRoom(): RoomType
    {
        return RoomType::create(['name' => 'Triple', 'base_price' => 90, 'max_occupancy' => 3, 'amenities' => []]);
    }

    public function test_admin_can_upload_a_room_type_photo(): void
    {
        Storage::fake('public');
        $type = $this->tripleRoom();

        $this->actingAs($this->admin())
            ->post(route('settings.room-types.images.upload', $type->id), [
                'images' => [UploadedFile::fake()->image('room.jpg', 1600, 1200)],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertSame(1, $type->images()->count());
    }

    public function test_a_10mb_photo_is_accepted_where_the_old_3mb_cap_rejected_it(): void
    {
        Storage::fake('public');
        $type = $this->tripleRoom();

        // 10MB — well over the old 3072KB cap, under the new 15360KB one.
        $img = UploadedFile::fake()->image('big.jpg')->size(10240);

        $this->actingAs($this->admin())
            ->post(route('settings.room-types.images.upload', $type->id), ['images' => [$img]])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $type->images()->count());
    }

    public function test_an_oversized_photo_is_rejected_with_an_albanian_message(): void
    {
        Storage::fake('public');
        $type = $this->tripleRoom();

        // 16MB > the 15360KB cap.
        $tooBig = UploadedFile::fake()->image('huge.jpg')->size(16000);

        $this->actingAs($this->admin())
            ->post(route('settings.room-types.images.upload', $type->id), ['images' => [$tooBig]])
            ->assertSessionHasErrors(['images.0' => 'Fotoja është shumë e madhe — maksimumi 15MB.']);

        $this->assertSame(0, $type->images()->count());
    }

    public function test_a_non_image_file_is_rejected(): void
    {
        Storage::fake('public');
        $type = $this->tripleRoom();

        $pdf = UploadedFile::fake()->create('brochure.pdf', 200, 'application/pdf');

        $this->actingAs($this->admin())
            ->post(route('settings.room-types.images.upload', $type->id), ['images' => [$pdf]])
            ->assertSessionHasErrors('images.0');

        $this->assertSame(0, $type->images()->count());
    }
}
