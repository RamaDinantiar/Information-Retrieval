<?php

namespace App\Http\Controllers\Asisten;

use App\Http\Controllers\Controller;
use App\Http\Requests\Asisten\OutsideClassRequest;
use App\Models\AuditLog;
use App\Models\OutsideClass;
use App\Models\Semester;
use App\Models\SupervisionReport;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Asisten › Generate › Rekap Jam Ngawas › Kelas di Luar Lab (US-RJN-06, PRD 3.6).
 * Sekretaris/Bendahara dan Admin mengelola; Asisten Umum hanya melihat kelas
 * yang ditugaskan kepadanya. Data ini terpisah dari `courses`/`classes`.
 */
class OutsideClassController extends Controller
{
    private const AUDIT_FIELDS = ['course_name', 'class_name', 'credits', 'origin_lab', 'day_of_week', 'start_time', 'end_time', 'lab_confirmed_at', 'lab_confirmation_note'];

    public function index(Request $request): Response
    {
        $user = $request->user();
        $manager = $user->canManageNgawasRecap();

        $semesters = Semester::orderByDesc('start_date')->get();
        $semester = $semesters->firstWhere('id', (int) $request->query('semester')) ?? Semester::current() ?? $semesters->first();
        $month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $request->query('month')) ? (string) $request->query('month') : now()->format('Y-m');

        $classes = $semester === null ? collect() : OutsideClass::query()
            ->where('semester_id', $semester->id)
            ->when(! $manager, fn ($q) => $q->whereHas('assistants', fn ($a) => $a->whereKey($user->id)))
            ->with('assistants:id,name')->orderBy('course_name')->orderBy('class_name')->get();

        $start = CarbonImmutable::createFromFormat('Y-m', $month)->startOfMonth();

        $monthly = $classes->isEmpty() ? collect() : SupervisionReport::query()
            ->whereIn('outside_class_id', $classes->pluck('id'))
            ->whereDateBetween('date', [$start->toDateString(), $start->endOfMonth()->toDateString()])
            ->when(! $manager, fn ($q) => $q->whereHas('assistants', fn ($a) => $a->whereKey($user->id)))
            ->with(['assistants:id,name', 'outsideClass'])->orderBy('date')->orderBy('start_time')->get();

        return Inertia::render('asisten/outside-classes', [
            'filters' => ['semester' => $semester?->id, 'month' => $month],
            'semesters' => $semesters->map(fn (Semester $s) => ['id' => $s->id, 'name' => $s->name, 'active' => $s->status->value === 'active'])->values(),
            'canManage' => $manager && $semester?->isEditable() === true,
            'assistantOptions' => $manager ? User::where('role', 'asisten')->where('is_active', true)->orderBy('name')->get(['id', 'name']) : [],
            'classes' => $classes->map(fn (OutsideClass $c) => [
                'id' => $c->id,
                'courseName' => $c->course_name,
                'className' => $c->class_name,
                'credits' => $c->credits,
                'originLab' => $c->origin_lab,
                'dayOfWeek' => $c->day_of_week,
                'startTime' => $c->start_time ? substr($c->start_time, 0, 5) : null,
                'endTime' => $c->end_time ? substr($c->end_time, 0, 5) : null,
                'assistants' => $c->assistants->map(fn (User $a) => ['id' => $a->id, 'name' => $a->name])->values(),
                'confirmed' => $c->isConfirmed(),
                'confirmedAt' => $c->lab_confirmed_at?->toIso8601String(),
                'confirmationNote' => $c->lab_confirmation_note,
            ])->values(),
            'monthly' => $monthly->map(fn (SupervisionReport $r) => [
                'id' => $r->id,
                'date' => $r->date->toDateString(),
                'class' => $r->outsideClass ? "{$r->outsideClass->course_name} ({$r->outsideClass->class_name})" : '',
                'originLab' => $r->outsideClass->origin_lab ?? '',
                'assistants' => $r->assistants->pluck('name')->values(),
                'confirmed' => $r->outsideClass?->isConfirmed() ?? false,
                'reportStatus' => $r->status,
                'executionStatus' => $r->execution_status->value,
            ])->values(),
        ]);
    }

    public function store(OutsideClassRequest $request): RedirectResponse
    {
        $semester = Semester::findOrFail($request->integer('semester_id'));
        $class = $semester->outsideClasses()->make($this->attributes($request));
        $class->created_by = $request->user()->id;
        $class->save();
        $class->assistants()->sync($request->validated('assistant_ids'));

        AuditLog::record('outside_class.created', $class, null, $class->only(self::AUDIT_FIELDS) + ['assistant_ids' => $request->validated('assistant_ids')]);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Kelas {$class->course_name} ({$class->class_name}) ditambahkan."]);

        return back();
    }

    public function update(OutsideClassRequest $request, OutsideClass $outsideClass): RedirectResponse
    {
        $before = $outsideClass->only(self::AUDIT_FIELDS) + ['assistant_ids' => $outsideClass->assistants()->pluck('users.id')->sort()->values()->all()];

        $outsideClass->fill($this->attributes($request, $outsideClass))->save();
        $outsideClass->assistants()->sync($request->validated('assistant_ids'));

        AuditLog::record('outside_class.updated', $outsideClass, $before, $outsideClass->only(self::AUDIT_FIELDS) + ['assistant_ids' => $request->validated('assistant_ids')]);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Kelas di luar Lab IR diperbarui.']);

        return back();
    }

    public function destroy(Request $request, OutsideClass $outsideClass): RedirectResponse
    {
        abort_unless($request->user()->canManageNgawasRecap() && $outsideClass->semester->isEditable(), 403);

        if (SupervisionReport::where('outside_class_id', $outsideClass->id)->exists()) {
            throw ValidationException::withMessages(['outside_class' => 'Kelas ini sudah dipakai di laporan ngawas sehingga tidak dapat dihapus.']);
        }

        AuditLog::record('outside_class.deleted', $outsideClass, $outsideClass->only(self::AUDIT_FIELDS));
        $outsideClass->delete();
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Kelas di luar Lab IR dihapus.']);

        return back();
    }

    /**
     * Kolom model dari input; `lab_confirmed_at` terisi saat status berubah menjadi "Sudah dikonfirmasi"
     * dan dipertahankan selama tetap terkonfirmasi.
     *
     * @return array<string, mixed>
     */
    private function attributes(OutsideClassRequest $request, ?OutsideClass $existing = null): array
    {
        $data = $request->safe()->only(['course_name', 'class_name', 'credits', 'origin_lab', 'day_of_week', 'start_time', 'end_time', 'lab_confirmation_note']);

        return $data + [
            'lab_confirmed_at' => $request->boolean('lab_confirmed') ? ($existing->lab_confirmed_at ?? now()) : null,
        ];
    }
}
