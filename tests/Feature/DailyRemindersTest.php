<?php

namespace Tests\Feature;

use App\Enums\Position;
use App\Enums\TaskType;
use App\Models\Course;
use App\Models\Grade;
use App\Models\LabClass;
use App\Models\ReminderLog;
use App\Models\Semester;
use App\Models\Shift;
use App\Models\Submission;
use App\Models\SupervisionReport;
use App\Models\Task;
use App\Models\TaskTarget;
use App\Models\User;
use App\Notifications\LabMailNotification;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Pengingat harian: laporan belum diisi, belum menilai, menunggu verifikasi (lonceng + email). */
class DailyRemindersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-14 08:00'));
    }

    private function shift(User $user, string $date): Shift
    {
        $semester = Semester::current() ?? Semester::factory()->active()->create();

        return Shift::create(['semester_id' => $semester->id, 'user_id' => $user->id, 'date' => $date, 'start_time' => '09:00', 'end_time' => '11:00', 'title' => 'Jaga Lab']);
    }

    private function sendReminders(): void
    {
        $this->artisan('reminders:send')->assertSuccessful();
    }

    private function titles(User $user): array
    {
        return $user->notifications->map(fn ($n) => $n->data['title'])->all();
    }

    public function test_a_missing_report_reminder_goes_out_by_bell_and_email_once_per_day()
    {
        Notification::fake();
        $asisten = User::factory()->asisten()->create();
        $this->shift($asisten, '2026-10-13');

        $this->sendReminders();
        $this->sendReminders();

        Notification::assertSentToTimes($asisten, LabMailNotification::class, 1);
        Notification::assertSentTo($asisten, LabMailNotification::class, fn ($n) => $n->title === 'Laporan ngawas belum diisi' && str_contains($n->body, 'Jaga Lab'));
    }

    public function test_the_reminder_repeats_daily_but_at_most_three_times_per_shift()
    {
        Notification::fake();
        $asisten = User::factory()->asisten()->create();
        $this->shift($asisten, '2026-10-13');

        foreach (range(1, 5) as $day) {
            $this->sendReminders();
            $this->travel(1)->day();
        }

        Notification::assertSentToTimes($asisten, LabMailNotification::class, 3);
        $this->assertSame(3, ReminderLog::where('user_id', $asisten->id)->where('kind', 'missing_report')->count());
    }

    public function test_no_reminder_when_a_report_exists_owned_or_as_a_team_member_or_for_other_cases()
    {
        Notification::fake();
        $owner = User::factory()->asisten()->create();
        $partner = User::factory()->asisten()->create();
        $inactive = User::factory()->asisten()->create(['is_active' => false]);
        $today = User::factory()->asisten()->create();
        $old = User::factory()->asisten()->create();

        $this->shift($owner, '2026-10-13');
        $this->shift($partner, '2026-10-13');
        $this->shift($inactive, '2026-10-13');
        $this->shift($today, '2026-10-14'); // hari ini: belum jatuh tempo
        $this->shift($old, '2026-10-01');   // lebih dari 3 hari lalu

        $report = SupervisionReport::factory()->create(['user_id' => $owner->id, 'date' => '2026-10-13', 'status' => 'submitted']);
        $report->assistants()->sync([$owner->id => ['position' => 1], $partner->id => ['position' => 2]]);

        $this->sendReminders();

        foreach ([$owner, $partner, $inactive, $today, $old] as $user) {
            Notification::assertNotSentTo($user, LabMailNotification::class);
        }
    }

    public function test_a_draft_report_still_counts_as_missing()
    {
        Notification::fake();
        $asisten = User::factory()->asisten()->create();
        $this->shift($asisten, '2026-10-13');
        SupervisionReport::factory()->create(['user_id' => $asisten->id, 'date' => '2026-10-13', 'status' => 'draft']);

        $this->sendReminders();

        Notification::assertSentTo($asisten, LabMailNotification::class);
    }

    public function test_ungraded_submissions_older_than_a_day_trigger_one_daily_summary()
    {
        Notification::fake();
        $asisten = User::factory()->asisten()->create();
        $other = User::factory()->asisten()->create();
        $course = Course::factory()->for(Semester::factory()->active())->create();
        $class = LabClass::factory()->for($course)->create(['semester_id' => $course->semester_id]);
        $class->assistants()->attach($asisten);
        $target = TaskTarget::factory()->for(Task::factory()->create(['course_id' => $course->id, 'type' => TaskType::Report]))
            ->create(['class_id' => $class->id, 'open_at' => now()->subDays(5)]);

        Submission::factory()->count(2)->for($target, 'target')->create(['submitted_at' => now()->subDays(2)]);
        Submission::factory()->for($target, 'target')->create(['submitted_at' => now()->subHours(3)]); // terlalu baru
        Grade::factory()->for(Submission::factory()->for($target, 'target')->create(['submitted_at' => now()->subDays(3)]))->create(); // sudah dinilai

        $this->sendReminders();
        $this->sendReminders();

        Notification::assertSentToTimes($asisten, LabMailNotification::class, 1);
        Notification::assertSentTo($asisten, LabMailNotification::class, fn ($n) => $n->title === 'Pengumpulan belum dinilai' && str_contains($n->body, '2 pengumpulan'));
        Notification::assertNotSentTo($other, LabMailNotification::class);
    }

    public function test_nothing_is_sent_when_everything_is_graded()
    {
        Notification::fake();
        $asisten = User::factory()->asisten()->create();
        $course = Course::factory()->for(Semester::factory()->active())->create();
        $class = LabClass::factory()->for($course)->create(['semester_id' => $course->semester_id]);
        $class->assistants()->attach($asisten);
        $target = TaskTarget::factory()->for(Task::factory()->create(['course_id' => $course->id]))->create(['class_id' => $class->id, 'open_at' => now()->subDays(5)]);
        Grade::factory()->for(Submission::factory()->for($target, 'target')->create(['submitted_at' => now()->subDays(3)]))->create();

        $this->sendReminders();

        Notification::assertNotSentTo($asisten, LabMailNotification::class);
    }

    public function test_the_secretary_is_reminded_of_reports_waiting_over_a_day_but_not_her_own()
    {
        Notification::fake();
        $sekretaris = User::factory()->asisten(Position::SekretarisBendahara)->create();
        $regular = User::factory()->asisten()->create();

        $stale = SupervisionReport::factory()->create(['user_id' => $regular->id, 'status' => 'submitted']);
        $stale->forceFill(['updated_at' => now()->subDays(2)])->saveQuietly();
        $own = SupervisionReport::factory()->create(['user_id' => $sekretaris->id, 'status' => 'submitted']);
        $own->forceFill(['updated_at' => now()->subDays(2)])->saveQuietly();
        SupervisionReport::factory()->create(['user_id' => $regular->id, 'status' => 'submitted']); // baru

        $this->sendReminders();

        Notification::assertSentTo($sekretaris, LabMailNotification::class, fn ($n) => $n->title === 'Laporan ngawas menunggu verifikasi' && str_contains($n->body, '1 laporan'));
        Notification::assertNotSentTo($regular, LabMailNotification::class);
    }

    public function test_the_command_is_scheduled_daily_at_eight()
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'reminders:send'));

        $this->assertNotNull($event);
        $this->assertSame('0 8 * * *', $event->expression);
    }

    public function test_reminders_also_land_in_the_bell_for_users_without_a_queue_worker()
    {
        $asisten = User::factory()->asisten()->create();
        $this->shift($asisten, '2026-10-13');

        $this->sendReminders();

        $this->assertContains('Laporan ngawas belum diisi', $this->titles($asisten->fresh()));
    }
}
