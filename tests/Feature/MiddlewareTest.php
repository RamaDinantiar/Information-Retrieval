<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Audit 03 P2-5: EnsureUserIsActive, EnsurePasswordIsChanged, AddSecurityHeaders pada respons galat.
 */
class MiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_account_deactivated_mid_session_is_signed_out_on_the_next_request(): void
    {
        $user = User::factory()->create();
        $this->post(route('login.store'), ['nim' => $user->nim, 'login' => $user->nim, 'email' => $user->email, 'password' => 'password']);
        $this->assertAuthenticatedAs($user);
        $this->get(route('praktikan.dashboard'))->assertOk();

        // Admin menonaktifkan akun lewat instance lain (bukan objek yang tersimpan di guard).
        User::whereKey($user->id)->update(['is_active' => false]);
        // Satu proses test memakai ulang guard (dan objek pengguna yang tersimpan); di produksi tiap request baru memuatnya dari DB.
        $this->app['auth']->forgetGuards();

        $this->get(route('praktikan.dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_a_deactivated_account_is_also_blocked_on_post_routes(): void
    {
        $user = User::factory()->asisten()->create(['is_active' => false]);

        $this->actingAs($user)->post(route('session.keep-alive'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_a_user_who_must_change_the_password_is_redirected_from_every_other_page(): void
    {
        $user = User::factory()->create(['must_change_password' => true]);

        foreach (['dashboard', 'search', 'notifications.index', 'profile.edit', 'security.edit', 'praktikan.class.index'] as $name) {
            $this->actingAs($user)->get(route($name))->assertRedirect(route('password.change'));
        }
    }

    public function test_the_forced_password_change_still_allows_its_own_page_keep_alive_and_logout(): void
    {
        $user = User::factory()->create(['must_change_password' => true]);

        $this->actingAs($user)->get(route('password.change'))->assertOk();
        $this->actingAs($user)->post(route('session.keep-alive'))->assertNoContent();
        $this->actingAs($user)->post(route('logout'))->assertRedirect();
        $this->assertGuest();
    }

    public function test_the_forced_password_change_applies_to_every_role(): void
    {
        foreach ([User::factory()->asisten(), User::factory()->admin()] as $factory) {
            $this->actingAs($factory->create(['must_change_password' => true]))->get(route('dashboard'))->assertRedirect(route('password.change'));
        }
    }

    public function test_security_headers_are_present_on_a_403_from_the_role_middleware(): void
    {
        // Audit 03 N13: redireksi tamu (middleware auth) dan 404 untuk URL yang tidak cocok rute apa pun
        // tidak melewati AddSecurityHeaders, jadi tidak dikunci di sini.
        $response = $this->actingAs(User::factory()->create())->get(route('admin.users.index'))->assertForbidden();

        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $this->assertStringContainsString("frame-ancestors 'self'", (string) $response->headers->get('Content-Security-Policy'));
    }

    public function test_security_headers_are_present_on_a_validation_failure(): void
    {
        $this->post(route('login.store'), [])->assertSessionHasErrors()->assertHeader('X-Content-Type-Options', 'nosniff');
    }
}
