<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Support\LiveVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LiveUpdatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_token_perubahan_bisa_dibaca_tanpa_login_dan_tanpa_cookie_sesi(): void
    {
        $response = $this->getJson('/live/version');

        $response->assertOk()->assertJsonStructure(['v']);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertEmpty($response->headers->getCookies());
    }

    public function test_token_berubah_saat_data_disimpan_atau_dihapus(): void
    {
        $before = LiveVersion::current();
        $this->assertSame($before, $this->getJson('/live/version')->json('v'));

        $announcement = Announcement::factory()->create();
        $afterCreate = LiveVersion::current();
        $this->assertNotSame($before, $afterCreate);

        $announcement->delete();
        $this->assertNotSame($afterCreate, LiveVersion::current());
    }
}
