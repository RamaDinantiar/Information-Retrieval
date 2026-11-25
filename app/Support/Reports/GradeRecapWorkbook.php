<?php

namespace App\Support\Reports;

use RuntimeException;
use ZipArchive;

/**
 * Rekap Nilai (Laporan Praktikum, Pre/Post-Test, Nilai Akhir) dengan tampilan yang sama dengan template
 * Excel "REKAP NILAI LAPORAN": font, ukuran, warna, border, lebar kolom, sel gabungan, dan rumus.
 * Gaya (`cellXfs`) dipakai ulang dari `resources/templates/rekap-nilai/styles.xml`; id di bawah
 * adalah id gaya pada template tersebut.
 */
final class GradeRecapWorkbook
{
    // Judul (baris 1–2), header kiri (baris 3–4).
    private const TITLE_TOP_LEFT = 47;

    private const TITLE_TOP = 48;

    private const TITLE_BOTTOM_LEFT = 49;

    private const TITLE_BOTTOM = 50;

    private const HEAD_NO = [51, 52];

    private const HEAD_NIM = [53, 54];

    private const HEAD_NAME = [55, 56];

    private const HEAD_RESULT = [67, 68];

    /** Kolom kiri (No, NIM, NAMA) menurut posisi baris: awal, tengah, akhir. */
    private const LEFT = ['no' => [18, 19, 23], 'nim' => [33, 33, 36], 'name' => [34, 34, 37]];

    /** Kolom hasil (rata-rata / nilai hasil): awal, tengah, akhir. */
    private const RESULT = [5, 5, 17];

    /** Header grup tiap laporan: warna bergilir kuning, biru-abu, hijau, biru muda → [awal, tengah, akhir]. */
    private const GROUP_HEAD = [[57, 57, 58], [59, 60, 60], [61, 62, 63], [64, 65, 66]];

    /** Sel kolom tunggal berwarna (header Pre/Post-Test & Nilai Akhir, 2 baris digabung): gaya 57/60/62/65 template + rata tengah vertikal. */
    private const SINGLE_HEAD = [79, 80, 81, 82];

    /** Sel nilai baris terakhir untuk kolom tunggal (gaya 9 template + rata tengah). */
    private const LAST_CELL = 83;

    public const FIRST_DATA_ROW = 5;

    /**
     * @param  array<int, array{nim: string|null, name: string}>  $students
     * @param  array<int, array<int, array{theory: float|null, practice: float|null, video: float|null, total: float|null}|null>>  $columns  per laporan → per mahasiswa (indeks sama dengan `$students`)
     */
    public static function reportSheet(string $sheetName, string $title, array $students, array $columns, bool $hasVideo): RecapSheet
    {
        $parts = $hasVideo ? 3 : 2;
        $groupWidth = $parts + 1;
        $count = count($columns);
        $resultCol = 5 + $count * $groupWidth;
        $sheet = self::frame($sheetName, $title, $students, max($resultCol, 5));
        $last = self::FIRST_DATA_ROW + count($students) - 1;

        $sheet->width(5, 11.140625);
        $sheet->width(6, 14.42578125);
        $sheet->height(4, 14.25);

        foreach ($columns as $g => $scores) {
            $first = 5 + $g * $groupWidth;
            $palette = self::GROUP_HEAD[$g % 4];
            $labels = $hasVideo ? ['TEORI', 'PRAKTIKUM', 'VIDEO', 'TOTAL NILAI'] : ['TEORI', 'PRAKTIKUM', 'TOTAL NILAI'];

            $sheet->merge(RecapSheet::column($first).'3:'.RecapSheet::column($first + $parts).'3');
            for ($i = 0; $i <= $parts; $i++) {
                $col = $first + $i;
                $sheet->set(3, $col, $i === 0 ? 'LAPORAN '.($g + 1) : null, $i === 0 ? $palette[0] : ($i === $parts ? $palette[2] : $palette[1]));
                $sheet->set(4, $col, $labels[$i], $i === $parts ? 3 : ($i === 0 ? ($g === 0 ? 29 : 10) : 6));
                if ($col > 6) {
                    $sheet->width($col, 15.5703125);
                }
            }

            foreach ($students as $r => $student) {
                $row = self::FIRST_DATA_ROW + $r;
                $pos = $r === 0 ? 'first' : ($row === $last ? 'last' : 'mid');
                $cell = self::resolve($scores[$r] ?? null, $hasVideo);

                for ($i = 0; $i < $parts; $i++) {
                    $style = match (true) {
                        $i === 0 => ['first' => $g === 0 ? 24 : 11, 'mid' => $g === 0 ? 25 : 12, 'last' => $g === 0 ? 28 : 15][$pos],
                        default => ['first' => 2, 'mid' => 1, 'last' => 9][$pos],
                    };
                    $sheet->set($row, $first + $i, $cell['values'][$i], $style);
                }

                $totalStyle = $pos === 'last' ? 16 : 4;
                if ($cell['legacy']) {
                    // Nilai lama tanpa rincian komponen: TOTAL ditampilkan apa adanya, bukan rumus.
                    $sheet->set($row, $first + $parts, $cell['total'], $totalStyle);
                } else {
                    $refs = [];
                    for ($i = 0; $i < $parts; $i++) {
                        $refs[] = RecapSheet::column($first + $i).$row;
                    }
                    $sheet->set($row, $first + $parts, $cell['total'], $totalStyle, '('.implode('+', $refs).")/{$parts}");
                }
            }
        }

        // NILAI HASIL LAPORAN = jumlah TOTAL / jumlah laporan.
        $sheet->width($resultCol, 33.140625);
        $sheet->merge(RecapSheet::column($resultCol).'3:'.RecapSheet::column($resultCol).'4');
        $sheet->set(3, $resultCol, 'NILAI HASIL LAPORAN', self::HEAD_RESULT[0]);
        $sheet->set(4, $resultCol, null, self::HEAD_RESULT[1]);

        foreach ($students as $r => $student) {
            $row = self::FIRST_DATA_ROW + $r;
            $pos = $r === 0 ? 0 : ($row === $last ? 2 : 1);
            $totals = array_map(fn ($g) => RecapSheet::column(5 + $g * $groupWidth + $parts).$row, array_keys($columns));
            $sum = 0.0;
            foreach ($columns as $scores) {
                $sum += self::resolve($scores[$r] ?? null, $hasVideo)['total'];
            }
            $sheet->set($row, $resultCol, $count > 0 ? $sum / $count : null, self::RESULT[$pos], $count > 0 ? 'SUM('.implode(',', $totals).')/'.$count : null);
        }

        return $sheet;
    }

    /**
     * Komponen satu laporan satu mahasiswa. TOTAL = rata-rata komponen (blank dihitung 0, seperti rumus
     * template). `legacy` = nilai lama yang hanya punya satu angka tanpa komponen.
     *
     * @param  array{theory: float|null, practice: float|null, video: float|null, total: float|null}|null  $cell
     * @return array{values: array<int, float|null>, total: float, legacy: bool}
     */
    private static function resolve(?array $cell, bool $hasVideo): array
    {
        $values = $cell === null ? [null, null, null] : [$cell['theory'], $cell['practice'], $hasVideo ? $cell['video'] : null];
        $values = array_slice($values, 0, $hasVideo ? 3 : 2);
        $legacy = $cell !== null && $cell['total'] !== null && $cell['theory'] === null && $cell['practice'] === null;

        return [
            'values' => $values,
            'total' => $legacy ? (float) $cell['total'] : array_sum($values) / count($values),
            'legacy' => $legacy,
        ];
    }

    /**
     * Pre-Test / Post-Test: satu kolom per tugas + rata-rata.
     *
     * @param  array<int, array{nim: string|null, name: string}>  $students
     * @param  array<int, array{title: string, scores: array<int, float|null>}>  $tests
     */
    public static function testSheet(string $sheetName, string $title, string $averageLabel, array $students, array $tests): RecapSheet
    {
        $resultCol = 5 + count($tests);
        $sheet = self::frame($sheetName, $title, $students, $resultCol);
        $last = self::FIRST_DATA_ROW + count($students) - 1;

        foreach ($tests as $i => $test) {
            $col = 5 + $i;
            $sheet->width($col, min(60, max(15.57, mb_strlen($test['title']) * 1.15 + 4)));
            $sheet->merge(RecapSheet::column($col).'3:'.RecapSheet::column($col).'4');
            $sheet->set(3, $col, $test['title'], self::SINGLE_HEAD[$i % 4]);
            $sheet->set(4, $col, null, self::SINGLE_HEAD[$i % 4]);

            foreach ($students as $r => $student) {
                $row = self::FIRST_DATA_ROW + $r;
                $sheet->set($row, $col, $test['scores'][$r] ?? null, $r === 0 ? 2 : ($row === $last ? self::LAST_CELL : 1));
            }
        }

        // Rata-rata hanya dari kolom yang terisi: tes yang belum dinilai tidak dihitung 0, dan tes baru
        // ikut dihitung begitu nilainya diisi (AVERAGE mengabaikan sel kosong).
        self::resultColumn($sheet, $resultCol, $averageLabel, $students, function (int $r, int $row) use ($tests, $resultCol) {
            $values = array_filter(array_map(fn ($t) => $t['scores'][$r] ?? null, $tests), fn ($v) => $v !== null);

            return $values === []
                ? [null, null]
                : [array_sum($values) / count($values), 'AVERAGE(E'.$row.':'.RecapSheet::column($resultCol - 1).$row.')'];
        });

        return $sheet;
    }

    /**
     * Nilai akhir: rata-rata tiap komponen dan nilai akhir berbobot (nilai tetap, bukan rumus).
     *
     * @param  array<int, array{nim: string|null, name: string}>  $students
     * @param  array<int, array{pretest: float|null, posttest: float|null, report: float|null, final: float|null}>  $finals
     */
    public static function finalSheet(string $sheetName, string $title, array $students, array $finals): RecapSheet
    {
        $sheet = self::frame($sheetName, $title, $students, 8);
        $last = self::FIRST_DATA_ROW + count($students) - 1;
        $columns = [
            [5, 'RATA-RATA PRE-TEST', 'pretest', 20.0, self::SINGLE_HEAD[0]],
            [6, 'RATA-RATA POST-TEST', 'posttest', 20.0, self::SINGLE_HEAD[1]],
            [7, 'RATA-RATA LAPORAN', 'report', 20.0, self::SINGLE_HEAD[2]],
        ];

        foreach ($columns as [$col, $label, $key, $width, $headStyle]) {
            $sheet->width($col, $width);
            $sheet->merge(RecapSheet::column($col).'3:'.RecapSheet::column($col).'4');
            $sheet->set(3, $col, $label, $headStyle);
            $sheet->set(4, $col, null, $headStyle);
            foreach ($students as $r => $student) {
                $row = self::FIRST_DATA_ROW + $r;
                $sheet->set($row, $col, $finals[$r][$key] ?? null, $r === 0 ? 2 : ($row === $last ? self::LAST_CELL : 1));
            }
        }

        foreach ([[8, 'NILAI AKHIR', 'final', 22.0]] as [$col, $label, $key, $width]) {
            $sheet->width($col, $width);
            $sheet->merge(RecapSheet::column($col).'3:'.RecapSheet::column($col).'4');
            $sheet->set(3, $col, $label, self::HEAD_RESULT[0]);
            $sheet->set(4, $col, null, self::HEAD_RESULT[1]);
            foreach ($students as $r => $student) {
                $row = self::FIRST_DATA_ROW + $r;
                $sheet->set($row, $col, $finals[$r][$key] ?? null, self::RESULT[$r === 0 ? 0 : ($row === $last ? 2 : 1)]);
            }
        }

        return $sheet;
    }

    /**
     * @param  list<RecapSheet>  $sheets
     */
    public static function write(string $path, array $sheets): void
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Tidak dapat membuat berkas Excel.');
        }

        $override = '';
        $workbook = '';
        $rels = '';
        foreach ($sheets as $i => $sheet) {
            $n = $i + 1;
            $override .= '<Override PartName="/xl/worksheets/sheet'.$n.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $workbook .= '<sheet name="'.htmlspecialchars(self::sheetName($sheet->name), ENT_XML1 | ENT_QUOTES).'" sheetId="'.$n.'" r:id="rId'.$n.'"/>';
            $rels .= '<Relationship Id="rId'.$n.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$n.'.xml"/>';
            $zip->addFromString('xl/worksheets/sheet'.$n.'.xml', $sheet->toXml($i === 0));
        }
        $s = count($sheets) + 1;
        $t = count($sheets) + 2;

        $head = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $zip->addFromString('[Content_Types].xml', $head.'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'.$override.'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/><Override PartName="/xl/theme/theme1.xml" ContentType="application/vnd.openxmlformats-officedocument.theme+xml"/></Types>');
        $zip->addFromString('_rels/.rels', $head.'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', $head.'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><bookViews><workbookView activeTab="0"/></bookViews><sheets>'.$workbook.'</sheets><calcPr calcId="191029" fullCalcOnLoad="1"/></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', $head.'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$rels.'<Relationship Id="rId'.$s.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/><Relationship Id="rId'.$t.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/theme" Target="theme/theme1.xml"/></Relationships>');
        $zip->addFile(resource_path('templates/rekap-nilai/styles.xml'), 'xl/styles.xml');
        $zip->addFile(resource_path('templates/rekap-nilai/theme1.xml'), 'xl/theme/theme1.xml');
        $zip->close();
    }

    /** Nama sheet Excel: maks. 31 karakter dan tanpa []:*?/\. */
    public static function sheetName(string $name): string
    {
        $clean = trim((string) preg_replace('/[\[\]:*?\/\\\\]+/', ' ', $name));

        return mb_substr($clean === '' ? 'Sheet' : $clean, 0, 31);
    }

    /**
     * Judul (B1 digabung sampai kolom terakhir, 2 baris) + header No/NIM/NAMA + isi kolom kiri.
     *
     * @param  array<int, array{nim: string|null, name: string}>  $students
     */
    private static function frame(string $sheetName, string $title, array $students, int $lastCol): RecapSheet
    {
        $sheet = new RecapSheet($sheetName);
        $sheet->width(3, 14.85546875);
        $sheet->width(4, 56.140625);
        $sheet->height(1, 15);
        $sheet->height(2, 23.25);

        $sheet->merge('B1:'.RecapSheet::column($lastCol).'2');
        for ($c = 2; $c <= $lastCol; $c++) {
            $sheet->set(1, $c, $c === 2 ? $title : null, $c === 2 ? self::TITLE_TOP_LEFT : self::TITLE_TOP);
            $sheet->set(2, $c, null, $c === 2 ? self::TITLE_BOTTOM_LEFT : self::TITLE_BOTTOM);
        }

        foreach ([[2, 'No', self::HEAD_NO, 'B'], [3, 'NIM', self::HEAD_NIM, 'C'], [4, 'NAMA', self::HEAD_NAME, 'D']] as [$col, $label, $style, $letter]) {
            $sheet->merge("{$letter}3:{$letter}4");
            $sheet->set(3, $col, $label, $style[0]);
            $sheet->set(4, $col, null, $style[1]);
        }

        $last = self::FIRST_DATA_ROW + count($students) - 1;
        foreach ($students as $r => $student) {
            $row = self::FIRST_DATA_ROW + $r;
            $pos = $r === 0 ? 0 : ($row === $last ? 2 : 1);
            $sheet->height($row, 15.75);
            $sheet->set($row, 2, $r + 1, self::LEFT['no'][$pos]);
            $sheet->set($row, 3, $student['nim'] !== null && ctype_digit($student['nim']) ? (int) $student['nim'] : $student['nim'], self::LEFT['nim'][$pos]);
            $sheet->set($row, 4, $student['name'], self::LEFT['name'][$pos]);
        }

        return $sheet;
    }

    /**
     * @param  array<int, array{nim: string|null, name: string}>  $students
     * @param  callable(int, int): array{0: float|null, 1: string|null}  $values
     */
    private static function resultColumn(RecapSheet $sheet, int $col, string $label, array $students, callable $values): void
    {
        $last = self::FIRST_DATA_ROW + count($students) - 1;
        $sheet->width($col, 33.140625);
        $sheet->merge(RecapSheet::column($col).'3:'.RecapSheet::column($col).'4');
        $sheet->set(3, $col, $label, self::HEAD_RESULT[0]);
        $sheet->set(4, $col, null, self::HEAD_RESULT[1]);

        foreach ($students as $r => $student) {
            $row = self::FIRST_DATA_ROW + $r;
            [$value, $formula] = $values($r, $row);
            $sheet->set($row, $col, $value, self::RESULT[$r === 0 ? 0 : ($row === $last ? 2 : 1)], $formula);
        }
    }
}
