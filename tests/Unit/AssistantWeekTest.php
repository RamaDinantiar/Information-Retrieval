<?php

namespace Tests\Unit;

use App\Models\Room;
use App\Models\Semester;
use App\Models\Shift;
use App\Models\User;
use App\Support\Schedule\AssistantWeek;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Audit 03 P2-4: agenda mingguan, shift berikutnya, dan kandidat tukar shift.
 * (`ClassDays` hanya konstanta nama hari, tidak ada logika untuk dites.)
 */
class AssistantWeekTest extends TestCase
{
    use RefreshDatabase;

    private Semester $semester;

    private Room $room;

    protected function setUp(): void
    {
        parent::setUp();
        $this->semester = Semester::factory()->active()->create(['start_date' => '2026-09-01', 'end_date' => '2027-01-31']);
        $this->room = Room::factory()->create();
    }

    private function shift(User $user, string $date, string $start, string $end): Shift
    {
        return Shift::create(['semester_id' => $this->semester->id, 'user_id' => $user->id, 'room_id' => $this->room->id, 'title' => 'Jaga Lab', 'date' => $date, 'start_time' => $start, 'end_time' => $end]);
    }

    public function test_events_include_only_the_users_shifts_inside_the_week_in_time_order(): void
    {
        $me = User::factory()->asisten()->create();
        $other = User::factory()->asisten()->create();
        $monday = CarbonImmutable::parse('2026-10-05');

        $this->shift($me, '2026-10-07', '13:00', '15:00');
        $this->shift($me, '2026-10-05', '08:00', '10:00');
        $this->shift($me, '2026-10-11', '09:00', '10:00'); // Minggu: hari terakhir pekan
        $this->shift($me, '2026-10-04', '09:00', '10:00'); // pekan sebelumnya
        $this->shift($me, '2026-10-12', '09:00', '10:00'); // pekan berikutnya
        $this->shift($other, '2026-10-06', '09:00', '10:00');

        $events = AssistantWeek::events($me, $monday, $monday->setTime(7, 0));

        $this->assertSame([[1, '08:00'], [3, '13:00'], [7, '09:00']], array_map(fn ($e) => [$e['day'], $e['start']], $events));
    }

    public function test_the_running_flag_is_true_only_between_start_and_end(): void
    {
        $me = User::factory()->asisten()->create();
        $monday = CarbonImmutable::parse('2026-10-05');
        $this->shift($me, '2026-10-05', '08:00', '10:00');

        $running = fn (string $at) => AssistantWeek::events($me, $monday, CarbonImmutable::parse("2026-10-05 {$at}"))[0]['running'];

        $this->assertFalse($running('07:59'));
        $this->assertTrue($running('08:00'));
        $this->assertTrue($running('10:00'));
        $this->assertFalse($running('10:01'));
    }

    public function test_next_shift_skips_finished_shifts_and_includes_one_in_progress(): void
    {
        $me = User::factory()->asisten()->create();
        $this->shift($me, '2026-10-05', '08:00', '10:00');
        $this->shift($me, '2026-10-05', '13:00', '15:00');
        $this->shift($me, '2026-10-06', '08:00', '10:00');

        $this->assertStringContainsString('13:00', AssistantWeek::nextShift($me, CarbonImmutable::parse('2026-10-05 10:30'))['startsAt']);
        $this->assertStringContainsString('13:00', AssistantWeek::nextShift($me, CarbonImmutable::parse('2026-10-05 14:00'))['startsAt']);
        $this->assertStringContainsString('2026-10-06', AssistantWeek::nextShift($me, CarbonImmutable::parse('2026-10-05 15:00'))['startsAt']);
        $this->assertNull(AssistantWeek::nextShift($me, CarbonImmutable::parse('2026-10-06 10:00')));
    }

    public function test_next_shift_handles_a_shift_that_crosses_midnight_boundary_day_only(): void
    {
        // Shift tersimpan per tanggal mulai; pada tepat jam selesai ia dianggap sudah lewat.
        $me = User::factory()->asisten()->create();
        $this->shift($me, '2026-10-05', '20:00', '23:59');

        $this->assertNotNull(AssistantWeek::nextShift($me, CarbonImmutable::parse('2026-10-05 23:00')));
        $this->assertNull(AssistantWeek::nextShift($me, CarbonImmutable::parse('2026-10-05 23:59')));
        $this->assertNull(AssistantWeek::nextShift($me, CarbonImmutable::parse('2026-10-06 00:30')));
    }

    public function test_swap_candidates_exclude_the_owner_inactive_non_assistants_and_clashing_shifts(): void
    {
        $owner = User::factory()->asisten()->create();
        $free = User::factory()->asisten()->create(['name' => 'Bebas']);
        $busy = User::factory()->asisten()->create(['name' => 'Bentrok']);
        $adjacent = User::factory()->asisten()->create(['name' => 'Bersebelahan']);
        User::factory()->asisten()->create(['is_active' => false]);
        User::factory()->create(); // praktikan

        $shift = $this->shift($owner, '2026-10-05', '13:00', '15:00');
        $this->shift($busy, '2026-10-05', '14:00', '16:00');
        $this->shift($adjacent, '2026-10-05', '15:00', '17:00'); // menempel, bukan bentrok

        $names = AssistantWeek::swapCandidates($shift)->pluck('name')->all();

        $this->assertSame(['Bebas', 'Bersebelahan'], $names);
        $this->assertNotContains($owner->name, $names);
        $this->assertContains($free->name, $names);
    }
}
