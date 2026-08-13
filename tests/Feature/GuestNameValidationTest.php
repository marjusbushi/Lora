<?php

namespace Tests\Feature;

use App\Models\Guest;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuestNameValidationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    public function test_punctuation_only_names_are_rejected(): void
    {
        $admin = $this->admin();

        // The room-102 case: a guest literally named "!".
        $this->actingAs($admin)
            ->post(route('guests.store'), ['first_name' => '!', 'last_name' => 'Wojciechowska'])
            ->assertSessionHasErrors(['first_name']);

        $this->actingAs($admin)
            ->post(route('guests.store'), ['first_name' => 'Ana', 'last_name' => '!!'])
            ->assertSessionHasErrors(['last_name']);

        // Single letter is not a first name either (min 2).
        $this->actingAs($admin)
            ->post(route('guests.store'), ['first_name' => 'Ë', 'last_name' => 'Hoxha'])
            ->assertSessionHasErrors(['first_name']);

        $this->assertSame(0, Guest::count());
    }

    public function test_real_names_pass_in_any_script(): void
    {
        $admin = $this->admin();

        // Albanian diacritics, Hebrew (a real Saturn guest), hyphenated.
        foreach ([
            ['Çelë', 'Hoxha'],
            ['יחיאל', 'שטרית'],
            ['Anne-Marie', "O'Neill"],
        ] as [$first, $last]) {
            $this->actingAs($admin)
                ->post(route('guests.store'), ['first_name' => $first, 'last_name' => $last])
                ->assertSessionHasNoErrors();
        }
        $this->assertSame(3, Guest::count());

        // Single-letter SURNAMES are legitimate (min 1 letter on last_name).
        $this->actingAs($admin)
            ->post(route('guests.store'), ['first_name' => 'Young', 'last_name' => 'O'])
            ->assertSessionHasNoErrors();
    }

    public function test_update_path_is_guarded_too(): void
    {
        $admin = $this->admin();
        $guest = Guest::create(['first_name' => 'Ana', 'last_name' => 'B']);

        $this->actingAs($admin)
            ->put(route('guests.update', $guest), ['first_name' => '!', 'last_name' => 'B'])
            ->assertSessionHasErrors(['first_name']);

        $this->assertSame('Ana', $guest->fresh()->first_name);
    }
}
