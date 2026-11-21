<?php

namespace Tests\Feature;

use App\Support\TrustedProxies;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** Audit 01 S6: IP klien di belakang reverse proxy hanya dipercaya bila TRUSTED_PROXIES diatur. */
class TrustedProxiesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::get('__client-ip', fn (Request $request) => response()->json(['ip' => $request->ip(), 'secure' => $request->isSecure()]));
    }

    protected function tearDown(): void
    {
        TrustProxies::flushState();

        parent::tearDown();
    }

    /** @return array<string, string> */
    private function proxied(): array
    {
        return ['X-Forwarded-For' => '203.0.113.7', 'X-Forwarded-Proto' => 'https'];
    }

    public function test_forwarded_headers_are_ignored_by_default(): void
    {
        $this->withHeaders($this->proxied())->getJson('/__client-ip')
            ->assertJson(['ip' => '127.0.0.1', 'secure' => false]);
    }

    public function test_forwarded_headers_are_trusted_when_the_proxy_is_configured(): void
    {
        TrustedProxies::configure('127.0.0.1, 10.0.0.5');

        $this->withHeaders($this->proxied())->getJson('/__client-ip')
            ->assertJson(['ip' => '203.0.113.7', 'secure' => true]);
    }

    public function test_a_wildcard_trusts_every_proxy_and_an_empty_value_changes_nothing(): void
    {
        TrustedProxies::configure('');
        $this->withHeaders($this->proxied())->getJson('/__client-ip')->assertJson(['ip' => '127.0.0.1']);

        TrustedProxies::configure('*');
        $this->withHeaders($this->proxied())->getJson('/__client-ip')->assertJson(['ip' => '203.0.113.7']);
    }
}
