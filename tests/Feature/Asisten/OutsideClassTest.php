<?php

namespace Tests\Feature\Asisten;

use App\Enums\Position;
use App\Models\AuditLog;
use App\Models\OutsideClass;
use App\Models\Semester;
use App\Models\SupervisionReport;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** US-RJN-06: kelas di luar Lab IR (CRUD pengelola, akses baca Asisten Umum, daftar bulanan). */
class OutsideClassTest extends TestCase
{
    use RefreshDatabase;

    private Semester $semester;

    private User $manager;

    private User $rama;

    private User $dina;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-04-20 10:00'));

        $this->semester = Semester::factory()->active()->create(['start_date' => '2026-02-16', 'end_date' => '2026-06-19']);
        $this->manager = User::factory()->asisten(Position::SekretarisBendahara)->create();
        $this->rama = User::factory()->asisten()->create(['name' => 'Rama']);
        $this->dina = User::factory()->asisten()->create(['name' => 'Dina']);
    }

    private function payload(array $overrides = []): array
    {
        return [
            'semester_id' => $this->semester->id, 'course_name' => 'Data Warehouse', 'class_name' => 'KR', 'credits' => 2,
            'origin_lab' => 'Lab Sistem Informasi', 'day_of_week' => 1, 'start_time' => '18:30', 'end_time' => '20:10',
            'assistant_ids' => [$this->rama->id], 'lab_confirmed' => false, 'lab_confirmation_note' => null,
            ...$overrides,
        ];
    }

    private function create(array $overrides = []): OutsideClass
    {
        $this->actingAs($this->manager)->post(route('asisten.outside-classes.store'), $this->payload($overrides))->assertSessionHasNoErrors();

        return OutsideClass::latest('id')->firstOrFail();
    }

    public function test_the_manager_creates_an_outside_class_with_assistants()
    {
        $class = $this->create(['assistant_ids' => [$this->rama->id, $this->dina->id]]);

        $this->assertSame($this->semester->id, $class->semester_id);
        $this->assertSame($this->manager->id, $class->created_by);
        $this->assertSame(2, $class->credits);
        $this->assertStringStartsWith('18:30', (string) $class->start_time);
        $this->assertEqualsCanonicalizing([$this->rama->id, $this->dina->id], $class->assistants()->pluck('users.id')->all());
        $this->assertNull($class->lab_confirmed_at);
        $this->assertTrue(AuditLog::where('action', 'outside_class.created')->where('auditable_id', $class->id)->exists());
    }

    public function test_admin_can_manage_too()
    {
        $this->actingAs(User::factory()->admin()->create())->post(route('asisten.outside-classes.store'), $this->payload())->assertSessionHasNoErrors();

        $this->assertSame(1, OutsideClass::count());
    }

    public function test_confirmation_timestamp_is_set_once_and_kept_until_unconfirmed()
    {
        $class = $this->create();
        $this->assertNull($class->lab_confirmed_at);

        $this->put(route('asisten.outside-classes.update', $class), $this->payload(['semester_id' => null, 'lab_confirmed' => true, 'lab_confirmation_note' => 'Via WA Kalab SI']))->assertSessionHasNoErrors();
        $confirmedAt = $class->fresh()->lab_confirmed_at;
        $this->assertNotNull($confirmedAt);
        $this->assertSame('Via WA Kalab SI', $class->fresh()->lab_confirmation_note);

        $this->travelTo(CarbonImmutable::parse('2026-04-25 10:00'));
        $this->put(route('asisten.outside-classes.update', $class), $this->payload(['semester_id' => null, 'lab_confirmed' => true, 'credits' => 3]))->assertSessionHasNoErrors();
        $this->assertEquals($confirmedAt, $class->fresh()->lab_confirmed_at);
        $this->assertSame(3, $class->fresh()->credits);

        $this->put(route('asisten.outside-classes.update', $class), $this->payload(['semester_id' => null, 'lab_confirmed' => false]))->assertSessionHasNoErrors();
        $this->assertNull($class->fresh()->lab_confirmed_at);
        $this->assertSame(2, AuditLog::where('action', 'outside_class.updated')->count() - 1);
    }

    public function test_update_syncs_assistants_and_cannot_move_the_class_to_another_semester()
    {
        $class = $this->create();

        $this->put(route('asisten.outside-classes.update', $class), $this->payload(['semester_id' => null, 'assistant_ids' => [$this->dina->id]]))->assertSessionHasNoErrors();
        $this->assertSame([$this->dina->id], $class->assistants()->pluck('users.id')->all());

        $other = Semester::factory()->create();
        $this->put(route('asisten.outside-classes.update', $class), $this->payload(['semester_id' => $other->id]))->assertSessionHasErrors('semester_id');
    }

    public function test_validation()
    {
        $this->actingAs($this->manager);
        $post = fn (array $o) => $this->post(route('asisten.outside-classes.store'), $this->payload($o));

        $post(['course_name' => ''])->assertSessionHasErrors('course_name');
        $post(['class_name' => ''])->assertSessionHasErrors('class_name');
        $post(['credits' => 0])->assertSessionHasErrors('credits');
        $post(['credits' => 7])->assertSessionHasErrors('credits');
        $post(['origin_lab' => ''])->assertSessionHasErrors('origin_lab');
        $post(['day_of_week' => 8])->assertSessionHasErrors('day_of_week');
        $post(['start_time' => '20:10', 'end_time' => '18:30'])->assertSessionHasErrors('end_time');
        $post(['end_time' => null])->assertSessionHasErrors('end_time'); // jam mulai tanpa jam selesai
        $post(['assistant_ids' => []])->assertSessionHasErrors('assistant_ids');
        $post(['assistant_ids' => [User::factory()->create()->id]])->assertSessionHasErrors('assistant_ids.0'); // praktikan
        $post(['assistant_ids' => [User::factory()->asisten()->create(['is_active' => false])->id]])->assertSessionHasErrors('assistant_ids.0');
        $post(['semester_id' => 9999])->assertSessionHasErrors('semester_id');

        $this->assertSame(0, OutsideClass::count());

        // Hari dan jam boleh kosong.
        $post(['day_of_week' => null, 'start_time' => null, 'end_time' => null])->assertSessionHasNoErrors();
        $this->assertSame(1, OutsideClass::count());
    }

    public function test_a_class_name_is_unique_per_course_and_semester_but_not_after_deletion()
    {
        $class = $this->create();

        $this->post(route('asisten.outside-classes.store'), $this->payload())->assertSessionHasErrors('class_name');
        $this->post(route('asisten.outside-classes.store'), $this->payload(['class_name' => 'KR2']))->assertSessionHasNoErrors();

        $this->delete(route('asisten.outside-classes.destroy', $class))->assertRedirect();
        $this->post(route('asisten.outside-classes.store'), $this->payload())->assertSessionHasNoErrors();
    }

    public function test_deleting_is_soft_audited_and_blocked_when_reports_exist()
    {
        $class = $this->create();
        $this->delete(route('asisten.outside-classes.destroy', $class))->assertSessionHasNoErrors();

        $this->assertSoftDeleted($class);
        $this->assertTrue(AuditLog::where('action', 'outside_class.deleted')->exists());

        $used = $this->create(['class_name' => 'KR2']);
        SupervisionReport::factory()->create(['semester_id' => $this->semester->id, 'user_id' => $this->rama->id, 'outside_class_id' => $used->id]);

        $this->delete(route('asisten.outside-classes.destroy', $used))->assertSessionHasErrors('outside_class');
        $this->assertNotSoftDeleted($used);
    }

    public function test_regular_assistants_and_praktikan_cannot_change_anything()
    {
        $class = $this->create();

        foreach ([$this->rama, User::factory()->create()] as $user) {
            $this->actingAs($user);
            $this->post(route('asisten.outside-classes.store'), $this->payload(['class_name' => 'X']))->assertForbidden();
            $this->put(route('asisten.outside-classes.update', $class), $this->payload(['semester_id' => null]))->assertForbidden();
            $this->delete(route('asisten.outside-classes.destroy', $class))->assertForbidden();
        }

        $this->assertSame(1, OutsideClass::count());
    }

    public function test_archived_semesters_are_read_only()
    {
        $archived = Semester::factory()->archived()->create(['start_date' => '2025-08-01', 'end_date' => '2025-12-20']);
        $class = $archived->outsideClasses()->create(['course_name' => 'Jaringan', 'class_name' => 'A', 'credits' => 3, 'origin_lab' => 'Lab Jarkom']);
        $this->actingAs($this->manager);

        $this->post(route('asisten.outside-classes.store'), $this->payload(['semester_id' => $archived->id]))->assertForbidden();
        $this->put(route('asisten.outside-classes.update', $class), $this->payload(['semester_id' => null]))->assertForbidden();
        $this->delete(route('asisten.outside-classes.destroy', $class))->assertForbidden();
        $this->get(route('asisten.outside-classes.index', ['semester' => $archived->id]))->assertInertia(fn (Assert $page) => $page->where('canManage', false)->has('classes', 1));
    }

    public function test_the_manager_sees_every_class_and_assistant_options()
    {
        $this->create();
        $this->create(['class_name' => 'B', 'assistant_ids' => [$this->dina->id]]);

        $this->get(route('asisten.outside-classes.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('asisten/outside-classes')
            ->where('canManage', true)
            ->has('classes', 2)
            ->where('classes.0.originLab', 'Lab Sistem Informasi')
            ->has('assistantOptions', 3)
            ->where('filters.semester', $this->semester->id)
            ->where('filters.month', '2026-04'));
    }

    public function test_a_regular_assistant_only_sees_their_own_classes_read_only()
    {
        $this->create();
        $this->create(['class_name' => 'B', 'assistant_ids' => [$this->dina->id]]);

        $this->actingAs($this->rama)->get(route('asisten.outside-classes.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('canManage', false)
            ->has('classes', 1)
            ->where('classes.0.className', 'KR')
            ->where('assistantOptions', []));
    }

    public function test_the_monthly_list_summarises_reports_for_the_selected_month()
    {
        $class = $this->create(['assistant_ids' => [$this->rama->id, $this->dina->id], 'lab_confirmed' => true]);
        $report = SupervisionReport::factory()->create([
            'semester_id' => $this->semester->id, 'user_id' => $this->rama->id, 'outside_class_id' => $class->id,
            'date' => '2026-04-06', 'status' => 'verified',
        ]);
        $report->assistants()->sync([$this->rama->id => ['position' => 1], $this->dina->id => ['position' => 2]]);
        SupervisionReport::factory()->create(['semester_id' => $this->semester->id, 'user_id' => $this->rama->id, 'outside_class_id' => $class->id, 'date' => '2026-03-02'])
            ->assistants()->sync([$this->rama->id => ['position' => 1]]);

        $this->actingAs($this->manager)->get(route('asisten.outside-classes.index', ['month' => '2026-04']))->assertInertia(fn (Assert $page) => $page
            ->has('monthly', 1)
            ->where('monthly.0.date', '2026-04-06')
            ->where('monthly.0.class', 'Data Warehouse (KR)')
            ->where('monthly.0.originLab', 'Lab Sistem Informasi')
            ->where('monthly.0.assistants', ['Rama', 'Dina'])
            ->where('monthly.0.confirmed', true)
            ->where('monthly.0.reportStatus', 'verified'));

        $this->actingAs($this->dina)->get(route('asisten.outside-classes.index', ['month' => '2026-03']))->assertInertia(fn (Assert $page) => $page->has('monthly', 0));
    }

    public function test_a_bad_month_falls_back_to_the_current_one()
    {
        $this->actingAs($this->manager)->get(route('asisten.outside-classes.index', ['month' => '2026-13']))
            ->assertInertia(fn (Assert $page) => $page->where('filters.month', '2026-04'));
    }
}
