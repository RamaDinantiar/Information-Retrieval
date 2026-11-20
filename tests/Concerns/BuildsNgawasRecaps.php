<?php

namespace Tests\Concerns;

use App\Support\Reports\NgawasRecap;
use Carbon\CarbonImmutable;
use GdImage;

/**
 * Pembuat struktur rekap sintetis (tanpa database) dan gambar tanda tangan
 * untuk menguji pengisi workbook pada berbagai jumlah hari dan baris.
 */
trait BuildsNgawasRecaps
{
    /**
     * @param  array<int, int>  $rowsPerAssistant  jumlah baris kelas per asisten (indeks = urutan asisten)
     * @param  array<int, array{kind?: string, status?: string, outsideLab?: bool}>  $special  `"asisten.baris.hari"` => penanda sel khusus
     * @param  list<array{date: string, class: string, time: string, note: string}>  $additional
     * @param  list<array{date: string, class: string, time: string, note: string}>  $replacements
     * @return array<string, mixed>
     */
    private function fakeRecap(string $month = '2026-04', array $rowsPerAssistant = [2, 3], array $special = [], array $additional = [], array $replacements = [], array $signedUsers = []): array
    {
        $start = CarbonImmutable::createFromFormat('Y-m', $month)->startOfMonth();
        $days = $start->daysInMonth;

        $columns = [];
        for ($d = 1; $d <= $days; $d++) {
            $date = $start->addDays($d - 1);
            $columns[] = [
                'day' => $d, 'date' => $date->toDateString(), 'weekday' => $date->translatedFormat('D'),
                'kind' => $date->isSunday() ? 'sunday' : ($d === 3 ? 'holiday' : null), 'label' => null,
            ];
        }

        $assistants = [];
        $totalDays = 0;
        $totalHours = 0;
        $totalRaw = 0.0;

        foreach ($rowsPerAssistant as $a => $rowCount) {
            $rows = [];
            $raw = 0.0;
            $daysSum = 0;

            for ($r = 0; $r < $rowCount; $r++) {
                $credits = $r % 2 === 0 ? 2 : 3;
                $cells = [];

                foreach ([6 + $r, 13 + $r, 20 + $r] as $day) {
                    if ($day > $days || $columns[$day - 1]['kind'] !== null) {
                        continue;
                    }

                    $extra = $special["{$a}.{$r}.{$day}"] ?? [];
                    $status = $extra['status'] ?? 'held';
                    $counts = $status !== 'cancelled';

                    $cells[$day] = [
                        'day' => $day, 'date' => $columns[$day - 1]['date'], 'status' => $status, 'kind' => $extra['kind'] ?? null,
                        'credits' => $credits, 'hours' => $counts ? $credits * 50 / 60 : 0.0, 'counts' => $counts,
                        'outsideLab' => $extra['outsideLab'] ?? false, 'reportIds' => [1],
                    ];
                }

                $rowHours = array_sum(array_column($cells, 'hours'));
                $rowDays = count(array_filter($cells, fn (array $c) => $c['counts']));
                $raw += $rowHours;
                $daysSum += $rowDays;

                $rows[] = [
                    'key' => 'c'.($a * 10 + $r), 'label' => "MATA KULIAH {$a}{$r} (".chr(65 + $r).')', 'credits' => $credits,
                    'outside' => false, 'placeholder' => false, 'cells' => $cells, 'totalDays' => $rowDays, 'hours' => $rowHours,
                ];
            }

            $hours = NgawasRecap::roundHours($raw);
            $assistants[] = [
                'userId' => $a + 1, 'name' => 'ASISTEN '.($a + 1), 'nim' => sprintf('2023 – 31 – %03d', $a + 1),
                'hasSignature' => in_array($a + 1, $signedUsers, true), 'rows' => $rows,
                'totalDays' => $daysSum, 'hoursRaw' => $raw, 'hours' => $hours,
            ];
            $totalDays += $daysSum;
            $totalHours += $hours;
            $totalRaw += $raw;
        }

        return [
            'month' => $month, 'monthLabel' => mb_strtoupper($start->translatedFormat('F Y')), 'daysInMonth' => $days,
            'semester' => ['id' => 1, 'name' => 'Genap 2025/2026', 'termLabel' => 'SEMESTER GENAP TAHUN 2025/2026', 'skNumber' => '03/SK/Genap/IRLab/2026'],
            'columns' => $columns, 'assistants' => $assistants,
            'totals' => ['assistants' => count($assistants), 'days' => $totalDays, 'hoursRaw' => $totalRaw, 'hours' => $totalHours],
            'additional' => $additional, 'replacements' => $replacements, 'reports' => [], 'duplicates' => [], 'unplaced' => [],
        ];
    }

    /** PNG transparan sederhana (coretan) di file sementara. */
    private function signaturePng(int $w = 200, int $h = 80): string
    {
        $img = imagecreatetruecolor($w, $h);
        $this->prepareTransparent($img);
        imagesetthickness($img, 4);
        imageline($img, 10, $h - 20, (int) ($w / 2), 15, (int) imagecolorallocate($img, 20, 20, 120));
        imageline($img, (int) ($w / 2), 15, $w - 10, $h - 15, (int) imagecolorallocate($img, 20, 20, 120));

        $path = tempnam(sys_get_temp_dir(), 'sgtest').'.png';
        imagepng($img, $path);

        return $path;
    }

    private function prepareTransparent(GdImage $img): void
    {
        imagealphablending($img, false);
        imagesavealpha($img, true);
        imagefill($img, 0, 0, (int) imagecolorallocatealpha($img, 0, 0, 0, 127));
    }
}
