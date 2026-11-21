<?php

namespace Tests\Feature\Asisten;

use App\Models\StoredFile;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** FR-RJN-15(c): pengingat mingguan + peringatan dashboard bagi asisten tanpa tanda tangan. */
class SignatureReminderTest extends TestCase
{
    use RefreshDatabase;

    private function signed(): User
    {
        $user = User::factory()->asisten()->create();
        $file = StoredFile::create(['owner_type' => null, 'owner_id' => null, 'purpose' => 'signature', 'disk' => 'lab', 'path' => 'signature/a.png', 'original_name' => 'a.png', 'mime' => 'image/png', 'size' => 1, 'sha256' => 'a', 'uploaded_by' => $user->id]);
        $user->forceFill(['signature_file_id' => $file->id])->save();

        return $user;
    }

    public function test_only_active_assistants_without_a_signature_are_reminded()
    {
        $missing = User::factory()->asisten()->create();
        $signed = $this->signed();
        $inactive = User::factory()->asisten()->create(['is_active' => false]);
        $praktikan = User::factory()->create();

        $this->artisan('signatures:remind')->assertSuccessful();

        $this->assertSame(1, $missing->notifications()->count());
        $this->assertSame('Tanda tangan belum diunggah', $missing->notifications()->first()->data['title']);
        $this->assertSame('/settings/profile', $missing->notifications()->first()->data['url']);
        $this->assertSame(0, $signed->notifications()->count());
        $this->assertSame(0, $inactive->notifications()->count());
        $this->assertSame(0, $praktikan->notifications()->count());
    }

    public function test_the_reminder_stops_once_the_signature_is_uploaded()
    {
        $user = User::factory()->asisten()->create();

        $this->artisan('signatures:remind');
        $file = StoredFile::create(['owner_type' => null, 'owner_id' => null, 'purpose' => 'signature', 'disk' => 'lab', 'path' => 'signature/b.png', 'original_name' => 'b.png', 'mime' => 'image/png', 'size' => 1, 'sha256' => 'b', 'uploaded_by' => $user->id]);
        $user->forceFill(['signature_file_id' => $file->id])->save();
        $this->artisan('signatures:remind');

        $this->assertSame(1, $user->notifications()->count());
    }

    public function test_dry_run_sends_nothing()
    {
        $user = User::factory()->asisten()->create();

        $this->artisan('signatures:remind', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(0, $user->notifications()->count());
    }

    public function test_it_is_scheduled_weekly_on_monday_morning()
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'signatures:remind'));

        $this->assertNotNull($event);
        $this->assertSame('0 8 * * 1', $event->expression);
    }

    public function test_the_dashboard_warns_only_assistants_without_a_signature()
    {
        $missing = User::factory()->asisten()->create();

        $this->actingAs($missing)->get(route('asisten.dashboard'))
            ->assertInertia(fn (Assert $p) => $p->where('dashboard.alerts', fn ($alerts) => collect($alerts)->contains(fn ($a) => $a['key'] === 'signature' && $a['count'] === 1)));

        $this->actingAs($this->signed())->get(route('asisten.dashboard'))
            ->assertInertia(fn (Assert $p) => $p->where('dashboard.alerts', fn ($alerts) => ! collect($alerts)->contains(fn ($a) => $a['key'] === 'signature')));
    }
}
