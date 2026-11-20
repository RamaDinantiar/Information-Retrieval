<?php

namespace Tests\Feature\Admin;

use App\Models\AcademicCalendarEvent;
use App\Models\AuditLog;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** FR-RJN-04 & US-RJN-05: kalender akademik, nomor SK, dan label semester. */
class AcademicCalendarTest extends TestCase
{
    use RefreshDatabase;

    private Semester $semester;

    protected function setUp(): void
    {
        parent::setUp();
        $this->semester = Semester::factory()->active()->create(['name' => 'Genap 2025/2026', 'start_date' => '2026-02-16', 'end_date' => '2026-06-19']);
        $this->actingAs(User::factory()->admin()->create());
    }

    private function payload(array $overrides = []): array
    {
        return ['date_from' => '2026-04-03', 'date_to' => '2026-04-03', 'type' => 'national_holiday', 'label' => 'Wafat Isa Almasih', ...$overrides];
    }

    public function test_the_calendar_page_lists_events_in_date_order()
    {
        $this->semester->calendarEvents()->createMany([
            ['date_from' => '2026-05-01', 'date_to' => '2026-05-01', 'type' => 'national_holiday', 'label' => 'Hari Buruh'],
            ['date_from' => '2026-04-03', 'date_to' => '2026-04-03', 'type' => 'national_holiday', 'label' => 'Wafat Isa Almasih'],
        ]);

        $this->get(route('admin.semesters.calendar', $this->semester))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('admin/semesters/calendar')
            ->where('semester.editable', true)
            ->where('events.0.label', 'Wafat Isa Almasih')
            ->where('events.1.label', 'Hari Buruh')
            ->has('types', 3));
    }

    public function test_admin_creates_updates_and_deletes_events_with_audit_trail()
    {
        $this->post(route('admin.calendar-events.store', $this->semester), $this->payload())->assertSessionHasNoErrors();

        $event = AcademicCalendarEvent::sole();
        $this->assertSame($this->semester->id, $event->semester_id);
        $this->assertTrue(AuditLog::where('action', 'calendar_event.created')->exists());

        $this->put(route('admin.calendar-events.update', $event), $this->payload(['type' => 'uts', 'date_from' => '2026-04-20', 'date_to' => '2026-04-24', 'label' => 'UTS']))->assertSessionHasNoErrors();

        $this->assertSame('uts', $event->fresh()->type->value);
        $this->assertSame('2026-04-24', $event->fresh()->date_to->toDateString());
        $this->assertTrue(AuditLog::where('action', 'calendar_event.updated')->exists());

        $this->delete(route('admin.calendar-events.destroy', $event))->assertRedirect();

        $this->assertSame(0, AcademicCalendarEvent::count());
        $this->assertTrue(AuditLog::where('action', 'calendar_event.deleted')->exists());
    }

    public function test_validation_rejects_bad_ranges_types_and_dates_outside_the_semester()
    {
        $this->post(route('admin.calendar-events.store', $this->semester), $this->payload(['date_to' => '2026-04-01']))->assertSessionHasErrors('date_to');
        $this->post(route('admin.calendar-events.store', $this->semester), $this->payload(['type' => 'ujian']))->assertSessionHasErrors('type');
        $this->post(route('admin.calendar-events.store', $this->semester), $this->payload(['label' => '']))->assertSessionHasErrors('label');
        $this->post(route('admin.calendar-events.store', $this->semester), $this->payload(['date_from' => '2026-02-10', 'date_to' => '2026-02-10']))->assertSessionHasErrors('date_from');
        $this->post(route('admin.calendar-events.store', $this->semester), $this->payload(['date_from' => '2026-06-18', 'date_to' => '2026-06-25']))->assertSessionHasErrors('date_from');

        $this->assertSame(0, AcademicCalendarEvent::count());
    }

    public function test_dates_on_the_semester_boundaries_are_accepted()
    {
        $this->post(route('admin.calendar-events.store', $this->semester), $this->payload(['date_from' => '2026-02-16', 'date_to' => '2026-02-16']))->assertSessionHasNoErrors();
        $this->post(route('admin.calendar-events.store', $this->semester), $this->payload(['date_from' => '2026-06-19', 'date_to' => '2026-06-19']))->assertSessionHasNoErrors();
    }

    public function test_updates_are_validated_against_the_events_own_semester()
    {
        $event = $this->semester->calendarEvents()->create($this->payload());

        $this->put(route('admin.calendar-events.update', $event), $this->payload(['date_from' => '2027-01-01', 'date_to' => '2027-01-01']))->assertSessionHasErrors('date_from');
    }

    public function test_archived_semesters_are_read_only()
    {
        $archived = Semester::factory()->archived()->create(['start_date' => '2025-08-01', 'end_date' => '2025-12-20']);
        $event = $archived->calendarEvents()->create($this->payload(['date_from' => '2025-09-01', 'date_to' => '2025-09-01']));

        $this->get(route('admin.semesters.calendar', $archived))->assertInertia(fn (Assert $page) => $page->where('semester.editable', false));
        $this->post(route('admin.calendar-events.store', $archived), $this->payload(['date_from' => '2025-10-01', 'date_to' => '2025-10-01']))->assertForbidden();
        $this->put(route('admin.calendar-events.update', $event), $this->payload(['date_from' => '2025-09-02', 'date_to' => '2025-09-02']))->assertForbidden();
        $this->delete(route('admin.calendar-events.destroy', $event))->assertForbidden();
    }

    public function test_only_admin_can_manage_the_calendar()
    {
        $event = $this->semester->calendarEvents()->create($this->payload());

        foreach ([User::factory()->asisten()->create(), User::factory()->create()] as $user) {
            $this->actingAs($user);
            $this->get(route('admin.semesters.calendar', $this->semester))->assertForbidden();
            $this->post(route('admin.calendar-events.store', $this->semester), $this->payload())->assertForbidden();
            $this->put(route('admin.calendar-events.update', $event), $this->payload())->assertForbidden();
            $this->delete(route('admin.calendar-events.destroy', $event))->assertForbidden();
        }
    }

    public function test_semester_sk_number_and_term_label_are_saved_and_audited()
    {
        $this->put(route('admin.semesters.update', $this->semester), [
            'name' => 'Genap 2025/2026', 'start_date' => '2026-02-16', 'end_date' => '2026-06-19',
            'sk_number' => '03/SK/Genap/IRLab/2026', 'term_label' => 'SEMESTER GENAP TAHUN 2025/2026',
        ])->assertSessionHasNoErrors();

        $fresh = $this->semester->fresh();
        $this->assertSame('03/SK/Genap/IRLab/2026', $fresh->sk_number);
        $this->assertSame('SEMESTER GENAP TAHUN 2025/2026', $fresh->term_label);
        $this->assertSame('03/SK/Genap/IRLab/2026', AuditLog::where('action', 'semester.updated')->latest('id')->first()->after_json['sk_number']);

        $this->get(route('admin.semesters.index'))->assertInertia(fn (Assert $page) => $page
            ->where('semesters.0.skNumber', '03/SK/Genap/IRLab/2026')->where('semesters.0.termLabel', 'SEMESTER GENAP TAHUN 2025/2026'));
    }

    public function test_sk_number_and_term_label_are_optional_and_length_limited()
    {
        $base = ['name' => 'Genap 2025/2026', 'start_date' => '2026-02-16', 'end_date' => '2026-06-19'];

        $this->put(route('admin.semesters.update', $this->semester), $base)->assertSessionHasNoErrors();
        $this->put(route('admin.semesters.update', $this->semester), [...$base, 'sk_number' => str_repeat('A', 81)])->assertSessionHasErrors('sk_number');
        $this->put(route('admin.semesters.update', $this->semester), [...$base, 'term_label' => str_repeat('A', 81)])->assertSessionHasErrors('term_label');
    }
}
