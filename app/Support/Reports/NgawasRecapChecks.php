<?php

namespace App\Support\Reports;

use App\Enums\ApprovalStatus;
use App\Models\LabClass;
use App\Models\OutsideClass;
use App\Models\Semester;
use App\Models\SupervisionReport;
use Carbon\CarbonImmutable;

/**
 * Pemeriksaan otomatis sebelum rekap ditetapkan Final (FR-RJN-07).
 * Temuan berlevel `block` menahan tombol Final; `warning` hanya peringatan.
 *
 * @phpstan-import-type Recap from NgawasRecap
 *
 * @phpstan-type Finding array{level: 'block'|'warning', code: string, title: string, items: list<string>, more: int}
 */
class NgawasRecapChecks
{
    /** Batas butir per temuan yang dikirim ke halaman. */
    private const MAX_ITEMS = 25;

    private const STATUS_LABEL = ['draft' => 'Draft', 'submitted' => 'Diajukan', 'returned' => 'Dikembalikan'];

    /**
     * @param  Recap  $recap  hasil NgawasRecap::build()
     * @return array{blocking: bool, findings: list<Finding>}
     */
    public static function run(Semester $semester, string $month, array $recap): array
    {
        $start = CarbonImmutable::createFromFormat('!Y-m', $month)->startOfMonth();
        $findings = [];

        // --- Blokir ---------------------------------------------------------
        $pending = SupervisionReport::query()
            ->where('semester_id', $semester->id)->where('status', '!=', 'verified')
            ->whereDateBetween('date', [$start->toDateString(), $start->endOfMonth()->toDateString()])
            ->with(['labClass.course', 'outsideClass', 'user:id,name'])->orderBy('date')->get();

        $findings[] = self::finding('block', 'unverified', 'Laporan belum diverifikasi', $pending->map(
            fn (SupervisionReport $r) => self::shortDate($r->date).' · '.self::className($r).' · '.(self::STATUS_LABEL[$r->status] ?? $r->status).' · '.$r->user->name
        )->all());

        $noCredits = array_map(
            fn (array $u) => self::shortDate(CarbonImmutable::parse($u['date'])).' · '.$u['activity'].' (laporan tanpa kelas, SKS tidak diketahui)',
            $recap['unplaced'],
        );

        foreach ($recap['assistants'] as $assistant) {
            foreach ($assistant['rows'] as $row) {
                if (! $row['placeholder'] && ($row['credits'] ?? 0) < 1 && $row['cells'] !== []) {
                    $noCredits[] = $row['label'].' · SKS kelas kosong';
                }
            }
        }

        $findings[] = self::finding('block', 'no_credits', 'SKS kelas kosong', $noCredits);

        $findings[] = self::finding('block', 'no_sk', 'Nomor SK semester belum diisi', blank($semester->sk_number) ? ["Semester {$semester->name}: isi di Admin › Semester"] : []);

        $findings[] = self::finding('block', 'duplicates', 'Duplikat asisten × kelas × tanggal', array_map(
            fn (array $d) => $d['name'].' · '.$d['class'].' · '.self::shortDate(CarbonImmutable::parse($d['date'])),
            $recap['duplicates'],
        ));

        // --- Peringatan -----------------------------------------------------
        $unsigned = [];
        $emptyRows = [];
        foreach ($recap['assistants'] as $assistant) {
            if (! $assistant['hasSignature']) {
                $unsigned[] = $assistant['name'];
            }

            foreach ($assistant['rows'] as $row) {
                if (! $row['placeholder'] && $row['cells'] === []) {
                    $emptyRows[] = $assistant['name'].' · '.$row['label'];
                }
            }
        }

        $findings[] = self::finding('warning', 'no_signature', 'Tanda tangan asisten belum diunggah (sel sheet absensi dikosongkan)', $unsigned);
        $findings[] = self::finding('warning', 'empty_rows', 'Baris kelas tanpa kehadiran sebulan penuh', $emptyRows);
        $findings[] = self::finding('warning', 'unconfirmed_outside', 'Kelas di luar Lab IR belum dikonfirmasi ke lab terkait', self::unconfirmedOutside($semester, $recap));

        $findings[] = self::finding('warning', 'missing_reports', 'Kelas terjadwal tanpa laporan (kemungkinan lupa lapor)', self::missingReports($semester, $start, $recap));

        $findings = array_values(array_filter($findings, fn (array $f) => $f['items'] !== [] || $f['more'] > 0));

        return [
            'blocking' => array_any($findings, fn (array $f) => $f['level'] === 'block'),
            'findings' => $findings,
        ];
    }

    /**
     * @param  'block'|'warning'  $level
     * @param  list<string>  $items
     * @return Finding
     */
    private static function finding(string $level, string $code, string $title, array $items): array
    {
        $items = array_values(array_unique($items));

        return [
            'level' => $level, 'code' => $code, 'title' => $title,
            'items' => array_slice($items, 0, self::MAX_ITEMS),
            'more' => max(0, count($items) - self::MAX_ITEMS),
        ];
    }

    /**
     * @param  Recap  $recap
     * @return list<string>
     */
    private static function unconfirmedOutside(Semester $semester, array $recap): array
    {
        $used = [];
        foreach ($recap['assistants'] as $assistant) {
            foreach ($assistant['rows'] as $row) {
                if ($row['outside']) {
                    $used[$row['key']] = true;
                }
            }
        }

        $items = [];
        foreach (OutsideClass::where('semester_id', $semester->id)->whereNull('lab_confirmed_at')->get() as $class) {
            if (isset($used['o'.$class->id])) {
                $items[] = "{$class->course_name} ({$class->class_name}) · {$class->origin_lab}";
            }
        }

        return $items;
    }

    /**
     * Tanggal jadwal kelas Lab IR yang tidak punya laporan apa pun (status berapa pun)
     * dan bukan tanggal asli kelas pengganti. Libur, Minggu, periode UTS/UAS, tanggal
     * di luar semester, dan tanggal setelah hari ini dilewati.
     *
     * @param  Recap  $recap
     * @return list<string>
     */
    private static function missingReports(Semester $semester, CarbonImmutable $start, array $recap): array
    {
        $end = $start->endOfMonth();

        $covered = [];
        SupervisionReport::query()
            ->where('semester_id', $semester->id)->whereNotNull('class_id')
            ->where(fn ($q) => $q->whereDateBetween('date', [$start->toDateString(), $end->toDateString()])
                ->orWhere(fn ($o) => $o->whereDateBetween('original_date', [$start->toDateString(), $end->toDateString()])))
            ->get(['class_id', 'date', 'original_date'])
            ->each(function (SupervisionReport $r) use (&$covered) {
                $covered[$r->class_id.'|'.$r->date->toDateString()] = true;
                if ($r->original_date !== null) {
                    $covered[$r->class_id.'|'.$r->original_date->toDateString()] = true;
                }
            });

        $skip = [];
        foreach ($recap['columns'] as $column) {
            if ($column['kind'] !== null) {
                $skip[$column['date']] = true;
            }
        }

        $items = [];

        $classes = LabClass::forSemester($semester)->where('approval_status', ApprovalStatus::Approved)->whereHas('assistants')
            ->with('course')->orderBy('day_of_week')->orderBy('start_time')->get();

        foreach ($classes as $class) {
            for ($date = $start; $date->lte($end); $date = $date->addDay()) {
                $key = $date->toDateString();

                if ($date->dayOfWeekIso !== $class->day_of_week || isset($skip[$key]) || $date->isFuture()
                    || $date->lt($semester->start_date) || $date->gt($semester->end_date) || isset($covered[$class->id.'|'.$key])) {
                    continue;
                }

                $items[] = self::shortDate($date).' · '.self::title($class);
            }
        }

        return $items;
    }

    private static function className(SupervisionReport $report): string
    {
        if ($report->labClass !== null) {
            return self::title($report->labClass);
        }

        return $report->outsideClass !== null ? "{$report->outsideClass->course_name} ({$report->outsideClass->class_name})" : $report->activity;
    }

    private static function title(LabClass $class): string
    {
        return "{$class->course->name} ({$class->name})";
    }

    private static function shortDate(CarbonImmutable $date): string
    {
        return $date->translatedFormat('j M Y');
    }
}
