<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Audit 03 P0-1/P0-2: matriks rute × peran. Rute yang ditambahkan nanti otomatis ikut diperiksa,
 * jadi rute baru tanpa middleware peran akan menggagalkan test ini.
 */
class RoutePermissionTest extends TestCase
{
    use RefreshDatabase;

    /** Peran yang diizinkan menurut awalan URL (routes/web.php: asisten juga untuk admin). */
    private const ALLOWED = [
        'admin' => ['admin'],
        'asisten' => ['asisten', 'admin'],
        'praktikan' => ['praktikan'],
    ];

    /**
     * Rute di luar awalan peran yang sah tanpa middleware `role:`. Rute baru di luar daftar ini
     * dan tanpa `role:` dianggap lupa dilindungi.
     */
    private const OPEN_ROUTES = [
        'home', 'password.request', 'password.email', 'password.code', 'password.code.verify',
        'password.reset', 'password.update', 'password.change', 'password.change.update',
        'files.show', 'session.keep-alive', 'dashboard', 'search', 'notifications.index',
        'notifications.read-all', 'notifications.read', 'live.version', 'avatars.show',
        'signatures.show', 'settings', 'profile.edit', 'profile.update', 'security.edit',
        'password.update', 'avatar.update', 'avatar.destroy', 'signature.update', 'signature.destroy',
        'login', 'login.store', 'logout', 'register', 'register.store', 'up',
    ];

    /**
     * @return list<RoutingRoute>
     */
    private static function roleRoutes(): array
    {
        return collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RoutingRoute $r) => preg_match('#^(admin|asisten|praktikan)/#', $r->uri()) === 1)
            ->values()->all();
    }

    private function url(string $uri): string
    {
        // Parameter diisi nilai yang pasti tidak ada; opsional ({type?}) dibuang.
        $uri = preg_replace('#/\{[^}]+\?\}#', '', $uri);

        return '/'.preg_replace('#\{[^}]+\}#', '999999', $uri);
    }

    private function hasParameter(RoutingRoute $route): bool
    {
        return $route->parameterNames() !== [];
    }

    private function prefixOf(string $uri): string
    {
        return explode('/', $uri)[0];
    }

    private function userWithRole(string $role): User
    {
        return match ($role) {
            'admin' => User::factory()->admin()->create(),
            'asisten' => User::factory()->asisten()->create(),
            default => User::factory()->create(),
        };
    }

    public function test_guests_are_sent_to_login(): void
    {
        $wrong = [];

        foreach (self::roleRoutes() as $route) {
            $response = $this->call($route->methods()[0], $this->url($route->uri()));

            // Rute berparameter: binding model berjalan sebelum auth dan memberi 404 untuk id yang tidak ada;
            // pagar auth-nya diperiksa lewat metadata di test_every_role_prefixed_route_carries....
            $okStatus = $this->hasParameter($route) && $response->getStatusCode() === 404;

            if (! $okStatus && ! $response->isRedirect(route('login'))) {
                $wrong[] = $route->methods()[0].' /'.$route->uri().' → '.$response->getStatusCode();
            }
        }

        $this->assertSame([], $wrong, "Tamu tidak dialihkan ke login:\n".implode("\n", $wrong));
    }

    public function test_roles_outside_the_allowed_list_get_403(): void
    {
        $users = collect(Role::cases())->mapWithKeys(fn (Role $r) => [$r->value => $this->userWithRole($r->value)]);
        $wrong = [];

        foreach (self::roleRoutes() as $route) {
            foreach ($users as $role => $user) {
                if (in_array($role, self::ALLOWED[$this->prefixOf($route->uri())], true)) {
                    continue;
                }

                $status = $this->actingAs($user)->call($route->methods()[0], $this->url($route->uri()))->getStatusCode();

                // Rute berparameter: binding model berjalan lebih dulu, jadi id palsu menghasilkan 404.
                // Peran salah dengan id yang ada tetap 403 karena middleware peran jalan sebelum controller.
                $expected = $this->hasParameter($route) ? [403, 404] : [403];

                if (! in_array($status, $expected, true)) {
                    $wrong[] = "{$role} ".$route->methods()[0].' /'.$route->uri()." → {$status}";
                }
            }
        }

        $this->assertSame([], $wrong, "Peran salah tidak ditolak dengan 403:\n".implode("\n", $wrong));
    }

    public function test_every_role_prefixed_route_carries_the_matching_role_middleware(): void
    {
        foreach (self::roleRoutes() as $route) {
            $expected = 'role:'.implode(',', self::ALLOWED[$this->prefixOf($route->uri())]);

            $middleware = $route->gatherMiddleware();

            $this->assertContains($expected, $middleware, "/{$route->uri()} kehilangan middleware {$expected}");
            $this->assertContains('auth', $middleware, "/{$route->uri()} kehilangan middleware auth");
        }
    }

    public function test_routes_outside_the_role_prefixes_are_either_on_the_known_open_list_or_role_protected(): void
    {
        $unprotected = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (preg_match('#^(admin|asisten|praktikan)/#', $route->uri()) === 1) {
                continue;
            }

            $name = (string) $route->getName();
            $hasRole = collect($route->gatherMiddleware())->contains(fn ($m) => is_string($m) && str_starts_with($m, 'role:'));

            // Hanya rute lokal (`dev.*`, tidak didaftarkan di luar APP_ENV=local) dan rute framework yang dikecualikan.
            if ($hasRole || in_array($name, self::OPEN_ROUTES, true) || str_starts_with($name, 'storage.') || $name === '' || str_starts_with($name, 'generated::') || str_starts_with($name, 'two-factor.') || str_starts_with($name, 'verification.') || str_starts_with($name, 'password.') || str_starts_with($name, 'user-')) {
                continue;
            }

            $unprotected[] = $route->methods()[0].' /'.$route->uri().' ('.$name.')';
        }

        $this->assertSame([], $unprotected, "Rute tanpa middleware peran dan di luar daftar terbuka:\n".implode("\n", $unprotected));
    }

    public function test_open_routes_require_a_session_or_are_intentionally_public(): void
    {
        // Rute "terbuka" yang membaca data pengguna wajib menolak tamu.
        foreach (['dashboard', 'search', 'notifications.index', 'profile.edit', 'password.change'] as $name) {
            $this->get(route($name))->assertRedirect(route('login'));
        }
    }
}
