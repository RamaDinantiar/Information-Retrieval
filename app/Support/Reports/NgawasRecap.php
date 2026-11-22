<?php

namespace App\Support\Reports;

use App\Enums\ApprovalStatus;
use App\Enums\CalendarEventType;
use App\Enums\ExecutionStatus;
use App\Models\LabClass;
use App\Models\NgawasRecapOrder;
use App\Models\OutsideClass;
use App\Models\Semester;
use App\Models\SupervisionReport;
use App\Models\User;
use App\Support\NimFormatter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Mesin Rekap Jam Ngawas (FR-RJN-05/06). Menghasilkan struktur netral yang
 * dipakai pratinjau web dan ekspor Excel kampus, sehingga keduanya tidak
 * mungkin berbeda angka.
 *
 * Aturan inti (PRD 3):
 *  - hanya laporan Diverifikasi; setiap anggota tim jaga mendapat sel sendiri;
 *  - jam sel = SKS × 50 ÷ 60 (bukan jam mulai–selesai), 0 untuk Ditiadakan;
 *  - total jam per asisten dibulatkan setengah ke atas (ROUND(…,0) Excel);
 *    total keseluruhan = jumlah total yang sudah dibulatkan (ROUND(SUM(AJ…))).
 *
 * @phpstan-type Cell array{day: int, date: string, status: string, kind: string|null, credits: int|null, hours: float, counts: bool, outsideLab: bool, reportIds: list<int>}
 * @phpstan-type Row array{key: string, label: string, credits: int|null, outside: bool, placeholder: bool, cells: array<int, Cell>, totalDays: int, hours: float}
 * @phpstan-type Recap array{month: string, monthLabel: string, daysInMonth: int, semester: array{id: int, name: string, termLabel: string|null, skNumber: string|null}, columns: list<array{day: int, date: string, weekday: string, kind: string|null, label: string|null}>, assistants: list<Assistant>, totals: array{assistants: int, days: int, hoursRaw: float, hours: int}, additional: list<array{date: string, class: string, time: string, note: string}>, replacements: list<array{date: string, class: string, time: string, note: string}>, reports: array<int, array{id: int, date: string, start: string, end: string, class: string, status: string, executionStatus: string, team: list<string>}>, duplicates: list<array{name: string, class: string, date: string, reportIds: list<int>}>, unplaced: list<array{id: int, date: string, activity: string}>}
 * @phpstan-type Assistant array{userId: int, name: string, nim: string, hasSignature: bool, rows: list<Row>, totalDays: int, hoursRaw: float, hours: int}
 */
class NgawasRecap
{
    /** Penanda warna sel, urut prioritas tertinggi → terendah (PRD 3.6). */
    public const KINDS = ['cancelled', 'exam', 'outside', 'replacement', 'additional'];

    private const MAX_DAY = 99;

    /**
     * @return Recap
     */
    public static function build(Semester $semester, string $month, ?int $onlyUserId = null): array
    {
        $start = CarbonImmutable::createFromFormat('!Y-m', $month)->startOfMonth();
        $end = $start->endOfMonth();
        $days = $start->daysInMonth;

        $columns = self::columns($semester, $start, $days);

        $reports = SupervisionReport::query()
            ->where('semester_id', $semester->id)
            ->where('status', 'verified')
            ->whereDateBetween('date', [$start->toDateString(), $end->toDateString()])
            ->with(['labClass.course', 'outsideClass', 'assistants:id,name'])
            ->orderBy('date')->orderBy('start_time')->orderBy('id')
            ->get();

        $labClasses = LabClass::forSemester($semester)->where('approval_status', ApprovalStatus::Approved)
            ->with(['course', 'assistants:id'])->get();
        $outsideClasses = OutsideClass::where('semester_id', $semester->id)->with('assistants:id')->get();

        $users = self::users($reports, $onlyUserId);

        /** @var array<int, array<string, array<string, mixed>>> $rows */
        $rows = [];

        foreach ($labClasses as $class) {
            foreach ($class->assistants as $assistant) {
                self::ensureRow($rows, $assistant->id, ...self::describeLab($class));
            }
        }

        foreach ($outsideClasses as $class) {
            foreach ($class->assistants as $assistant) {
                self::ensureRow($rows, $assistant->id, ...self::describeOutside($class));
            }
        }

        $duplicates = [];
        $unplaced = [];
        $reportIndex = [];
        $additional = [];
        $replacements = [];

        foreach ($reports as $report) {
            // Asisten Umum hanya boleh melihat laporan yang melibatkan dirinya (nama tim, tabel tambahan/pengganti).
            $involved = $onlyUserId === null || $report->assistants->contains('id', $onlyUserId);
            $descriptor = self::describeReport($report);

            if ($descriptor === null) {
                if ($involved) {
                    $unplaced[] = ['id' => $report->id, 'date' => $report->date->toDateString(), 'activity' => $report->activity];
                }

                continue;
            }

            [$key, $label, $credits, $outside, $sortDay, $sortTime, $title] = $descriptor;
            $status = $report->execution_status;
            $outsideLab = $report->outside_class_id !== null || $report->held_outside_lab;

            if ($involved) {
                $reportIndex[$report->id] = [
                    'id' => $report->id,
                    'date' => $report->date->toDateString(),
                    'start' => substr($report->start_time, 0, 5),
                    'end' => substr($report->end_time, 0, 5),
                    'class' => $title,
                    'status' => $report->status,
                    'executionStatus' => $status->value,
                    'team' => $report->assistants->pluck('name')->values()->all(),
                ];
            }

            $time = str_replace(':', '.', substr($report->start_time, 0, 5)).' - '.str_replace(':', '.', substr($report->end_time, 0, 5));

            if ($involved && $status === ExecutionStatus::Additional) {
                $additional[] = ['date' => $report->date->toDateString(), 'class' => $title, 'time' => $time, 'note' => (string) $report->replacement_note];
            }

            if ($involved && $status === ExecutionStatus::Replacement) {
                $replacements[] = ['date' => $report->date->toDateString(), 'class' => $title, 'time' => $time, 'note' => self::replacementNote($report)];
            }

            $kind = match (true) {
                $status === ExecutionStatus::Cancelled => 'cancelled',
                $status === ExecutionStatus::Exam => 'exam',
                $outsideLab => 'outside',
                $status === ExecutionStatus::Replacement => 'replacement',
                $status === ExecutionStatus::Additional => 'additional',
                default => null,
            };

            foreach ($report->assistants as $member) {
                if ($onlyUserId !== null && $member->id !== $onlyUserId) {
                    continue;
                }

                self::ensureRow($rows, $member->id, $key, $label, $credits, $outside, $sortDay, $sortTime);
                $day = $report->date->day;

                if (isset($rows[$member->id][$key]['cells'][$day])) {
                    $existing = $rows[$member->id][$key]['cells'][$day];
                    $duplicates[] = [
                        'name' => $member->name, 'class' => $label, 'date' => $report->date->toDateString(),
                        'reportIds' => array_values([...$existing['reportIds'], $report->id]),
                    ];

                    continue;
                }

                $rows[$member->id][$key]['cells'][$day] = [
                    'day' => $day,
                    'date' => $report->date->toDateString(),
                    'status' => $status->value,
                    'kind' => $kind,
                    'credits' => $credits,
                    'hours' => $report->recapHours(),
                    'counts' => $status->countsHours(),
                    'outsideLab' => $outsideLab,
                    'reportIds' => [$report->id],
                ];
            }
        }

        $assistants = self::assemble($semester, $users, $rows, $onlyUserId);

        $totalHours = array_sum(array_column($assistants, 'hours'));

        return [
            'month' => $month,
            'monthLabel' => mb_strtoupper($start->translatedFormat('F Y')),
            'daysInMonth' => $days,
            'semester' => ['id' => $semester->id, 'name' => $semester->name, 'termLabel' => $semester->term_label, 'skNumber' => $semester->sk_number],
            'columns' => $columns,
            'assistants' => $assistants,
            'totals' => [
                'assistants' => count($assistants),
                'days' => array_sum(array_column($assistants, 'totalDays')),
                'hoursRaw' => array_sum(array_column($assistants, 'hoursRaw')),
                'hours' => $totalHours,
            ],
            'additional' => $additional,
            'replacements' => $replacements,
            'reports' => $reportIndex,
            'duplicates' => $duplicates,
            'unplaced' => $unplaced,
        ];
    }

    /**
     * Sidik jari isi rekap untuk mendeteksi perubahan data sejak Final (audit 08 W10).
     * Tidak bergantung pada urutan kunci objek: kolom JSON MySQL menyusun ulang kunci
     * (yang pendek lebih dulu), sedangkan SQLite menyimpan teks apa adanya, sehingga
     * membandingkan json_encode mentah selalu "berubah" di MySQL. Larik berindeks
     * (daftar) tetap dibandingkan menurut urutannya.
     *
     * @param  array<string, mixed>  $recap
     */
    public static function fingerprint(array $recap): string
    {
        $normalize = function (mixed $value) use (&$normalize): mixed {
            if (! is_array($value)) {
                return $value;
            }

            if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map($normalize, $value);
        };

        // Lewat json dulu agar sisi hidup dan sisi tersimpan memakai representasi tipe yang sama.
        $roundTripped = json_decode((string) json_encode($recap), true);

        return md5((string) json_encode($normalize($roundTripped)));
    }

    /** ROUND(x,0) Excel: setengah menjauhi nol; selisih float ±1e-9 diabaikan. */
    public static function roundHours(float $hours): int
    {
        return (int) round(round($hours, 9), 0, PHP_ROUND_HALF_UP);
    }

    /**
     * Penanda kolom: libur nasional > Minggu > UTS/UAS (PRD 3.4).
     *
     * @return list<array{day: int, date: string, weekday: string, kind: string|null, label: string|null}>
     */
    private static function columns(Semester $semester, CarbonImmutable $start, int $days): array
    {
        $events = $semester->calendarEvents()
            ->whereDate('date_from', '<=', $start->endOfMonth()->toDateString())
            ->whereDate('date_to', '>=', $start->toDateString())
            ->get();

        $columns = [];

        for ($day = 1; $day <= $days; $day++) {
            $date = $start->addDays($day - 1);
            $covering = $events->filter(fn ($e) => $e->date_from->lte($date) && $e->date_to->gte($date));
            $holiday = $covering->firstWhere('type', CalendarEventType::NationalHoliday);
            $exam = $covering->first(fn ($e) => $e->type !== CalendarEventType::NationalHoliday);

            [$kind, $label] = match (true) {
                $holiday !== null => ['holiday', $holiday->label],
                $date->isSunday() => ['sunday', 'Minggu'],
                $exam !== null => ['exam', $exam->label],
                default => [null, null],
            };

            $columns[] = ['day' => $day, 'date' => $date->toDateString(), 'weekday' => $date->translatedFormat('D'), 'kind' => $kind, 'label' => $label];
        }

        return $columns;
    }

    /**
     * Semua asisten aktif ditambah siapa pun yang tercatat di laporan terverifikasi.
     *
     * @param  Collection<int, SupervisionReport>  $reports
     * @return Collection<int, User>
     */
    private static function users(Collection $reports, ?int $onlyUserId): Collection
    {
        $inReports = $reports->flatMap(fn (SupervisionReport $r) => $r->assistants->pluck('id'))->unique()->values()->all();

        return User::query()
            ->where(fn ($q) => $q->where(fn ($a) => $a->where('role', 'asisten')->where('is_active', true))->orWhereIn('id', $inReports))
            ->when($onlyUserId !== null, fn ($q) => $q->whereKey($onlyUserId))
            ->get(['id', 'name', 'nim', 'signature_file_id']);
    }

    /**
     * @param  array<int, array<string, array<string, mixed>>>  $rows
     */
    private static function ensureRow(array &$rows, int $userId, string $key, string $label, ?int $credits, bool $outside, int $sortDay, string $sortTime): void
    {
        $rows[$userId][$key] ??= [
            'key' => $key, 'label' => $label, 'credits' => $credits, 'outside' => $outside, 'placeholder' => false,
            'sortDay' => $sortDay, 'sortTime' => $sortTime, 'cells' => [],
        ];
    }

    /**
     * @return array{0: string, 1: string, 2: int|null, 3: bool, 4: int, 5: string}
     */
    private static function describeLab(LabClass $class): array
    {
        return ['c'.$class->id, self::label($class->course->name, $class->name), $class->course->credits, false,
            $class->day_of_week, substr($class->start_time, 0, 5)];
    }

    /**
     * @return array{0: string, 1: string, 2: int|null, 3: bool, 4: int, 5: string}
     */
    private static function describeOutside(OutsideClass $class): array
    {
        return ['o'.$class->id, self::label($class->course_name, $class->class_name), $class->credits, true,
            $class->day_of_week ?? self::MAX_DAY, $class->start_time ? substr($class->start_time, 0, 5) : '99:99'];
    }

    /**
     * @return array{0: string, 1: string, 2: int|null, 3: bool, 4: int, 5: string, 6: string}|null
     */
    private static function describeReport(SupervisionReport $report): ?array
    {
        if ($report->outsideClass !== null) {
            $class = $report->outsideClass;

            return [...self::describeOutside($class), self::title($class->course_name, $class->class_name)];
        }

        if ($report->labClass !== null) {
            $class = $report->labClass;

            return [...self::describeLab($class), self::title($class->course->name, $class->name)];
        }

        return null;
    }

    /** "Kelas A" → "A"; "KR" tetap "KR". */
    private static function suffix(string $className): string
    {
        return mb_strtoupper(trim((string) preg_replace('/^kelas\s+/i', '', trim($className))));
    }

    /** Label baris seperti file kampus: `DATA WAREHOUSE (E)`. */
    private static function label(string $course, string $className): string
    {
        return mb_strtoupper(trim($course)).' ('.self::suffix($className).')';
    }

    /** Nama kelas untuk tabel catatan: `Teknologi Blockchain C`. */
    private static function title(string $course, string $className): string
    {
        return trim(mb_convert_case(mb_strtolower(trim($course)), MB_CASE_TITLE).' '.self::suffix($className));
    }

    /** "Pengganti dari Kelas Senin, 27 April 2026" (+ ", <keterangan>" bila diisi). */
    private static function replacementNote(SupervisionReport $report): string
    {
        $note = 'Pengganti';

        if ($report->original_date !== null) {
            $note .= ' dari Kelas '.$report->original_date->translatedFormat('l, j F Y');
        }

        $extra = trim((string) $report->replacement_note);

        return $extra === '' ? $note : $note.', '.$extra;
    }

    /**
     * Urutkan asisten (pengaturan Urutan Rekap, default nama) dan baris kelas,
     * lalu hitung total.
     *
     * @param  Collection<int, User>  $users
     * @param  array<int, array<string, array<string, mixed>>>  $rows
     * @return list<Assistant>
     */
    private static function assemble(Semester $semester, Collection $users, array $rows, ?int $onlyUserId): array
    {
        $orders = NgawasRecapOrder::where('semester_id', $semester->id)->get()->keyBy('user_id');

        $sorted = $users->sort(function (User $a, User $b) use ($orders) {
            $pa = $orders[$a->id]->position ?? PHP_INT_MAX;
            $pb = $orders[$b->id]->position ?? PHP_INT_MAX;

            return $pa <=> $pb ?: strcasecmp($a->name, $b->name);
        });

        $result = [];

        foreach ($sorted as $user) {
            $userRows = array_values($rows[$user->id] ?? []);
            $classOrder = array_flip($orders[$user->id]->class_order_json ?? []);

            usort($userRows, fn (array $a, array $b) => [$classOrder[$a['key']] ?? PHP_INT_MAX, $a['sortDay'], $a['sortTime'], $a['label']]
                <=> [$classOrder[$b['key']] ?? PHP_INT_MAX, $b['sortDay'], $b['sortTime'], $b['label']]);

            if ($userRows === []) {
                // Asisten tanpa kelas: satu baris kosong dengan MATA KULIAH "-" (P8).
                $userRows = [['key' => '-', 'label' => '-', 'credits' => null, 'outside' => false, 'placeholder' => true, 'cells' => []]];
            }

            $final = [];
            $hoursRaw = 0.0;
            $totalDays = 0;

            foreach ($userRows as $row) {
                ksort($row['cells']);
                $rowHours = array_sum(array_column($row['cells'], 'hours'));
                $rowDays = count(array_filter($row['cells'], fn (array $c) => $c['counts']));
                $hoursRaw += $rowHours;
                $totalDays += $rowDays;

                $final[] = [
                    'key' => $row['key'], 'label' => $row['label'], 'credits' => $row['credits'],
                    'outside' => $row['outside'], 'placeholder' => $row['placeholder'],
                    'cells' => $row['cells'], 'totalDays' => $rowDays, 'hours' => $rowHours,
                ];
            }

            $result[] = [
                'userId' => $user->id,
                'name' => mb_strtoupper($user->name),
                'nim' => NimFormatter::format($user->nim),
                'hasSignature' => $user->signature_file_id !== null,
                'rows' => $final,
                'totalDays' => $totalDays,
                'hoursRaw' => $hoursRaw,
                'hours' => self::roundHours($hoursRaw),
            ];
        }

        return $result;
    }
}
