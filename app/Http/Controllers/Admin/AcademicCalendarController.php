<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CalendarEventType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AcademicCalendarEventRequest;
use App\Models\AcademicCalendarEvent;
use App\Models\AuditLog;
use App\Models\Semester;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin › Semester › Kalender Akademik (FR-RJN-04, US-RJN-05): libur nasional
 * dan periode UTS/UAS yang mewarnai kolom tanggal rekap ngawas.
 */
class AcademicCalendarController extends Controller
{
    public function index(Semester $semester): Response
    {
        return Inertia::render('admin/semesters/calendar', [
            'semester' => [
                'id' => $semester->id,
                'name' => $semester->name,
                'startDate' => $semester->start_date->toDateString(),
                'endDate' => $semester->end_date->toDateString(),
                'editable' => $semester->isEditable(),
            ],
            'events' => $semester->calendarEvents()->orderBy('date_from')->orderBy('id')->get()->map(fn (AcademicCalendarEvent $e) => [
                'id' => $e->id,
                'dateFrom' => $e->date_from->toDateString(),
                'dateTo' => $e->date_to->toDateString(),
                'type' => $e->type->value,
                'label' => $e->label,
            ])->values(),
            'types' => array_map(fn (CalendarEventType $t) => ['value' => $t->value, 'label' => $t->label()], CalendarEventType::cases()),
        ]);
    }

    public function store(AcademicCalendarEventRequest $request, Semester $semester): RedirectResponse
    {
        $event = $semester->calendarEvents()->create([...$request->validated(), 'created_by' => $request->user()->id]);

        AuditLog::record('calendar_event.created', $event, null, $request->validated());
        Inertia::flash('toast', ['type' => 'success', 'message' => "{$event->type->label()} “{$event->label}” ditambahkan."]);

        return back();
    }

    public function update(AcademicCalendarEventRequest $request, AcademicCalendarEvent $event): RedirectResponse
    {
        $before = $event->only(['date_from', 'date_to', 'type', 'label']);
        $event->update($request->validated());

        AuditLog::record('calendar_event.updated', $event, $before, $request->validated());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Kalender diperbarui.']);

        return back();
    }

    public function destroy(AcademicCalendarEvent $event): RedirectResponse
    {
        abort_unless($event->semester->isEditable(), 403);

        AuditLog::record('calendar_event.deleted', $event, $event->only(['date_from', 'date_to', 'type', 'label']));
        $event->delete();
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Penanda kalender dihapus.']);

        return back();
    }
}
