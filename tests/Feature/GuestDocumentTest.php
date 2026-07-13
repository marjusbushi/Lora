<?php

namespace Tests\Feature;

use App\Models\Guest;
use App\Models\GuestDocument;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GuestDocumentTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $u = User::factory()->create();
        $u->assignRole('admin');

        return $u;
    }

    public function test_admin_uploads_document_to_private_disk(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $admin = $this->admin();
        $guest = Guest::create(['first_name' => 'Ana', 'last_name' => 'B', 'email' => 'a@b.local', 'phone' => '1']);

        $this->actingAs($admin)->post(route('guests.documents.store', $guest->id), [
            'type' => 'passport',
            'file' => UploadedFile::fake()->create('passport.pdf', 300, 'application/pdf'),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $doc = GuestDocument::first();
        $this->assertNotNull($doc);
        $this->assertSame('passport', $doc->type);
        $this->assertSame('passport.pdf', $doc->original_name);
        $this->assertSame($admin->id, $doc->uploaded_by);
        Storage::disk('local')->assertExists($doc->path);          // PRIVATE disk
        $this->assertStringStartsWith('tenants/', $doc->path);      // per-tenant folder
        $this->assertSame(0, count(Storage::disk('public')->allFiles())); // never public
    }

    public function test_view_route_streams_for_permitted_and_blocks_others(): void
    {
        Storage::fake('local');
        $admin = $this->admin();
        $guest = Guest::create(['first_name' => 'A', 'last_name' => 'B', 'email' => 'a@b.local', 'phone' => '1']);
        $this->actingAs($admin)->post(route('guests.documents.store', $guest->id), [
            'type' => 'id_card', 'file' => UploadedFile::fake()->create('id.pdf', 100, 'application/pdf'),
        ]);
        $doc = GuestDocument::first();

        $this->actingAs($admin)->get(route('guests.documents.show', $doc->id))->assertOk();

        $noPerm = User::factory()->create(); // no roles/permissions
        $this->actingAs($noPerm)->get(route('guests.documents.show', $doc->id))->assertForbidden();

        $guest->delete();
        $this->actingAs($admin)->get(route('guests.documents.show', $doc->id))->assertNotFound();
    }

    public function test_delete_removes_file_and_record(): void
    {
        Storage::fake('local');
        $admin = $this->admin();
        $guest = Guest::create(['first_name' => 'A', 'last_name' => 'B', 'email' => 'a@b.local', 'phone' => '1']);
        $this->actingAs($admin)->post(route('guests.documents.store', $guest->id), [
            'type' => 'visa', 'file' => UploadedFile::fake()->create('v.pdf', 100, 'application/pdf'),
        ]);
        $doc = GuestDocument::first();
        $path = $doc->path;

        $this->actingAs($admin)->delete(route('guests.documents.destroy', $doc->id))->assertRedirect();
        $this->assertNull(GuestDocument::find($doc->id));
        Storage::disk('local')->assertMissing($path);
    }
}
