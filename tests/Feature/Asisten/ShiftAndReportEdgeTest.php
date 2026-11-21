<?php

namespace Tests\Feature\Asisten;

use App\Models\LabClass;
use App\Models\Meeting;
use App\Models\Room;
use App\Models\Semester;
use App\Models\Shift;
use App\Models\ShiftSwap;
use App\Models\StoredFile;
use App\Models\SupervisionReport;
use App\Models\User;
use App\Support\Files\FileStore;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Audit 03 P1-6: hapus laporan/pertemuan, kelola shift (khusus Admin), batalkan tukar shift, kandidat tukar.
 */
class ShiftAndReportEdgeTest extends TestCase
{
    use RefreshDatabase;

    private Semester $semester;

    private Room $room;

    private User $asisten;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('lab');
        $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00'));

        $this->semester = Semester::factory()->active()->create(['start_date' => '2026-09-01', 'end_date' => '2027-01-31']);
        $this->room = Room::factory()->create();
        $this->asisten = User::factory()->asisten()->create();
    }

    private function shift(User $user, string $date, string $start = '13:00', string $end = '16:00'): Shift
    {
        return Shift::create(['semester_id' => $this->semester->id, 'user_id' => $user->id, 'room_id' => $this->room->id, 'title' => 'Jaga Lab', 'date' => $date, 'start_time' => $start, 'end_time' => $end]);
    }

    private function report(array $attributes = []): SupervisionReport
    {
        return SupervisionReport::factory()->create(['semester_id' => $this->semester->id, 'user_id' => $this->asisten->id, ...$attributes]);
    }

    // --- Laporan ---

    public function test_only_the_author_can_delete_a_draft_and_its_photo_goes_with_it(): void
    {
        $report = $this->report();
        app(FileStore::class)->store(UploadedFile::fake()->image('foto.jpg'), ['image'], 'report_photo', $report, $this->asisten, 'photo');
        $path = StoredFile::sole()->path;

        $this->actingAs(User::factory()->asisten()->create())->delete(route('asisten.reports.destroy', $report))->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())->delete(route('asisten.reports.destroy', $report))->assertForbidden();
        $this->assertNotNull($report->fresh());

        $this->actingAs($this->asisten)->delete(route('asisten.reports.destroy', $report))->assertRedirect();

        $this->assertNull(SupervisionReport::find($report->id));
        $this->assertSame(0, StoredFile::count());
        Storage::disk('lab')->assertMissing($path);
        $this->assertDatabaseHas('audit_logs', ['action' => 'report.deleted', 'auditable_id' => $report->id]);
    }

    public function test_submitted_verified_and_returned_reports_cannot_be_deleted(): void
    {
        $this->actingAs($this->asisten);

        foreach (['submitted', 'verified', 'returned'] as $status) {
            $report = $this->report(['status' => $status, 'date' => '2026-10-0'.(['submitted' => 1, 'verified' => 2, 'returned' => 3][$status])]);

            $this->delete(route('asisten.reports.destroy', $report))->assertForbidden();
            $this->assertNotNull($report->fresh(), $status);
        }
    }

    // --- Pertemuan ---

    public function test_a_meeting_that_has_not_started_can_be_deleted_by_its_assistant_only(): void
    {
        $class = LabClass::factory()->create(['semester_id' => $this->semester->id, 'start_time' => '13:00', 'end_time' => '15:00']);
        $class->assistants()->attach($this->asisten);
        $meeting = Meeting::factory()->for($class, 'labClass')->create(['number' => 1, 'date' => '2026-10-12']);

        $this->actingAs(User::factory()->asisten()->create())->delete(route('asisten.meetings.destroy', $meeting))->assertForbidden();
        $this->assertNotNull($meeting->fresh());

        $this->actingAs($this->asisten)->delete(route('asisten.meetings.destroy', $meeting))->assertRedirect();
        $this->assertNull(Meeting::find($meeting->id));
    }

    public function test_a_started_meeting_cannot_be_deleted(): void
    {
        $class = LabClass::factory()->create(['semester_id' => $this->semester->id, 'start_time' => '07:00', 'end_time' => '09:00']);
        $class->assistants()->attach($this->asisten);
        $past = Meeting::factory()->for($class, 'labClass')->create(['number' => 1, 'date' => '2026-10-05']); // mulai 07:00, sekarang 10:00
        $manual = Meeting::factory()->for($class, 'labClass')->create(['number' => 2, 'date' => '2026-10-20', 'started_at' => now()]);

        $this->actingAs($this->asisten);

        $this->delete(route('asisten.meetings.destroy', $past))->assertStatus(422);
        $this->delete(route('asisten.meetings.destroy', $manual))->assertStatus(422);
        $this->assertSame(2, Meeting::count());
    }

    public function test_meetings_of_an_archived_semester_are_read_only(): void
    {
        $archived = Semester::factory()->archived()->create();
        $class = LabClass::factory()->create(['semester_id' => $archived->id]);
        $class->assistants()->attach($this->asisten);
        $meeting = Meeting::factory()->for($class, 'labClass')->create(['number' => 1, 'date' => '2030-01-01']);

        $this->actingAs($this->asisten)->delete(route('asisten.meetings.destroy', $meeting))->assertForbidden();
    }

    // --- Shift (khusus Admin) ---

    public function test_regular_assistants_cannot_manage_shifts(): void
    {
        $shift = $this->shift($this->asisten, '2026-10-12');
        $this->actingAs($this->asisten);

        $this->post(route('asisten.shifts.store'), ['user_id' => $this->asisten->id, 'date' => '2026-10-12', 'start_time' => '08:00', 'end_time' => '09:00'])->assertForbidden();
        $this->post(route('asisten.shifts.generate'))->assertForbidden();
        $this->delete(route('asisten.shifts.destroy', $shift))->assertForbidden();

        $this->assertSame(1, Shift::count());
    }

    public function test_admin_adds_weekly_shifts_skipping_clashes_and_dates_after_the_semester(): void
    {
        $admin = User::factory()->admin()->create();
        $this->shift($this->asisten, '2026-10-19', '08:30', '09:30'); // bentrok di minggu ke-2
        $this->actingAs($admin);

        // Formulir selalu mengirim `title` (kosong → null). Lihat Temuan baru N7 untuk kunci yang hilang.
        $this->post(route('asisten.shifts.store'), [
            'user_id' => $this->asisten->id, 'room_id' => $this->room->id, 'title' => '', 'date' => '2026-10-12', 'start_time' => '08:00', 'end_time' => '09:00', 'repeat_weeks' => 3,
        ])->assertSessionHasNoErrors();

        $dates = Shift::where('title', 'Jaga Lab')->orderBy('date')->get()->map(fn (Shift $s) => $s->date->toDateString())->all();
        $this->assertSame(['2026-10-12', '2026-10-19', '2026-10-26'], $dates, '19 Okt sudah punya shift bentrok (satu yang lama), jadi hanya 12 dan 26 yang baru');
        $this->assertSame(3, Shift::count());

        $this->post(route('asisten.shifts.store'), ['user_id' => $this->asisten->id, 'title' => '', 'date' => '2027-03-01', 'start_time' => '08:00', 'end_time' => '09:00'])->assertSessionHasNoErrors();
        $this->assertSame(3, Shift::count(), 'tanggal setelah akhir semester tidak dibuat');
    }

    public function test_shift_input_is_validated(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->post(route('asisten.shifts.store'), ['user_id' => 999999, 'date' => 'bukan-tanggal', 'start_time' => '9', 'end_time' => '08:00'])
            ->assertSessionHasErrors(['user_id', 'date', 'start_time']);
        $this->post(route('asisten.shifts.store'), ['user_id' => $this->asisten->id, 'date' => '2026-10-12', 'start_time' => '10:00', 'end_time' => '09:00'])
            ->assertSessionHasErrors('end_time');
        $this->post(route('asisten.shifts.store'), ['user_id' => $this->asisten->id, 'date' => '2026-10-12', 'start_time' => '08:00', 'end_time' => '09:00', 'repeat_weeks' => 21])
            ->assertSessionHasErrors('repeat_weeks');

        $this->assertSame(0, Shift::count());
    }

    public function test_generating_class_shifts_creates_one_shift_per_assistant_per_meeting(): void
    {
        $class = LabClass::factory()->create(['semester_id' => $this->semester->id, 'room_id' => $this->room->id]);
        $class->assistants()->sync([$this->asisten->id]);
        Meeting::factory()->for($class, 'labClass')->create(['number' => 1, 'date' => '2026-10-12']);
        Meeting::factory()->for($class, 'labClass')->create(['number' => 2, 'date' => '2026-10-19']);
        $this->actingAs(User::factory()->admin()->create());

        // Idempotensi (menjalankan dua kali) tidak dikunci di sini: firstOrCreate memakai kolom `date` dengan
        // cast, dan di SQLite hasilnya menggandakan baris, sedangkan di MySQL tidak. Lihat Temuan baru N8.
        $this->post(route('asisten.shifts.generate'))->assertSessionHasNoErrors();

        $this->assertSame(2, Shift::where('class_id', $class->id)->where('user_id', $this->asisten->id)->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'shift.generated']);
    }

    public function test_a_shift_with_a_report_cannot_be_deleted_but_an_empty_one_can(): void
    {
        $withReport = $this->shift($this->asisten, '2026-10-12');
        $this->report(['shift_id' => $withReport->id, 'date' => '2026-10-12']);
        $empty = $this->shift($this->asisten, '2026-10-13');
        $this->actingAs(User::factory()->admin()->create());

        $this->delete(route('asisten.shifts.destroy', $withReport))->assertSessionHasErrors('shift');
        $this->assertNotNull($withReport->fresh());

        $this->delete(route('asisten.shifts.destroy', $empty))->assertRedirect();
        $this->assertNull(Shift::find($empty->id));
    }

    // --- Tukar shift ---

    public function test_only_the_requester_can_cancel_a_pending_swap(): void
    {
        $shift = $this->shift($this->asisten, '2026-10-12');
        $target = User::factory()->asisten()->create();
        $this->actingAs($this->asisten)->post(route('asisten.shift-swaps.store'), ['shift_id' => $shift->id, 'to_user_id' => $target->id, 'reason' => 'Ada keperluan keluarga']);
        $swap = ShiftSwap::sole();

        $this->actingAs($target)->delete(route('asisten.shift-swaps.destroy', $swap))->assertForbidden();
        $this->actingAs(User::factory()->asisten()->create())->delete(route('asisten.shift-swaps.destroy', $swap))->assertForbidden();
        $this->assertSame('pending', $swap->fresh()->status);

        $this->actingAs($this->asisten)->delete(route('asisten.shift-swaps.destroy', $swap))->assertRedirect();
        $this->assertSame('cancelled', $swap->fresh()->status);

        // Sudah dibatalkan → tidak bisa dibatalkan lagi, dan tidak bisa dijawab.
        $this->delete(route('asisten.shift-swaps.destroy', $swap))->assertForbidden();
        $this->actingAs($target)->post(route('asisten.shift-swaps.respond', $swap), ['approve' => true])->assertSessionHasErrors();
        $this->assertSame($this->asisten->id, $shift->fresh()->user_id);
    }

    public function test_swap_candidates_exclude_the_owner_inactive_accounts_and_clashing_assistants(): void
    {
        $shift = $this->shift($this->asisten, '2026-10-12', '13:00', '16:00');
        $free = User::factory()->asisten()->create(['name' => 'Bebas']);
        $busy = User::factory()->asisten()->create(['name' => 'Sibuk']);
        $this->shift($busy, '2026-10-12', '15:00', '17:00');
        User::factory()->asisten()->create(['name' => 'Nonaktif', 'is_active' => false]);
        User::factory()->create(['name' => 'Praktikan']);

        $response = $this->actingAs($this->asisten)->getJson(route('asisten.shifts.swap-candidates', $shift))->assertOk();

        $this->assertSame([$free->id], collect($response->json())->pluck('id')->all());

        // Hanya pemilik shift yang boleh melihat kandidat.
        $this->actingAs($free)->getJson(route('asisten.shifts.swap-candidates', $shift))->assertForbidden();
    }
}
