<?php

namespace Tests\Feature;

use App\Events\SupervisionReportReviewed;
use App\Models\Setting;
use App\Models\SupervisionReport;
use App\Models\User;
use App\Notifications\LabMailNotification;
use App\Notifications\LabNotification;
use App\Support\Notifications\Notify;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Semua notifikasi penting ikut dikirim ke email akun (semua peran), lewat antrean. */
class EmailNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_role_gets_the_bell_and_a_queued_email_copy()
    {
        Notification::fake();
        $praktikan = User::factory()->create();
        $asisten = User::factory()->asisten()->create();
        $admin = User::factory()->admin()->create();

        Notify::send([$praktikan->id, $asisten->id, $admin->id], 'task', 'Tugas baru', 'Basis Data · A', '/praktikan/tasks/1');

        foreach ([$praktikan, $asisten, $admin] as $user) {
            Notification::assertSentTo($user, LabNotification::class);
            Notification::assertSentTo($user, LabMailNotification::class, fn (LabMailNotification $n, array $channels) => $channels === ['mail']);
        }

        $this->assertInstanceOf(ShouldQueue::class, new LabMailNotification('x', 'x', 'x'));
        $this->assertNotInstanceOf(ShouldQueue::class, new LabNotification('x', 'x', 'x'));
    }

    public function test_the_mail_contains_the_title_message_and_a_link()
    {
        $user = User::factory()->create(['name' => 'Rani']);
        $html = (new LabMailNotification('grade', 'Nilai dipublikasikan', 'Nilai Pre-Test sudah dapat dilihat.', '/praktikan/grade'))->toMail($user)->render();

        $this->assertStringContainsString('Halo, Rani', (string) $html);
        $this->assertStringContainsString('Nilai Pre-Test sudah dapat dilihat.', (string) $html);
        $this->assertStringContainsString(url('/praktikan/grade'), (string) $html);
    }

    public function test_the_global_switch_turns_off_email_but_keeps_the_bell()
    {
        Notification::fake();
        Setting::put(['email_notifications' => false], null);
        $user = User::factory()->create();

        Notify::send([$user->id], 'task', 'Tugas baru', 'x');

        Notification::assertSentTo($user, LabNotification::class);
        Notification::assertNotSentTo($user, LabMailNotification::class);
    }

    public function test_accounts_without_a_valid_email_are_skipped_for_mail_only()
    {
        Notification::fake();
        $broken = User::factory()->create(['email' => 'bukan-email']);

        Notify::send([$broken->id], 'task', 'Tugas baru', 'x');

        Notification::assertSentTo($broken, LabNotification::class);
        Notification::assertNotSentTo($broken, LabMailNotification::class);
    }

    public function test_the_caller_can_opt_out_of_email_for_a_single_notification()
    {
        Notification::fake();
        $user = User::factory()->create();

        Notify::send([$user->id], 'task', 'x', 'x', null, false);

        Notification::assertNotSentTo($user, LabMailNotification::class);
    }

    public function test_large_audiences_are_staggered_in_batches_of_twenty()
    {
        Notification::fake();
        $users = User::factory()->count(45)->create();

        Notify::send($users->pluck('id')->all(), 'announcement', 'Pengumuman', 'x');

        $delays = [];
        Notification::assertSentTo($users, LabMailNotification::class, function (LabMailNotification $n) use (&$delays) {
            $delays[] = $n->delay === null ? 0 : (int) now()->diffInSeconds($n->delay, true);

            return true;
        });

        $this->assertCount(45, $delays);
        $this->assertSame(20, count(array_filter($delays, fn ($d) => $d === 0)));
        $this->assertGreaterThan(0, max($delays));
    }

    public function test_a_real_domain_event_sends_the_email_to_the_report_owner()
    {
        Notification::fake();
        $report = SupervisionReport::factory()->create(['status' => 'verified']);

        SupervisionReportReviewed::dispatch($report->fresh());

        Notification::assertSentTo($report->user, LabMailNotification::class, fn (LabMailNotification $n) => $n->title === 'Laporan ngawas diverifikasi');
    }

    public function test_admin_can_toggle_the_switch_in_system_settings()
    {
        $admin = User::factory()->admin()->create();
        $payload = ['idle_minutes' => 30, 'limit_pdf_mb' => 10, 'limit_zip_mb' => 20, 'limit_image_mb' => 5, 'registration_domains' => 'itpln.ac.id'];

        $this->actingAs($admin)->put(route('admin.system-settings.update'), [...$payload, 'email_notifications' => '0'])->assertSessionHasNoErrors();
        $this->assertFalse(Setting::value('email_notifications'));

        $this->actingAs($admin)->put(route('admin.system-settings.update'), [...$payload, 'email_notifications' => '1'])->assertSessionHasNoErrors();
        $this->assertTrue(Setting::value('email_notifications'));
    }
}
