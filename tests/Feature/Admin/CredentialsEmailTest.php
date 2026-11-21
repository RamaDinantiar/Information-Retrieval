<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Notifications\AccountCredentialsNotification;
use Exception;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Audit 01 T2/S2: email password sementara dienkripsi di antrean, dicoba ulang, disebar saat impor
 * massal, dan Admin diberi tahu bila tetap gagal terkirim.
 */
class CredentialsEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_notification_is_encrypted_and_retried(): void
    {
        $notification = new AccountCredentialsNotification('abc12345XY');

        $this->assertInstanceOf(ShouldBeEncrypted::class, $notification);
        $this->assertSame(3, $notification->tries);
        $this->assertSame([60, 300], $notification->backoff);
    }

    public function test_temporary_password_is_not_readable_in_the_jobs_table(): void
    {
        config(['queue.default' => 'database']);
        $user = User::factory()->create();

        $user->notify(new AccountCredentialsNotification('PlainSecret99', false, $user->name));

        $payload = (string) DB::table('jobs')->value('payload');

        $this->assertNotSame('', $payload);
        $this->assertStringNotContainsString('PlainSecret99', $payload);
    }

    public function test_bulk_import_spreads_credential_emails_in_batches(): void
    {
        Queue::fake();
        $this->actingAs(User::factory()->admin()->create());

        $rows = collect(range(1, 45))->map(fn (int $i) => sprintf('2023%05d,Praktikan %d,praktikan%d@itpln.ac.id', $i, $i, $i))->implode("\n");
        $path = tempnam(sys_get_temp_dir(), 'import').'.csv';
        file_put_contents($path, "nim,nama,email\n{$rows}\n");

        $this->post(route('admin.users.import'), ['file' => new UploadedFile($path, 'praktikan.csv', 'text/csv', null, true)])
            ->assertInertiaFlash('importResult.created', 45);

        $jobs = Queue::pushed(SendQueuedNotifications::class);

        $this->assertCount(45, $jobs);

        $delays = $jobs->map(fn (SendQueuedNotifications $job) => $job->delay === null ? 0 : (int) round(now()->diffInSeconds($job->delay, false)))
            ->countBy()->sortKeys();

        $this->assertSame([0 => 20, 30 => 20, 60 => 5], $delays->all());
    }

    public function test_admins_are_notified_when_the_credentials_email_finally_fails(): void
    {
        $admin = User::factory()->admin()->create();
        $other = User::factory()->asisten()->create();

        (new AccountCredentialsNotification('abc12345XY', false, 'Rina Praktikan'))->failed(new Exception('SMTP ditolak'));

        $this->assertSame(1, $admin->notifications()->count());
        $this->assertStringContainsString('Rina Praktikan', $admin->notifications()->first()->data['body']);
        $this->assertStringNotContainsString('abc12345XY', json_encode($admin->notifications()->first()->data));
        $this->assertSame(0, $other->notifications()->count());
    }
}
