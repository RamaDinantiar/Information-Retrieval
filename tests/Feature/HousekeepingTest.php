<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Audit 01 R7: tabel notifikasi dan kode OTP tidak tumbuh tanpa batas. */
class HousekeepingTest extends TestCase
{
    use RefreshDatabase;

    private function notification(User $user, ?string $readAt, string $createdAt): string
    {
        $id = (string) Str::uuid();
        DB::table('notifications')->insert([
            'id' => $id, 'type' => 'test', 'notifiable_type' => $user->getMorphClass(), 'notifiable_id' => $user->id,
            'data' => '{}', 'read_at' => $readAt, 'created_at' => $createdAt, 'updated_at' => $createdAt,
        ]);

        return $id;
    }

    public function test_old_notifications_and_expired_otp_codes_are_pruned(): void
    {
        $user = User::factory()->create();
        $oldRead = $this->notification($user, now()->subDays(91), now()->subDays(100));
        $oldUnread = $this->notification($user, null, now()->subDays(400));
        $recentRead = $this->notification($user, now()->subDays(5), now()->subDays(10));
        $recentUnread = $this->notification($user, null, now()->subDays(200));

        DB::table('password_reset_codes')->insert([
            ['email' => 'lama@itpln.ac.id', 'code' => 'x', 'attempts' => 0, 'expires_at' => now()->subDays(3), 'created_at' => now()->subDays(3)],
            ['email' => 'baru@itpln.ac.id', 'code' => 'x', 'attempts' => 0, 'expires_at' => now()->addMinutes(5), 'created_at' => now()],
        ]);

        $this->artisan('housekeeping:prune')->assertSuccessful();

        $remaining = DB::table('notifications')->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$recentRead, $recentUnread], $remaining);
        $this->assertNotContains($oldRead, $remaining);
        $this->assertNotContains($oldUnread, $remaining);
        $this->assertSame(['baru@itpln.ac.id'], DB::table('password_reset_codes')->pluck('email')->all());
    }
}
