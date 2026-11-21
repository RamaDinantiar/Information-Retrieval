<?php

namespace App\Support\Reports;

use Closure;
use DOMElement;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Shared\Drawing as DrawingMath;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;

/**
 * Pengisi template Excel kampus (PRD Bagian 7). Hanya bagian yang diizinkan
 * yang berubah: kop (semester, SK, periode), jumlah kolom tanggal, baris
 * asisten, tabel Kelas Tambahan/Pengganti, tanda tangan, dan nama pengesah.
 * Font, lebar kolom, merge header, logo, legenda, dan pengaturan cetak berasal
 * dari template.
 *
 * @phpstan-import-type Recap from NgawasRecap
 *
 * @phpstan-type Approver array{position: string, name: string, signaturePath: string|null}
 * @phpstan-type Proto array{xf: array<int, int>, height: float}
 */
class NgawasRecapWorkbook
{
    public const DRAFT = 'draft';

    public const FINAL = 'final';

    /** Teks di header halaman ekspor Draft (di luar tabel, tidak mengubah tata letak). */
    public const DRAFT_HEADER = '&C&B&16DRAFT - belum final';

    /** Baris template (1-based) mulai dari mana elemen ikut bergeser bersama jumlah baris data (baris TOTAL). */
    private const NOTES_THRESHOLD_ROW = NgawasTemplate::TOTAL_ROW;

    /** Kolom 0-based (AJ) tempat tepi kanan bentuk mulai ikut bergeser saat kolom tanggal dihapus. */
    private const RIGHT_EDGE_COLUMN = 35;

    /** @var array<string, string> kind legenda → ARGB */
    private array $legend = [];

    /** @var array<string, array{0: int, 1: int}> */
    private array $imageSize = [];

    /**
     * Indeks gaya turunan ("gaya awal|variasi" → indeks). Membuat gaya lewat getStyle() mahal
     * (puluhan ms per sel), jadi tiap kombinasi dibuat sekali lalu dipakai ulang.
     *
     * @var array<string, int>
     */
    private array $derived = [];

    /**
     * Buat file rekap dan kembalikan path file sementara (hapus setelah dipakai).
     *
     * @param  Recap  $recap  hasil NgawasRecap::build()
     * @param  'draft'|'final'  $mode  Draft = tanpa tanda tangan pengesah + penanda DRAFT
     * @param  list<Approver>  $approvers  [0] = Pengesah 1 (kiri), [1] = Pengesah 2 (kanan)
     * @param  array<int, string>  $signatures  userId → path PNG tanda tangan asisten
     */
    public function build(array $recap, string $mode, array $approvers, array $signatures, ?string $templatePath = null): string
    {
        try {
            return $this->assemble($recap, $mode, $approvers, $signatures, $templatePath);
        } finally {
            // PhpSpreadsheet menyimpan referensi melingkar (sheet ↔ workbook); setelah assemble() selesai dan variabel
            // lokalnya dilepas, bersihkan agar worker antrean yang berjalan lama tidak membengkak.
            $this->derived = [];
            $this->imageSize = [];
            gc_collect_cycles();
        }
    }

    /**
     * @param  Recap  $recap
     * @param  'draft'|'final'  $mode
     * @param  list<Approver>  $approvers
     * @param  array<int, string>  $signatures
     */
    private function assemble(array $recap, string $mode, array $approvers, array $signatures, ?string $templatePath): string
    {
        $template = $templatePath ?? NgawasTemplate::activePath();
        $errors = NgawasTemplateValidator::validate($template);

        if ($errors !== []) {
            throw new RuntimeException('Template rekap tidak valid: '.implode(' ', $errors));
        }

        if ($recap['assistants'] === []) {
            throw new RuntimeException('Tidak ada asisten pada rekap ini.');
        }

        $spreadsheet = (new XlsxReader)->load($template);
        $recapSheet = $spreadsheet->getSheet(NgawasTemplate::RECAP);
        $attendanceSheet = $spreadsheet->getSheet(NgawasTemplate::ATTENDANCE);

        $days = $recap['daysInMonth'];
        $removed = NgawasTemplate::MAX_DAYS - $days;
        $rows = array_sum(array_map(fn (array $a) => count($a['rows']), $recap['assistants']));
        $rowDelta = $rows - NgawasTemplate::DATA_ROWS;

        foreach ([$recapSheet, $attendanceSheet] as $index => $sheet) {
            if ($removed > 0) {
                $sheet->removeColumn(Coordinate::stringFromColumnIndex(NgawasTemplate::FIRST_DAY_COLUMN + $days), $removed);
                $this->rebuildHeaderMerges($sheet, $days, NgawasTemplate::FIRST_DAY_COLUMN + $days + ($index === NgawasTemplate::RECAP ? 1 : 0));
            }
        }

        $this->derived = [];
        $this->legend = $this->captureLegend($recapSheet);

        $this->fillSheet($spreadsheet, $recapSheet, $recap, true, $rows, $signatures);
        $this->fillSheet($spreadsheet, $attendanceSheet, $recap, false, $rows, $signatures);

        $tables = $this->fillTables($recapSheet, $recap, $rowDelta);

        if ($mode === self::FINAL) {
            $this->placeApproverSignatures($spreadsheet, $approvers, $rowDelta);
        } else {
            foreach ([$recapSheet, $attendanceSheet] as $sheet) {
                $sheet->getHeaderFooter()->setOddHeader(self::DRAFT_HEADER);
            }
        }

        $spreadsheet->getProperties()->setTitle('Rekap Ngawas LAB IR '.$recap['monthLabel'])->setCreator('IR Lab Management System');
        $spreadsheet->setActiveSheetIndex(0);

        $path = tempnam(sys_get_temp_dir(), 'ngawas').'.xlsx';
        IOFactory::createWriter($spreadsheet, 'Xlsx')->save($path);
        $spreadsheet->disconnectWorksheets();

        XlsxShapes::inject($path, XlsxShapes::read($template), $this->shapeAdjuster($recap, $approvers, $removed, $rowDelta, $tables));

        $this->verify($path, $recap, $rows);

        return $path;
    }

    /** Nama file unduhan: Rekap_Ngawas_LAB_IR_APRIL_2026.xlsx. */
    public static function filename(string $monthLabel): string
    {
        return 'Rekap_Ngawas_LAB_IR_'.str_replace(' ', '_', $monthLabel).'.xlsx';
    }

    // ------------------------------------------------------------------ sheet

    /**
     * PhpSpreadsheet tidak menyusutkan merge yang melintasi beberapa kolom terhapus (hanya 1 kolom), sehingga
     * merge header tanggal bisa tumpang tindih dengan kolom total. Merge kop dan header tanggal dibuat ulang.
     */
    private function rebuildHeaderMerges(Worksheet $ws, int $days, int $lastColumn): void
    {
        foreach (array_values($ws->getMergeCells()) as $range) {
            if (preg_match('/^(E1[34]|A([1-9]|1[01])):/', $range) === 1) {
                $ws->unmergeCells($range);
            }
        }

        $lastDay = Coordinate::stringFromColumnIndex(NgawasTemplate::FIRST_DAY_COLUMN + $days - 1);
        $last = Coordinate::stringFromColumnIndex($lastColumn);

        for ($row = 1; $row <= 11; $row++) {
            $ws->mergeCells("A{$row}:{$last}{$row}");
        }

        $ws->mergeCells("E13:{$lastDay}13");
        $ws->mergeCells("E14:{$lastDay}14");
    }

    /**
     * @param  Recap  $recap
     * @param  array<int, string>  $signatures
     */
    private function fillSheet(Spreadsheet $spreadsheet, Worksheet $ws, array $recap, bool $isRecap, int $rows, array $signatures): void
    {
        $days = $recap['daysInMonth'];
        $sheetIndex = $isRecap ? NgawasTemplate::RECAP : NgawasTemplate::ATTENDANCE;
        $lastDayCol = NgawasTemplate::FIRST_DAY_COLUMN + $days - 1;
        $daysCol = $lastDayCol + 1;
        $hoursCol = $lastDayCol + 2;
        $lastCol = $isRecap ? $hoursCol : $daysCol;

        $protos = $this->capturePrototypes($ws, $sheetIndex, $lastCol);

        // Kop: semester, SK, periode; tanggal 1 dan nomor hari.
        $ws->setCellValue('A9', (string) ($recap['semester']['termLabel'] ?? ''));
        $ws->setCellValue('A10', 'SESUAI SK NOMOR: '.($recap['semester']['skNumber'] ?? ''));
        $ws->setCellValue('A11', 'PERIODE BULAN: '.$recap['monthLabel']);
        $ws->setCellValue('E'.NgawasTemplate::DATE_ROW, Date::PHPToExcel(new \DateTimeImmutable($recap['month'].'-01')));

        for ($d = 1; $d <= $days; $d++) {
            $ws->setCellValue(Coordinate::stringFromColumnIndex(NgawasTemplate::FIRST_DAY_COLUMN + $d - 1).NgawasTemplate::DAY_ROW, $d);
        }

        // Jumlah baris data: sisip/hapus di akhir daerah data agar baris TOTAL dan semua di bawahnya ikut bergeser.
        $delta = $rows - NgawasTemplate::DATA_ROWS;

        if ($delta > 0) {
            $ws->insertNewRowBefore(NgawasTemplate::TOTAL_ROW, $delta);
        } elseif ($delta < 0) {
            $ws->removeRow(NgawasTemplate::FIRST_DATA_ROW + $rows, -$delta);
        }

        $firstRow = NgawasTemplate::FIRST_DATA_ROW;
        $lastRow = $firstRow + $rows - 1;
        $totalRow = $lastRow + 1;
        $dayLetter = fn (int $day) => Coordinate::stringFromColumnIndex(NgawasTemplate::FIRST_DAY_COLUMN + $day - 1);
        $firstDayLetter = $dayLetter(1);
        $lastDayLetter = $dayLetter($days);
        $daysLetter = Coordinate::stringFromColumnIndex($daysCol);
        $hoursLetter = Coordinate::stringFromColumnIndex($hoursCol);

        $row = $firstRow;
        $number = 0;

        foreach ($recap['assistants'] as $assistant) {
            $number++;
            $blockStart = $row;
            $blockEnd = $row + count($assistant['rows']) - 1;

            foreach ($assistant['rows'] as $index => $item) {
                $isFirst = $index === 0;
                $isLast = $row === $blockEnd;
                $proto = $protos[$isFirst && $isLast ? 'single' : ($isFirst ? 'first' : ($isLast ? 'last' : 'middle'))];

                $this->applyPrototype($ws, $row, $proto, $lastCol, $isFirst && $isLast);

                if ($isFirst) {
                    $ws->setCellValue("A{$row}", $number);
                    $ws->setCellValue("B{$row}", $assistant['name']);
                    $ws->setCellValueExplicit("C{$row}", $assistant['nim'], DataType::TYPE_STRING);
                }

                $ws->setCellValue("D{$row}", $item['label']);

                foreach ($recap['columns'] as $column) {
                    $cell = $item['cells'][$column['day']] ?? null;
                    $address = $dayLetter($column['day']).$row;
                    $fill = $cell['kind'] ?? $column['kind'];

                    if ($fill !== null) {
                        $this->paint($ws, $address, $fill);
                    }

                    if ($cell === null || ! $cell['counts']) {
                        continue;
                    }

                    if ($isRecap) {
                        $ws->setCellValue($address, '='.$cell['credits'].'*50/60');
                    } elseif (isset($signatures[$assistant['userId']])) {
                        $this->placeSignature($ws, $address, $signatures[$assistant['userId']], $row);
                    }
                }

                if ($isRecap) {
                    $ws->setCellValue("{$daysLetter}{$row}", "=COUNT({$firstDayLetter}{$row}:{$lastDayLetter}{$row})");
                } else {
                    $ws->setCellValue("{$daysLetter}{$row}", $item['totalDays']);
                }

                $row++;
            }

            if ($blockEnd > $blockStart) {
                foreach ($isRecap ? ['A', 'B', 'C', $hoursLetter] : ['A', 'B', 'C'] as $letter) {
                    $ws->mergeCells("{$letter}{$blockStart}:{$letter}{$blockEnd}");
                }
            }

            if ($isRecap) {
                $ws->setCellValue("{$hoursLetter}{$blockStart}", "=ROUND(SUM({$firstDayLetter}{$blockStart}:{$lastDayLetter}{$blockEnd}),0)");
            }
        }

        if ($isRecap) {
            $ws->setCellValue("{$daysLetter}{$totalRow}", "=SUM({$daysLetter}{$firstRow}:{$daysLetter}{$lastRow})");
            $ws->setCellValue("{$hoursLetter}{$totalRow}", "=ROUND(SUM({$hoursLetter}{$firstRow}:{$hoursLetter}{$lastRow}),0)");
        } else {
            $ws->setCellValue("{$daysLetter}{$totalRow}", $recap['totals']['days']);
        }
    }

    /**
     * Gaya sel baris contoh (pertama/tengah/terakhir) per kolom, diambil dari template sebelum baris diubah.
     *
     * @return array<string, Proto>
     */
    private function capturePrototypes(Worksheet $ws, int $sheetIndex, int $lastCol): array
    {
        $capture = function (int $row) use ($ws, $lastCol): array {
            $xf = [];

            for ($c = 1; $c <= $lastCol; $c++) {
                $xf[$c] = $ws->getCell(Coordinate::stringFromColumnIndex($c).$row)->getXfIndex();
            }

            return ['xf' => $xf, 'height' => (float) $ws->getRowDimension($row)->getRowHeight()];
        };

        $rows = NgawasTemplate::PROTOTYPES[$sheetIndex];
        $first = $capture($rows['first']);

        return [
            'first' => $first,
            'middle' => $capture($rows['middle']),
            'last' => $capture($rows['last']),
            // Blok satu baris: gaya baris pertama dengan garis bawah tebal.
            'single' => $first,
        ];
    }

    /**
     * @param  Proto  $proto
     */
    private function applyPrototype(Worksheet $ws, int $row, array $proto, int $lastCol, bool $closeBlock): void
    {
        foreach ($proto['xf'] as $c => $xf) {
            $address = Coordinate::stringFromColumnIndex($c).$row;
            $ws->getCell($address)->setXfIndex($xf);
        }

        $ws->getRowDimension($row)->setRowHeight($proto['height']);

        if ($closeBlock) {
            foreach ($proto['xf'] as $c => $xf) {
                $this->derive($ws, Coordinate::stringFromColumnIndex($c).$row, 'close', function (Worksheet $sheet, string $address): void {
                    $sheet->getStyle($address)->getBorders()->getBottom()->setBorderStyle(Border::BORDER_MEDIUM);
                });
            }
        }
    }

    // ------------------------------------------------------------------ warna

    /**
     * Warna sel legenda (A80:A85) sebagai ARGB, supaya warna identik dengan template.
     *
     * @return array<string, string>
     */
    private function captureLegend(Worksheet $ws): array
    {
        $legend = [];

        foreach (NgawasTemplate::LEGEND as $kind => $row) {
            $legend[$kind] = $ws->getStyle('A'.$row)->getFill()->getStartColor()->getARGB();
        }

        return $legend;
    }

    private function paint(Worksheet $ws, string $address, string $kind): void
    {
        $key = NgawasTemplate::KIND_LEGEND[$kind] ?? null;

        if ($key === null || ! isset($this->legend[$key])) {
            return;
        }

        $this->derive($ws, $address, 'fill:'.$key, function (Worksheet $sheet, string $address) use ($key): void {
            $fill = $sheet->getStyle($address)->getFill();
            $fill->setFillType(Fill::FILL_SOLID);
            $fill->getStartColor()->setARGB($this->legend[$key]);
            $fill->getEndColor()->setARGB($this->legend[$key]);
        });
    }

    /**
     * Terapkan variasi gaya ke sel. Hasil pertama untuk (gaya awal, variasi) dibuat lewat API gaya;
     * sel berikutnya dengan kombinasi yang sama cukup memakai indeks gaya yang sama.
     *
     * @param  Closure(Worksheet, string): void  $apply
     */
    private function derive(Worksheet $ws, string $address, string $variant, Closure $apply): void
    {
        $cell = $ws->getCell($address);
        $key = $cell->getXfIndex().'|'.$variant;

        if (isset($this->derived[$key])) {
            $cell->setXfIndex($this->derived[$key]);

            return;
        }

        $apply($ws, $address);
        $this->derived[$key] = $cell->getXfIndex();
    }

    // ----------------------------------------------------------- tanda tangan

    /**
     * Gambar tanda tangan asisten di dalam sel (margin 2 px, proporsional, di tengah).
     */
    private function placeSignature(Worksheet $ws, string $address, string $png, int $row): void
    {
        $column = preg_replace('/\d+/', '', $address) ?? 'E';
        $width = $ws->getColumnDimension($column)->getWidth();
        $cellW = DrawingMath::cellDimensionToPixels($width > 0 ? $width : 8.43, $ws->getParent()?->getDefaultStyle()->getFont() ?? new Font);
        $height = $ws->getRowDimension($row)->getRowHeight();
        $cellH = DrawingMath::pointsToPixels($height > 0 ? $height : 15.0);

        [$imgW, $imgH] = $this->imageSize[$png] ??= $this->measure($png);
        $margin = NgawasTemplate::CELL_MARGIN_PX;
        $scale = min(max(1, $cellW - 2 * $margin) / $imgW, max(1, $cellH - 2 * $margin) / $imgH);
        $w = max(1, (int) floor($imgW * $scale));
        $h = max(1, (int) floor($imgH * $scale));

        $drawing = new Drawing;
        $drawing->setName('Tanda tangan');
        $drawing->setPath($png);
        $drawing->setCoordinates($address);
        $drawing->setResizeProportional(false);
        $drawing->setWidth($w);
        $drawing->setHeight($h);
        $drawing->setOffsetX(max($margin, (int) floor(($cellW - $w) / 2)));
        $drawing->setOffsetY(max($margin, (int) floor(($cellH - $h) / 2)));
        $drawing->setWorksheet($ws);
    }

    /**
     * @param  list<Approver>  $approvers
     */
    private function placeApproverSignatures(Spreadsheet $spreadsheet, array $approvers, int $rowDelta): void
    {
        foreach (NgawasTemplate::SIGNATURE_SLOTS as $sheetIndex => $slots) {
            $ws = $spreadsheet->getSheet($sheetIndex);

            foreach ($slots as $approver => $slot) {
                $png = $approvers[$approver - 1]['signaturePath'] ?? null;

                if ($png === null || ! is_file($png)) {
                    continue;
                }

                [$imgW, $imgH] = $this->imageSize[$png] ??= $this->measure($png);
                $scale = min($slot['width'] / $imgW, $slot['height'] / $imgH, 1.0);

                if (preg_match('/^([A-Z]+)(\d+)$/', $slot['cell'], $m) !== 1) {
                    continue;
                }

                $row = (int) $m[2];
                $row += $row >= self::NOTES_THRESHOLD_ROW ? $rowDelta : 0;

                $drawing = new Drawing;
                $drawing->setName('Tanda tangan pengesah '.$approver);
                $drawing->setPath($png);
                $drawing->setCoordinates($m[1].$row);
                $drawing->setResizeProportional(false);
                $drawing->setWidth(max(1, (int) round($imgW * $scale)));
                $drawing->setHeight(max(1, (int) round($imgH * $scale)));
                $drawing->setWorksheet($ws);
            }
        }
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function measure(string $png): array
    {
        $info = @getimagesize($png);

        if ($info === false || $info[0] < 1 || $info[1] < 1) {
            throw new RuntimeException('Gambar tanda tangan tidak dapat dibaca.');
        }

        return [$info[0], $info[1]];
    }

    // ------------------------------------------------------------ tabel catatan

    /**
     * Isi tabel Kelas Tambahan dan Kelas Pengganti (sheet 1). Mengembalikan
     * jumlah baris yang ditambahkan pada masing-masing tabel.
     *
     * @param  Recap  $recap
     * @return array{additional: int, replacement: int}
     */
    private function fillTables(Worksheet $ws, array $recap, int $rowDelta): array
    {
        // Tabel bawah dulu agar koordinat tabel atas tidak bergeser.
        $replacement = $this->fillTable($ws, NgawasTemplate::REPLACEMENT_TABLE, $recap['replacements'], $rowDelta);
        $additional = $this->fillTable($ws, NgawasTemplate::ADDITIONAL_TABLE, $recap['additional'], $rowDelta);

        return ['additional' => $additional, 'replacement' => $replacement];
    }

    /**
     * @param  array{title: int, first: int, rows: int}  $table
     * @param  list<array{date: string, class: string, time: string, note: string}>  $entries
     */
    private function fillTable(Worksheet $ws, array $table, array $entries, int $rowDelta): int
    {
        $first = $table['first'] + $rowDelta;
        $extra = max(0, count($entries) - $table['rows']);

        if ($extra > 0) {
            $ws->insertNewRowBefore($first + $table['rows'], $extra);
        }

        $protoXf = [];

        foreach (['A', 'B', 'C', 'D'] as $letter) {
            $protoXf[$letter] = $ws->getCell($letter.$first)->getXfIndex();
        }

        foreach ($entries as $i => $entry) {
            $row = $first + $i;

            foreach ($protoXf as $letter => $xf) {
                $ws->getCell($letter.$row)->setXfIndex($xf);
            }

            $ws->setCellValue("A{$row}", Date::PHPToExcel(new \DateTimeImmutable($entry['date'])));
            $ws->setCellValue("B{$row}", $entry['class']);
            $ws->setCellValueExplicit("C{$row}", $entry['time'], DataType::TYPE_STRING);
            $ws->setCellValue("D{$row}", $entry['note']);
            $ws->getRowDimension($row)->setRowHeight(-1);
        }

        return $extra;
    }

    // ----------------------------------------------------------------- bentuk

    /**
     * Penggeser bentuk template: turun mengikuti baris yang bertambah/berkurang,
     * tepi kanan menyusut mengikuti kolom tanggal yang dihapus, dan teks pengesah diisi.
     *
     * @param  Recap  $recap
     * @param  list<Approver>  $approvers
     * @param  array{additional: int, replacement: int}  $tables
     * @return Closure(int, DOMElement): void
     */
    private function shapeAdjuster(array $recap, array $approvers, int $removedColumns, int $rowDelta, array $tables): Closure
    {
        $additionalEnd = NgawasTemplate::ADDITIONAL_TABLE['first'] + NgawasTemplate::ADDITIONAL_TABLE['rows'];
        $replacementEnd = NgawasTemplate::REPLACEMENT_TABLE['first'] + NgawasTemplate::REPLACEMENT_TABLE['rows'];

        return function (int $sheet, DOMElement $anchor) use ($approvers, $removedColumns, $rowDelta, $tables, $additionalEnd, $replacementEnd): void {
            $row = XlsxShapes::fromRow($anchor) + 1; // 1-based, koordinat template

            $shift = ($row >= self::NOTES_THRESHOLD_ROW ? $rowDelta : 0);

            if ($sheet === NgawasTemplate::RECAP) {
                // Tabel catatan yang membesar mendorong semua yang berjangkar di bawahnya (mis. catatan "1 sks = 50 menit").
                $shift += $row >= $additionalEnd ? $tables['additional'] : 0;
                $shift += $row >= $replacementEnd ? $tables['replacement'] : 0;
            }

            XlsxShapes::shiftRows($anchor, $shift);

            if ($removedColumns > 0) {
                XlsxShapes::shiftRightEdge($anchor, self::RIGHT_EDGE_COLUMN, -$removedColumns);
            }

            $approver = NgawasTemplate::APPROVER_SHAPES[$sheet][XlsxShapes::name($anchor)] ?? null;

            if ($approver !== null) {
                XlsxShapes::replaceText($anchor, [
                    NgawasTemplate::token($approver, 'jabatan') => $approvers[$approver - 1]['position'] ?? '',
                    NgawasTemplate::token($approver, 'nama') => $approvers[$approver - 1]['name'] ?? '',
                ]);
            }
        };
    }

    // ------------------------------------------------------------- verifikasi

    /**
     * PRD 7.3.6: buka ulang file hasil, hitung rumusnya, dan cocokkan dengan mesin rekap.
     *
     * Jumlah mentah per asisten dibandingkan ketat (toleransi 1e-6). Hasil ROUND hanya dibandingkan setelahnya:
     * PhpSpreadsheet membulatkan dari nilai biner, sedangkan Excel (dan mesin rekap) dari 15 digit desimal, sehingga
     * jumlah yang jatuh tepat di batas .5 (mis. 12,499999999999998) boleh berbeda 1 tanpa dianggap kesalahan.
     *
     * @param  Recap  $recap
     */
    private function verify(string $path, array $recap, int $rows): void
    {
        $spreadsheet = (new XlsxReader)->load($path);
        $recapSheet = $spreadsheet->getSheet(NgawasTemplate::RECAP);
        $attendanceSheet = $spreadsheet->getSheet(NgawasTemplate::ATTENDANCE);

        $days = $recap['daysInMonth'];
        $firstDay = Coordinate::stringFromColumnIndex(NgawasTemplate::FIRST_DAY_COLUMN);
        $lastDay = Coordinate::stringFromColumnIndex(NgawasTemplate::FIRST_DAY_COLUMN + $days - 1);
        $daysLetter = Coordinate::stringFromColumnIndex(NgawasTemplate::FIRST_DAY_COLUMN + $days);
        $hoursLetter = Coordinate::stringFromColumnIndex(NgawasTemplate::FIRST_DAY_COLUMN + $days + 1);
        $totalRow = NgawasTemplate::FIRST_DATA_ROW + $rows;
        $problems = [];
        $sumOfBlocks = 0;
        $edgeBlocks = 0;

        $row = NgawasTemplate::FIRST_DATA_ROW;

        foreach ($recap['assistants'] as $assistant) {
            $blockStart = $row;

            foreach ($assistant['rows'] as $item) {
                $actual = (int) $recapSheet->getCell("{$daysLetter}{$row}")->getCalculatedValue();

                if ($actual !== $item['totalDays']) {
                    $problems[] = "baris {$row}: total hari {$actual}, seharusnya {$item['totalDays']}";
                }

                $row++;
            }

            $values = $recapSheet->rangeToArray("{$firstDay}{$blockStart}:{$lastDay}".($row - 1), null, true, false);
            $raw = 0.0;

            foreach ($values as $line) {
                foreach ($line as $value) {
                    $raw += is_numeric($value) ? (float) $value : 0.0;
                }
            }

            if (abs($raw - $assistant['hoursRaw']) > 1e-6) {
                $problems[] = sprintf('%s: jumlah jam %.4f, seharusnya %.4f', $assistant['name'], $raw, $assistant['hoursRaw']);
            }

            $hours = (int) $recapSheet->getCell("{$hoursLetter}{$blockStart}")->getCalculatedValue();
            $sumOfBlocks += $hours;

            if ($hours !== $assistant['hours']) {
                $onHalfBoundary = abs(abs($raw - floor($raw)) - 0.5) < 1e-6 && abs($hours - $assistant['hours']) === 1;

                if ($onHalfBoundary) {
                    $edgeBlocks++;
                } else {
                    $problems[] = "{$assistant['name']}: total jam {$hours}, seharusnya {$assistant['hours']}";
                }
            }
        }

        $grandDays = (int) $recapSheet->getCell("{$daysLetter}{$totalRow}")->getCalculatedValue();
        $grandHours = (int) $recapSheet->getCell("{$hoursLetter}{$totalRow}")->getCalculatedValue();

        if ($grandDays !== $recap['totals']['days']) {
            $problems[] = "total hari keseluruhan {$grandDays}, seharusnya {$recap['totals']['days']}";
        }

        // Total keseluruhan = jumlah total asisten yang sudah dibulatkan (rumus template).
        if ($grandHours !== $sumOfBlocks || ($edgeBlocks === 0 && $grandHours !== $recap['totals']['hours'])) {
            $problems[] = "total jam keseluruhan {$grandHours}, seharusnya {$recap['totals']['hours']}";
        }

        if ((int) $attendanceSheet->getCell("{$daysLetter}{$totalRow}")->getValue() !== $recap['totals']['days']) {
            $problems[] = 'total hari sheet absensi tidak sama dengan sheet rekap';
        }

        $spreadsheet->disconnectWorksheets();

        if ($problems !== []) {
            @unlink($path);

            throw new RuntimeException('Verifikasi rekap Excel gagal (angka berbeda dari mesin rekap): '.implode('; ', array_slice($problems, 0, 5)));
        }
    }
}
