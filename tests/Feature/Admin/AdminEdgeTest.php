<?php

namespace Tests\Feature\Admin;

use App\Models\Announcement;
use App\Models\Course;
use App\Models\LabClass;
use App\Models\Room;
use App\Models\Seat;
use App\Models\SeatContract;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Audit 03 P1-7: rute Admin yang belum tercakup (kelas, ruangan, denah, pengumuman, kontrak kursi).
 */
class AdminEdgeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Semester $semester;

    private Course $course;

    private Room $room;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->semester = Semester::factory()->active()->create();
        $this->course = Course::factory()->for($this->semester)->create();
        $this->room = Room::factory()->create(['rows' => 4, 'cols' => 4]);
        $this->actingAs($this->admin);
    }

    private function classPayload(LabClass $class, array $overrides = []): array
    {
        return [
            'semester_id' => $class->semester_id, 'course_id' => $class->course_id, 'name' => $class->name, 'lecturer' => 'Dosen Uji',
            'day_of_week' => 2, 'start_time' => '10:00', 'end_time' => '12:00', 'room_id' => $this->room->id, 'quota' => 25, 'assistant_ids' => [],
            ...$overrides,
        ];
    }

    // --- Kelas ---

    public function test_the_class_index_lists_classes_of_the_selected_semester(): void
    {
        LabClass::factory()->create(['semester_id' => $this->semester->id, 'course_id' => $this->course->id, 'name' => 'Kelas Aktif']);
        $archived = Semester::factory()->archived()->create();
        LabClass::factory()->create(['semester_id' => $archived->id, 'name' => 'Kelas Lama']);

        $this->get(route('admin.classes.index', ['semester' => $this->semester->id]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('admin/classes/index')->has('classes', 1)->where('classes.0.name', 'Kelas Aktif'));

        $this->get(route('admin.classes.index', ['semester' => $archived->id]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('classes', 1)->where('classes.0.name', 'Kelas Lama')->where('semester.editable', false));
    }

    public function test_updating_a_class_saves_fields_syncs_assistants_and_is_audited(): void
    {
        $class = LabClass::factory()->create(['semester_id' => $this->semester->id, 'course_id' => $this->course->id, 'name' => 'Kelas A']);
        $old = User::factory()->asisten()->create();
        $new = User::factory()->asisten()->create();
        $class->assistants()->attach($old);

        $this->put(route('admin.classes.update', $class), $this->classPayload($class, ['quota' => 25, 'assistant_ids' => [$new->id]]))->assertSessionHasNoErrors();

        $fresh = $class->fresh();
        $this->assertSame(25, $fresh->quota);
        $this->assertSame([$new->id], $fresh->assistants()->pluck('users.id')->all());
        $this->assertDatabaseHas('audit_logs', ['action' => 'class.updated', 'auditable_id' => $class->id]);
    }

    public function test_classes_of_an_archived_semester_cannot_be_updated_or_deleted(): void
    {
        $archived = Semester::factory()->archived()->create();
        $class = LabClass::factory()->create(['semester_id' => $archived->id, 'name' => 'Kelas Lama']);

        $this->put(route('admin.classes.update', $class), $this->classPayload($class))->assertForbidden();
        $this->delete(route('admin.classes.destroy', $class))->assertForbidden();

        $this->assertSame('Kelas Lama', $class->fresh()->name);
    }

    public function test_deleting_a_class_is_audited_and_removes_it_from_the_list(): void
    {
        $class = LabClass::factory()->create(['semester_id' => $this->semester->id, 'course_id' => $this->course->id]);

        $this->delete(route('admin.classes.destroy', $class))->assertRedirect();

        $this->assertNull(LabClass::find($class->id));
        $this->assertDatabaseHas('audit_logs', ['action' => 'class.deleted', 'auditable_id' => $class->id]);
    }

    public function test_class_input_is_validated(): void
    {
        $class = LabClass::factory()->create(['semester_id' => $this->semester->id, 'course_id' => $this->course->id]);

        $this->put(route('admin.classes.update', $class), $this->classPayload($class, ['quota' => 0, 'day_of_week' => 9, 'room_id' => 999999]))
            ->assertSessionHasErrors(['quota', 'day_of_week', 'room_id']);
        $this->put(route('admin.classes.update', $class), $this->classPayload($class, ['start_time' => '12:00', 'end_time' => '10:00']))
            ->assertSessionHasErrors('end_time');
    }

    // --- Ruangan dan denah ---

    public function test_the_room_index_and_layout_pages_render(): void
    {
        $this->get(route('admin.rooms.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('admin/rooms/index'));
        $this->get(route('admin.rooms.layout', $this->room))->assertOk()->assertInertia(fn (Assert $page) => $page->component('admin/rooms/layout'));
    }

    public function test_shrinking_a_room_removes_empty_seats_but_refuses_when_a_contracted_seat_would_be_lost(): void
    {
        $empty = Seat::factory()->for($this->room)->create(['row' => 4, 'col' => 4, 'label' => 'PC-A']);
        $kept = Seat::factory()->for($this->room)->create(['row' => 1, 'col' => 1, 'label' => 'PC-B']);
        $contracted = Seat::factory()->for($this->room)->create(['row' => 3, 'col' => 3, 'label' => 'PC-C']);
        SeatContract::factory()->create(['seat_id' => $contracted->id]);

        $this->put(route('admin.rooms.update', $this->room), ['name' => $this->room->name, 'rows' => 2, 'cols' => 2])->assertSessionHasErrors('rows');
        $this->assertSame(4, $this->room->fresh()->rows);
        $this->assertNotNull(Seat::find($empty->id), 'tidak ada yang berubah saat ditolak');

        SeatContract::query()->delete();
        $this->put(route('admin.rooms.update', $this->room), ['name' => $this->room->name, 'rows' => 2, 'cols' => 2])->assertSessionHasNoErrors();

        $this->assertSame(2, $this->room->fresh()->rows);
        $this->assertNull(Seat::find($empty->id));
        $this->assertNull(Seat::find($contracted->id));
        $this->assertNotNull(Seat::find($kept->id));
    }

    public function test_room_input_is_validated(): void
    {
        Room::factory()->create(['name' => 'Ruang Kembar']);

        foreach ([['rows' => 0], ['rows' => 21], ['cols' => 0], ['cols' => 'x'], ['name' => 'Ruang Kembar'], ['name' => '']] as $bad) {
            $this->put(route('admin.rooms.update', $this->room), ['name' => 'Ruang Baru', 'rows' => 4, 'cols' => 4, ...$bad])->assertSessionHasErrors();
        }

        $this->assertSame(4, $this->room->fresh()->rows);
    }

    public function test_saving_a_layout_rejects_cells_outside_the_room_duplicates_and_missing_labels(): void
    {
        $seat = fn (int $row, int $col, ?string $label) => ['row' => $row, 'col' => $col, 'type' => 'seat', 'label' => $label];

        foreach ([
            [$seat(5, 1, 'PC-1')],                          // di luar ukuran (4×4)
            [$seat(1, 1, 'PC-1'), $seat(1, 1, 'PC-2')],      // posisi ganda
            [$seat(1, 1, '')],                            // label kosong
            [$seat(1, 1, 'PC-1'), $seat(1, 2, 'pc-1')],      // label ganda (tak peka huruf)
            [['row' => 1, 'col' => 1, 'type' => 'lampu', 'label' => null]], // jenis tak dikenal
        ] as $cells) {
            $this->put(route('admin.rooms.layout.update', $this->room), ['cells' => $cells])->assertSessionHasErrors();
        }

        $this->assertSame(0, $this->room->seats()->count());

        $this->put(route('admin.rooms.layout.update', $this->room), ['cells' => [$seat(1, 1, 'PC-1'), $seat(1, 2, 'PC-2')]])->assertSessionHasNoErrors();
        $this->assertSame(2, $this->room->seats()->count());
    }

    public function test_a_contracted_seat_cannot_be_removed_from_the_layout(): void
    {
        $seat = Seat::factory()->for($this->room)->create(['row' => 1, 'col' => 1, 'label' => 'PC-1']);
        SeatContract::factory()->create(['seat_id' => $seat->id]);

        $this->put(route('admin.rooms.layout.update', $this->room), ['cells' => []])->assertSessionHasErrors('cells');
        $this->assertNotNull(Seat::find($seat->id));
    }

    // --- Pengumuman ---

    private function announcement(array $overrides = []): Announcement
    {
        return Announcement::create([
            'scope' => 'global', 'title' => 'Libur', 'body' => 'Lab tutup.', 'publish_at' => now()->subHour(), 'created_by' => $this->admin->id, ...$overrides,
        ]);
    }

    public function test_announcements_can_be_listed_updated_and_deleted_with_an_audit_trail(): void
    {
        $announcement = $this->announcement();

        $this->get(route('admin.announcements.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('admin/announcements'));

        $this->put(route('admin.announcements.update', $announcement), ['scope' => 'global', 'title' => 'Libur Panjang', 'body' => 'Lab tutup sampai Senin.', 'publish_at' => now()->subHour()->toDateTimeString()])
            ->assertSessionHasNoErrors();
        $this->assertSame('Libur Panjang', $announcement->fresh()->title);
        $this->assertDatabaseHas('audit_logs', ['action' => 'announcement.updated', 'auditable_id' => $announcement->id]);

        $this->delete(route('admin.announcements.destroy', $announcement))->assertRedirect();
        $this->assertNull(Announcement::find($announcement->id));
        $this->assertDatabaseHas('audit_logs', ['action' => 'announcement.deleted', 'auditable_id' => $announcement->id]);
    }

    public function test_announcement_input_is_validated(): void
    {
        $announcement = $this->announcement();
        $base = ['scope' => 'global', 'title' => 'Judul', 'body' => 'Isi', 'publish_at' => now()->toDateTimeString()];

        foreach ([
            ['title' => ''],
            ['title' => str_repeat('a', 151)],
            ['body' => str_repeat('a', 5001)],
            ['scope' => 'semua'],
            ['scope' => 'class', 'class_id' => null],
            ['expire_at' => now()->subDay()->toDateTimeString()],
        ] as $bad) {
            $this->put(route('admin.announcements.update', $announcement), [...$base, ...$bad])->assertSessionHasErrors();
        }

        $this->assertSame('Libur', $announcement->fresh()->title);
    }

    // --- Kontrak kursi ---

    public function test_the_seat_contract_index_renders_with_and_without_an_active_semester(): void
    {
        $this->get(route('admin.seat-contracts.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('admin/seat-contracts'));

        $this->semester->forceFill(['status' => 'archived'])->save();
        $this->get(route('admin.seat-contracts.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('selected', null)->where('map', null));
    }
}
