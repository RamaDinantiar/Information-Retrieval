<?php

namespace App\Support\Reports;

use App\Models\SupervisionReport;
use Carbon\CarbonImmutable;

/**
 * Rekap jam mengawas bulanan per asisten, dirinci per mata kuliah (jam + SKS
 * mata kuliah). Hanya laporan Diverifikasi; setiap anggota tim jaga mendapat
 * jam laporan tersebut. Tidak ada perhitungan uang: tarif per jam bukan urusan
 * sistem ini.
 */
class HoursRecap
{
    /**
     * Setiap baris: userId, name, nim, shifts, hours, courses[{name, code, credits, shifts, hours}].
     *
     * @return list<array<string, mixed>>
     */
    public static function forMonth(string $month, ?int $onlyUserId = null): array
    {
        $start = CarbonImmutable::createFromFormat('Y-m', $month)->startOfMonth();

        $reports = SupervisionReport::where('status', 'verified')
            ->whereDateBetween('date', [$start->toDateString(), $start->endOfMonth()->toDateString()])
            ->with(['assistants:id,name,nim', 'labClass.course'])
            ->get();

        /** @var array<int, array<string, mixed>> $perUser */
        $perUser = [];

        foreach ($reports as $report) {
            $labClass = $report->labClass;
            $course = $labClass === null ? null : $labClass->course;
            $courseKey = $course === null ? 'other' : 'c'.$course->id;

            foreach ($report->assistants as $assistant) {
                if ($onlyUserId !== null && $assistant->id !== $onlyUserId) {
                    continue;
                }

                $row = &$perUser[$assistant->id];
                $row['userId'] = $assistant->id;
                $row['name'] = $assistant->name;
                $row['nim'] = $assistant->nim;
                $row['shifts'] = ($row['shifts'] ?? 0) + 1;
                $row['hours'] = ($row['hours'] ?? 0) + $report->hours();

                $c = &$row['courses'][$courseKey];
                $c['name'] = $course === null ? 'Kegiatan lain' : $course->name;
                $c['code'] = $course === null ? null : $course->code;
                $c['credits'] = $course === null ? null : $course->credits;
                $c['shifts'] = ($c['shifts'] ?? 0) + 1;
                $c['hours'] = round(($c['hours'] ?? 0) + $report->hours(), 2);
                unset($row, $c);
            }
        }

        $rows = [];
        foreach ($perUser as $row) {
            $courses = array_values($row['courses']);
            usort($courses, fn (array $a, array $b) => strcmp($a['name'], $b['name']));
            $rows[] = [
                'userId' => $row['userId'],
                'name' => $row['name'],
                'nim' => $row['nim'],
                'shifts' => $row['shifts'],
                'hours' => round($row['hours'], 2),
                'courses' => $courses,
            ];
        }

        usort($rows, fn (array $a, array $b) => strcmp((string) ($a['nim'] ?? $a['name']), (string) ($b['nim'] ?? $b['name'])));

        return $rows;
    }
}
