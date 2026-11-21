<?php

namespace App\Actions\Report;

use App\Enums\CalendarEventType;
use App\Enums\ExecutionStatus;
use App\Events\SupervisionReportSubmitted;
use App\Models\AcademicCalendarEvent;
use App\Models\AuditLog;
use App\Models\LabClass;
use App\Models\OutsideClass;
use App\Models\Semester;
use App\Models\Shift;
use App\Models\SupervisionReport;
use App\Models\User;
use App\Support\Files\FileStore;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * US-A03 / FR-AR-01: buat atau ubah berita acara mengawas. `submit` = kirim
 * ke Sekretaris/Bendahara (Diajukan); tanpa itu tersimpan sebagai Draft.
 */
class SaveSupervisionReport
{
    public function __construct(private FileStore $files) {}

    /**
     * @param  array{shift_id?: int|null, date: string, start_time: string, end_time: string, class_id?: int|null, outside_class_id?: int|null, execution_status?: string, exam_type?: string|null, original_date?: string|null, original_start_time?: string|null, replacement_note?: string|null, held_outside_lab?: bool, outside_location?: string|null, activity: string, material?: string|null, notes: string, assistant_ids: list<int>, submit?: bool}  $data
     */
    public function __invoke(User $author, array $data, ?UploadedFile $photo = null, ?SupervisionReport $report = null): SupervisionReport
    {
        if ($report && ! $report->isEditableBy($author)) {
            throw ValidationException::withMessages(['report' => $report->isLocked()
                ? 'Laporan sudah terkunci (lewat H+1 pukul 23.59). Hubungi Admin untuk membuka kunci.'
                : 'Laporan ini tidak dapat diubah.']);
        }

        $date = CarbonImmutable::parse($data['date']);
        if ($date->isAfter(today())) {
            throw ValidationException::withMessages(['date' => 'Laporan hanya untuk shift hari ini atau sebelumnya.']);
        }

        $shift = isset($data['shift_id']) ? Shift::find($data['shift_id']) : null;
        if ($shift && $shift->user_id !== $author->id && ! $author->isAdmin()) {
            throw ValidationException::withMessages(['shift_id' => 'Shift ini bukan milik Anda.']);
        }

        if ($shift && SupervisionReport::where('shift_id', $shift->id)->when($report, fn ($q) => $q->whereKeyNot($report->id))->exists()) {
            throw ValidationException::withMessages(['shift_id' => 'Shift ini sudah memiliki laporan.']);
        }

        $team = array_values(array_unique(array_map('intval', $data['assistant_ids'])));
        if (! in_array($author->id, $team, true)) {
            throw ValidationException::withMessages(['assistant_ids' => 'Anda harus termasuk dalam tim asisten jaga.']);
        }

        $outsideId = isset($data['outside_class_id']) ? (int) $data['outside_class_id'] : null;
        $outside = $outsideId ? OutsideClass::find($outsideId) : null;

        if ($outsideId && isset($data['class_id'])) {
            throw ValidationException::withMessages(['class_id' => 'Pilih kelas Lab IR atau kelas di luar Lab IR, tidak keduanya.']);
        }

        $class = $outside ? null : (isset($data['class_id']) ? LabClass::find($data['class_id']) : $shift?->labClass);

        if ($class === null && $outside === null) {
            throw ValidationException::withMessages(['class_id' => 'Kelas wajib dipilih.']);
        }

        $semesterId = Semester::current()->id ?? $class->semester_id ?? $outside?->semester_id;
        $status = ExecutionStatus::from($data['execution_status'] ?? 'held');
        $extra = $this->validateExecution($author, $data, $status, $date, $class, $outside, $semesterId, $team, $report);

        return DB::transaction(function () use ($author, $data, $photo, $report, $date, $shift, $team, $class, $outside, $semesterId, $status, $extra) {
            $before = $report?->only(['status', 'execution_status', 'start_time', 'end_time', 'notes']);
            $submit = (bool) ($data['submit'] ?? false);

            $report ??= new SupervisionReport([
                'user_id' => $author->id,
                'semester_id' => $semesterId,
                'editable_until' => $date->addDay()->endOfDay(),
            ]);

            $report->fill([
                'shift_id' => $shift?->id,
                'date' => $date->toDateString(),
                'start_time' => $data['start_time'],
                'end_time' => $data['end_time'],
                'class_id' => $class?->id,
                'outside_class_id' => $outside?->id,
                'execution_status' => $status,
                ...$extra,
                'room_id' => $shift->room_id ?? $class?->room_id,
                'activity' => $data['activity'],
                'material' => $data['material'] ?? null,
                'notes' => $data['notes'],
            ]);

            if ($submit) {
                $report->status = 'submitted';
                $report->submitted_at = now();
                $report->return_note = null;
            } elseif (! $report->exists || $report->status === 'returned') {
                $report->status = $report->exists ? 'returned' : 'draft';
            }

            $report->save();
            $report->assistants()->sync(collect($team)->mapWithKeys(fn ($id, $i) => [$id => ['position' => $i + 1]])->all());

            if ($photo) {
                $report->photos()->get()->each->delete();
                $this->files->store($photo, ['image'], 'report_photo', $report, $author, 'photo');
            }

            AuditLog::record($before ? 'report.updated' : 'report.created', $report, $before, $report->only(['status', 'execution_status', 'start_time', 'end_time', 'notes']));

            if ($submit) {
                SupervisionReportSubmitted::dispatch($report);
            }

            return $report;
        });
    }

    /**
     * FR-RJN-02/03: aturan status pelaksanaan. Mengembalikan kolom turunan yang
     * sudah dibersihkan (field yang tidak relevan untuk status itu dikosongkan).
     *
     * @param  array<string, mixed>  $data
     * @param  list<int>  $team
     * @return array{exam_type: string|null, original_date: string|null, original_start_time: string|null, replacement_note: string|null, held_outside_lab: bool, outside_location: string|null}
     */
    private function validateExecution(User $author, array $data, ExecutionStatus $status, CarbonImmutable $date, ?LabClass $class, ?OutsideClass $outside, ?int $semesterId, array $team, ?SupervisionReport $report): array
    {
        if ($outside !== null) {
            $assigned = $outside->assistants()->whereKey($author->id)->exists();
            if (! $author->isAdmin() && ! $assigned) {
                throw ValidationException::withMessages(['outside_class_id' => 'Anda tidak ditugaskan pada kelas di luar Lab IR ini.']);
            }
            if ($semesterId !== null && $outside->semester_id !== $semesterId) {
                throw ValidationException::withMessages(['outside_class_id' => 'Kelas di luar Lab IR ini bukan milik semester aktif.']);
            }
        }

        $dayNames = [1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu'];
        $scheduledDay = $class->day_of_week ?? $outside?->day_of_week;

        if ($status === ExecutionStatus::Held) {
            if ($date->isSunday() || $this->isNationalHoliday($semesterId, $date)) {
                throw ValidationException::withMessages(['execution_status' => 'Tanggal ini hari Minggu atau libur nasional. Jika kelas tetap berjalan, ubah status menjadi Pengganti atau Tambahan.']);
            }

            if ($scheduledDay !== null && $date->dayOfWeekIso !== $scheduledDay) {
                throw ValidationException::withMessages(['execution_status' => "Kelas ini terjadwal hari {$dayNames[$scheduledDay]}. Jika ini kelas pengganti atau tambahan, ubah status pelaksanaan."]);
            }
        }

        $originalDate = null;
        if ($status === ExecutionStatus::Replacement) {
            if (empty($data['original_date'])) {
                throw ValidationException::withMessages(['original_date' => 'Tanggal kelas asli wajib diisi untuk kelas pengganti.']);
            }

            $originalDate = CarbonImmutable::parse($data['original_date']);
            if ($originalDate->isSameDay($date)) {
                throw ValidationException::withMessages(['original_date' => 'Tanggal kelas asli harus berbeda dari tanggal pelaksanaan.']);
            }
        }

        if ($status === ExecutionStatus::Exam && ! in_array($data['exam_type'] ?? null, ['uts', 'uas'], true)) {
            throw ValidationException::withMessages(['exam_type' => 'Pilih jenis ujian (UTS atau UAS).']);
        }

        $outsideLab = $outside === null && (bool) ($data['held_outside_lab'] ?? false);
        $location = $outsideLab ? trim((string) ($data['outside_location'] ?? '')) : '';
        if ($outsideLab && $location === '') {
            throw ValidationException::withMessages(['outside_location' => 'Lokasi pelaksanaan wajib diisi bila kelas dilaksanakan di luar ruang Lab IR.']);
        }

        if ($status !== ExecutionStatus::Cancelled) {
            $duplicate = SupervisionReport::query()
                ->where('execution_status', '!=', ExecutionStatus::Cancelled->value)
                ->whereDate('date', $date->toDateString())
                ->where(fn ($q) => $class ? $q->where('class_id', $class->id) : $q->where('outside_class_id', $outside?->id))
                ->when($report, fn ($q) => $q->whereKeyNot($report->id))
                ->whereHas('assistants', fn ($q) => $q->whereIn('users.id', $team))
                ->with(['assistants' => fn ($q) => $q->whereIn('users.id', $team)])
                ->first();

            if ($duplicate !== null) {
                $name = $duplicate->assistants->first()->name ?? 'Asisten';
                throw ValidationException::withMessages(['class_id' => "{$name} sudah tercatat pada laporan lain untuk kelas dan tanggal ini."]);
            }
        }

        return [
            'exam_type' => $status === ExecutionStatus::Exam ? $data['exam_type'] : null,
            'original_date' => $originalDate?->toDateString(),
            'original_start_time' => $originalDate !== null ? ($data['original_start_time'] ?? null) : null,
            'replacement_note' => $status === ExecutionStatus::Replacement ? ($data['replacement_note'] ?? null) : null,
            'held_outside_lab' => $outsideLab,
            'outside_location' => $outsideLab ? $location : null,
        ];
    }

    private function isNationalHoliday(?int $semesterId, CarbonImmutable $date): bool
    {
        return $semesterId !== null && AcademicCalendarEvent::query()
            ->where('semester_id', $semesterId)
            ->where('type', CalendarEventType::NationalHoliday)
            ->whereDate('date_from', '<=', $date->toDateString())
            ->whereDate('date_to', '>=', $date->toDateString())
            ->exists();
    }
}
