<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Audit 03 P0-3: alat bantu pengembangan (login cepat, katalog komponen) hanya ada di APP_ENV=local.
 * Rute login-as masuk sebagai akun demo tanpa password, jadi bila bocor ke produksi itu pintu belakang.
 */
class DevLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_dev_routes_are_not_registered_outside_the_local_environment(): void
    {
        $this->assertFalse(app()->isLocal(), 'Test ini mengandaikan lingkungan testing, bukan local.');
        $this->assertFalse(Route::has('dev.login-as'));
        $this->assertFalse(Route::has('dev.components'));
    }

    public function test_login_as_does_not_sign_anyone_in(): void
    {
        User::factory()->admin()->create(['email' => 'admin@irlab.test']);

        foreach (['admin', 'asisten', 'praktikan'] as $role) {
            $this->get("/dev/login-as/{$role}")->assertNotFound();
            $this->assertGuest();
        }

        $this->get('/dev/komponen')->assertNotFound();
    }
}
