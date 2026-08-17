<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_password_link_screen_can_be_rendered(): void
    {
        $response = $this->get('/forgot-password');

        $response->assertStatus(200);
    }

    public function test_reset_password_link_can_be_requested(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    /**
     * Numërim llogarish: më parë një email i panjohur kthente gabim validimi me
     * "nuk gjejmë përdorues me këtë email", ndaj kushdo mund të mësonte se cilat
     * adresa kanë llogari këtu. Të dyja rrugët duhet të duken NJËSOJ.
     */
    public function test_an_unknown_email_looks_exactly_like_a_known_one(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $known = $this->post('/forgot-password', ['email' => $user->email]);
        $unknown = $this->post('/forgot-password', ['email' => 'nuk-ekziston-fare@example.com']);

        // I njëjti rezultat, i njëjti mesazh, asnjë gabim që tregon mungesën.
        $known->assertSessionHasNoErrors()->assertSessionHas('status', 'password-reset-link-sent');
        $unknown->assertSessionHasNoErrors()->assertSessionHas('status', 'password-reset-link-sent');

        // Dhe, natyrisht, njoftimi shkon VETËM te llogaria që ekziston.
        Notification::assertSentTo($user, ResetPassword::class);
        Notification::assertCount(1);
    }

    /**
     * Gjetje P1 e Codex: brokeri kthen INVALID_USER PARA se të kontrollojë
     * throttle-in, ndaj dy dërgime brenda 60 sekondave jepnin gabim VETËM për
     * adresat ekzistuese — orakulli i njëjtë, një hap më thellë.
     */
    public function test_a_throttled_result_is_neutral_too(): void
    {
        // Brokeri detyrohet të kthejë RESET_THROTTLED: kjo është e vetmja mënyrë
        // deterministe për ta prekur atë degë përmes HTTP-së. Një provë me dy
        // kërkesa radhazi kalonte edhe PA rregullimin, pra nuk provonte asgjë.
        Password::shouldReceive('sendResetLink')
            ->once()
            ->andReturn(Password::RESET_THROTTLED);

        $this->post('/forgot-password', ['email' => 'kushdo@example.com'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'password-reset-link-sent');
    }

    public function test_the_reset_request_route_is_rate_limited_regardless_of_the_address(): void
    {
        // Kufizim mbi RRUGË: godet njësoj adresat ekzistuese dhe joekzistuese,
        // ndaj s'ka rrugë për të provuar adresa me shumicë.
        for ($i = 0; $i < 6; $i++) {
            $this->post('/forgot-password', ['email' => "provë{$i}@example.com"]);
        }

        $this->post('/forgot-password', ['email' => 'edhe-nje@example.com'])
            ->assertStatus(429);
    }

    public function test_reset_password_screen_can_be_rendered(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) {
            $response = $this->get('/reset-password/'.$notification->token);

            $response->assertStatus(200);

            return true;
        });
    }

    public function test_password_can_be_reset_with_valid_token(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $response = $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'password',
                'password_confirmation' => 'password',
            ]);

            $response
                ->assertSessionHasNoErrors()
                ->assertRedirect(route('login'));

            return true;
        });
    }
}
