<?php

namespace Tests\Feature\Asisten;

use App\Support\Reports\NgawasRecap;
use App\Support\Reports\NgawasRecapWorkbook;
use App\Support\Reports\NgawasTemplate;
use Carbon\CarbonImmutable;
use DOMDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Shared\Drawing as DrawingMath;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;
use Tests\Concerns\BuildsNgawasRecaps;
use Tests\Concerns\SeedsNgawasGoldenFixture;
use Tests\TestCase;
use ZipArchive;

/** FR-RJN-11: isi template Excel kampus (PRD Bagian 7) dan verifikasi hasilnya. */
class NgawasRecapWorkbookTest extends TestCase
{
    use BuildsNgawasRecaps;
    use RefreshDatabase;
    use SeedsNgawasGoldenFixture;

    /** @var list<string> */
    private array $paths = [];

    /** @var list<Spreadsheet> */
    private array $spreadsheets = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }

        foreach ($this->spreadsheets as $spreadsheet) {
            $spreadsheet->disconnectWorksheets();
        }

        $this->spreadsheets = [];
        $this->paths = [];
        gc_collect_cycles(); // batas memori CLI 128 MB: jangan menumpuk antar-test

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $recap
     * @param  list<array{position: string, name: string, signaturePath: string|null}>|null  $approvers
     * @param  array<int, string>  $signatures
     */
    private function build(array $recap, string $mode = 'draft', ?array $approvers = null, array $signatures = []): string
    {
        $approvers ??= [
            ['position' => 'Dekan Fakultas Telematika Energi IT PLN', 'name' => 'Dr. Contoh Dekan', 'signaturePath' => $this->track($this->signaturePng())],
            ['position' => 'Kepala Laboratorium Information Retrieval IT PLN', 'name' => 'Contoh Kalab, M.Kom.', 'signaturePath' => $this->track($this->signaturePng())],
        ];

        return $this->track((new NgawasRecapWorkbook)->build($recap, $mode, $approvers, $signatures));
    }

    private function track(string $path): string
    {
        $this->paths[] = $path;

        return $path;
    }

    private function load(string $path): Spreadsheet
    {
        return $this->spreadsheets[] = IOFactory::load($path);
    }

    private function goldenRecap(): array
    {
        $this->travelTo(CarbonImmutable::parse('2026-06-01 10:00'));
        $this->seedFromFixture();

        return NgawasRecap::build($this->semester, '2026-04');
    }

    private function assertNoOverlappingMerges(Worksheet $ws, string $label): void
    {
        $cells = [];

        foreach ($ws->getMergeCells() as $range) {
            [[$c1, $r1], [$c2, $r2]] = Coordinate::rangeBoundaries($range);

            for ($c = (int) $c1; $c <= (int) $c2; $c++) {
                for ($r = (int) $r1; $r <= (int) $r2; $r++) {
                    $this->assertArrayNotHasKey("{$c}.{$r}", $cells, "{$label}: merge {$range} tumpang tindih di ".Coordinate::stringFromColumnIndex($c).$r);
                    $cells["{$c}.{$r}"] = true;
                }
            }
        }
    }

    private function legendFill(Worksheet $ws, int $row): string
    {
        return $ws->getStyle('A'.$row)->getFill()->getStartColor()->getRGB();
    }

    /** @return array<string, int> jumlah sel berwarna di area tanggal sheet 1 */
    private function fillCounts(Worksheet $ws, int $firstRow, int $lastRow, int $days): array
    {
        $counts = [];

        for ($r = $firstRow; $r <= $lastRow; $r++) {
            for ($c = NgawasTemplate::FIRST_DAY_COLUMN; $c < NgawasTemplate::FIRST_DAY_COLUMN + $days; $c++) {
                $fill = $ws->getCell([$c, $r])->getStyle()->getFill();

                if ($fill->getFillType() !== 'none') {
                    $counts[$fill->getStartColor()->getRGB()] = ($counts[$fill->getStartColor()->getRGB()] ?? 0) + 1;
                }
            }
        }

        return $counts;
    }

    // --- Kop, kolom, rumus --------------------------------------------------

    public function test_sheet_names_header_period_and_day_numbers_follow_the_month()
    {
        $spreadsheet = $this->load($this->build($this->fakeRecap('2026-04')));
        $recap = $spreadsheet->getSheet(0);
        $attendance = $spreadsheet->getSheet(1);

        $this->assertSame('Format Aslab ', $recap->getTitle());
        $this->assertSame('Absensi Kehadiran Aslab (TTD)', $attendance->getTitle());

        foreach ([$recap, $attendance] as $sheet) {
            $this->assertSame('SEMESTER GENAP TAHUN 2025/2026', $sheet->getCell('A9')->getValue());
            $this->assertSame('SESUAI SK NOMOR: 03/SK/Genap/IRLab/2026', $sheet->getCell('A10')->getValue());
            $this->assertSame('PERIODE BULAN: APRIL 2026', $sheet->getCell('A11')->getValue());
            $this->assertSame('2026-04-01', Date::excelToDateTimeObject($sheet->getCell('E14')->getValue())->format('Y-m-d'));
            $this->assertSame(1, (int) $sheet->getCell('E15')->getValue());
            $this->assertSame(30, (int) $sheet->getCell('AH15')->getValue());
            $this->assertNull($sheet->getCell('AI15')->getValue());
        }
    }

    public function test_the_number_of_day_columns_and_total_columns_follow_the_month_length()
    {
        foreach (['2026-02' => 28, '2028-02' => 29, '2026-04' => 30, '2026-05' => 31] as $month => $days) {
            $spreadsheet = $this->load($this->build($this->fakeRecap($month)));
            $recap = $spreadsheet->getSheet(0);
            $attendance = $spreadsheet->getSheet(1);
            $lastDay = Coordinate::stringFromColumnIndex(NgawasTemplate::FIRST_DAY_COLUMN + $days - 1);
            $daysCol = Coordinate::stringFromColumnIndex(NgawasTemplate::FIRST_DAY_COLUMN + $days);
            $hoursCol = Coordinate::stringFromColumnIndex(NgawasTemplate::FIRST_DAY_COLUMN + $days + 1);

            $this->assertSame($days, (int) $recap->getCell($lastDay.'15')->getValue(), $month);
            $this->assertNull($recap->getCell($daysCol.'15')->getValue(), $month);
            $this->assertStringContainsString('TOTAL JUMLAH HARI', (string) $recap->getCell($daysCol.'13')->getValue(), $month);
            $this->assertStringContainsString('TOTAL JAM', (string) $recap->getCell($hoursCol.'13')->getValue(), $month);
            $this->assertStringContainsString('TOTAL JUMLAH HARI', (string) $attendance->getCell($daysCol.'13')->getValue(), $month);
            $this->assertSame("E13:{$lastDay}13", collect($recap->getMergeCells())->first(fn ($m) => str_starts_with($m, 'E13:')), $month);
            // Kop melebar sampai kolom total terakhir.
            $this->assertContains("A1:{$hoursCol}1", array_values($recap->getMergeCells()), $month);
            $this->assertContains("A1:{$daysCol}1", array_values($attendance->getMergeCells()), $month);
            $this->assertContains("E14:{$lastDay}14", array_values($recap->getMergeCells()), $month);

            // Merge tidak boleh saling tumpang tindih (Excel meminta "repair" bila terjadi).
            foreach ([$recap, $attendance] as $sheet) {
                $this->assertNoOverlappingMerges($sheet, $month);
            }

            // Logo kanan ikut bergeser sebanyak kolom yang dihapus.
            $logo = collect($recap->getDrawingCollection())->first(fn ($d) => $d->getName() === 'Picture 5');
            $this->assertSame(Coordinate::stringFromColumnIndex(36 - (31 - $days)).'1', $logo->getCoordinates(), $month);
        }
    }

    public function test_cells_carry_the_credit_formula_and_totals_use_ranges_that_start_at_day_one()
    {
        $spreadsheet = $this->load($this->build($this->fakeRecap('2026-04', [2, 3])));
        $ws = $spreadsheet->getSheet(0);

        // Baris 16: 2 SKS (hari 6/13/20); baris 17: 3 SKS (hari 7/14/21).
        $this->assertSame('=2*50/60', $ws->getCell('J16')->getValue());
        $this->assertSame('=3*50/60', $ws->getCell('K17')->getValue());
        $this->assertSame('=COUNT(E16:AH16)', $ws->getCell('AI16')->getValue());
        $this->assertSame('=ROUND(SUM(E16:AH17),0)', $ws->getCell('AJ16')->getValue()); // asisten 1: baris 16–17
        $this->assertSame('=ROUND(SUM(E18:AH20),0)', $ws->getCell('AJ18')->getValue()); // asisten 2: baris 18–20
        $this->assertSame('=SUM(AI16:AI20)', $ws->getCell('AI21')->getValue());
        $this->assertSame('=ROUND(SUM(AJ16:AJ20),0)', $ws->getCell('AJ21')->getValue());
    }

    public function test_assistant_blocks_are_merged_and_single_row_blocks_are_not()
    {
        $ws = $this->load($this->build($this->fakeRecap('2026-04', [3, 1, 2])))->getSheet(0);
        $merges = array_values($ws->getMergeCells());

        foreach (['A', 'B', 'C', 'AJ'] as $col) {
            $this->assertContains("{$col}16:{$col}18", $merges);
            $this->assertContains("{$col}20:{$col}21", $merges);
            $this->assertNotContains("{$col}19:{$col}19", $merges);
        }

        $this->assertSame(1, (int) $ws->getCell('A16')->getValue());
        $this->assertSame(2, (int) $ws->getCell('A19')->getValue());
        $this->assertSame(3, (int) $ws->getCell('A20')->getValue());
        $this->assertSame('ASISTEN 2', $ws->getCell('B19')->getValue());
        $this->assertSame(Border::BORDER_MEDIUM, $ws->getStyle('D19')->getBorders()->getBottom()->getBorderStyle()); // blok 1 baris ditutup
    }

    public function test_merges_never_overlap_with_many_blocks_and_removed_columns()
    {
        foreach (['2026-02', '2026-05'] as $month) {
            $spreadsheet = $this->load($this->build($this->fakeRecap($month, [4, 1, 3, 2, 5, 1, 1, 6, 2, 3, 4, 2])));

            foreach ([0, 1] as $i) {
                $this->assertNoOverlappingMerges($spreadsheet->getSheet($i), $month);
            }
        }
    }

    public function test_names_and_nim_use_the_campus_format()
    {
        $ws = $this->load($this->build($this->fakeRecap('2026-04', [1, 1])))->getSheet(0);

        $this->assertSame('ASISTEN 1', $ws->getCell('B16')->getValue());
        $this->assertSame('2023 – 31 – 001', $ws->getCell('C16')->getValue());
        $this->assertSame('MATA KULIAH 00 (A)', $ws->getCell('D16')->getValue());
    }

    public function test_an_assistant_without_classes_gets_a_dash_row()
    {
        $recap = $this->fakeRecap('2026-04', [1, 1]);
        $recap['assistants'][1]['rows'] = [['key' => '-', 'label' => '-', 'credits' => null, 'outside' => false, 'placeholder' => true, 'cells' => [], 'totalDays' => 0, 'hours' => 0.0]];
        $recap['assistants'][1]['totalDays'] = 0;
        $recap['assistants'][1]['hoursRaw'] = 0.0;
        $recap['assistants'][1]['hours'] = 0;
        $recap['totals'] = ['assistants' => 2, 'days' => $recap['assistants'][0]['totalDays'], 'hoursRaw' => $recap['assistants'][0]['hoursRaw'], 'hours' => $recap['assistants'][0]['hours']];

        $ws = $this->load($this->build($recap))->getSheet(0);

        $this->assertSame('-', $ws->getCell('D17')->getValue());
        $this->assertSame('ASISTEN 2', $ws->getCell('B17')->getValue());
        $this->assertSame(0, (int) $ws->getCell('AI17')->getCalculatedValue());
    }

    // --- Golden April -------------------------------------------------------

    public function test_the_april_golden_workbook_reproduces_the_campus_colours_and_totals()
    {
        $recap = $this->goldenRecap();
        $spreadsheet = $this->load($this->build($recap));
        $ws = $spreadsheet->getSheet(0);

        $this->assertSame(59, NgawasTemplate::DATA_ROWS); // April = 9 asisten, 59 baris: tanpa sisip/hapus baris
        // Sama dengan jumlah sel berwarna pada rekap April kampus: libur 59, Minggu 236, ditiadakan 11, pengganti 19.
        $this->assertSame(['FF0000' => 59, '0070C0' => 236, 'C55911' => 11, '70AD47' => 19], $this->fillCounts($ws, 16, 74, 30));

        $this->assertSame(247, (int) $ws->getCell('AI75')->getCalculatedValue());
        $this->assertSame(495, (int) $ws->getCell('AJ75')->getCalculatedValue());
        $this->assertSame('ASISTEN 1', $ws->getCell('B16')->getValue());
        $this->assertSame(55, (int) $ws->getCell('AJ16')->getCalculatedValue());
        $this->assertSame(62, (int) $ws->getCell('AJ29')->getCalculatedValue()); // asisten 3
        $this->assertSame(45, (int) $ws->getCell('AJ62')->getCalculatedValue()); // asisten 8
    }

    public function test_cancelled_cells_are_coloured_but_empty()
    {
        $recap = $this->goldenRecap();
        $ws = $this->load($this->build($recap))->getSheet(0);
        $empty = 0;

        for ($r = 16; $r <= 74; $r++) {
            for ($c = 5; $c < 35; $c++) {
                $cell = $ws->getCell([$c, $r]);

                if ($cell->getStyle()->getFill()->getStartColor()->getRGB() === 'C55911') {
                    $this->assertNull($cell->getValue());
                    $empty++;
                }
            }
        }

        $this->assertSame(11, $empty);
    }

    public function test_replacement_table_lists_the_five_april_entries_and_pushes_the_note_down()
    {
        $recap = $this->goldenRecap();
        $spreadsheet = $this->load($this->build($recap));
        $ws = $spreadsheet->getSheet(0);

        // Template memuat 4 baris pengganti; April butuh 5 → 1 baris disisipkan di tabel.
        $this->assertSame('Kelas Pengganti', $ws->getCell('A92')->getValue());
        $dates = [];

        for ($r = 94; $r <= 98; $r++) {
            $dates[] = Date::excelToDateTimeObject($ws->getCell("A{$r}")->getValue())->format('Y-m-d');
            $this->assertNotSame('', (string) $ws->getCell("B{$r}")->getValue());
        }

        $this->assertSame(array_fill(0, 5, '2026-04-30'), $dates);
        $this->assertSame('10.10 - 11.50', $ws->getCell('C94')->getValue());
        $this->assertSame('Pengganti dari Kelas Senin, 27 April 2026', $ws->getCell('D94')->getValue());
        $this->assertSame($ws->getStyle('A94')->getNumberFormat()->getFormatCode(), $ws->getStyle('A98')->getNumberFormat()->getFormatCode());
        $this->assertSame($ws->getStyle('B94')->getBorders()->getBottom()->getBorderStyle(), $ws->getStyle('B98')->getBorders()->getBottom()->getBorderStyle());

        // Catatan "1 sks = 50 menit" berjangkar di bawah tabel: turun 1 baris (0-based 98 → 99).
        $notes = $this->drawingAnchors($spreadsheet, $this->paths[array_key_last($this->paths)], 0)['TextBox 10'];
        $this->assertSame(99, $notes['fromRow']);
    }

    public function test_sheet_two_mirrors_the_row_order_and_carries_a_signature_per_attended_cell()
    {
        $recap = $this->goldenRecap();
        $signatures = [];
        $png = $this->track($this->signaturePng());

        foreach ($recap['assistants'] as $i => $assistant) {
            if ($i !== 3) { // asisten 4 belum mengunggah tanda tangan
                $signatures[$assistant['userId']] = $png;
            }
        }

        $spreadsheet = $this->load($this->build($recap, 'draft', null, $signatures));
        $one = $spreadsheet->getSheet(0);
        $two = $spreadsheet->getSheet(1);

        for ($r = 16; $r <= 74; $r++) {
            $this->assertSame($one->getCell("D{$r}")->getValue(), $two->getCell("D{$r}")->getValue(), "baris {$r}");
        }

        $expected = 0;
        foreach ($recap['assistants'] as $i => $assistant) {
            if ($i === 3) {
                continue;
            }
            foreach ($assistant['rows'] as $row) {
                $expected += count(array_filter($row['cells'], fn (array $c) => $c['counts']));
            }
        }

        $pictures = collect($two->getDrawingCollection())->filter(fn ($d) => $d->getName() === 'Tanda tangan');
        $this->assertSame($expected, $pictures->count());

        // Tanda tangan jatuh di sel yang sama dengan jam di sheet 1, dan tidak pernah di sel ditiadakan/kosong.
        foreach ($pictures as $drawing) {
            $address = $drawing->getCoordinates();
            $this->assertStringStartsWith('=', (string) $one->getCell($address)->getValue(), "gambar di {$address} tanpa jam di sheet 1");
        }

        $first = $pictures->first(fn ($d) => $d->getCoordinates() === 'J16');
        $this->assertNotNull($first);
        $cellW = DrawingMath::cellDimensionToPixels($two->getColumnDimension('J')->getWidth(), $spreadsheet->getDefaultStyle()->getFont());
        $cellH = DrawingMath::pointsToPixels($two->getRowDimension(16)->getRowHeight());
        $this->assertGreaterThanOrEqual(2, $first->getOffsetX());
        $this->assertGreaterThanOrEqual(2, $first->getOffsetY());
        $this->assertLessThanOrEqual($cellW - 4, $first->getWidth());
        $this->assertLessThanOrEqual($cellH - 4, $first->getHeight());

        // Total hari ditulis sebagai angka, bukan rumus COUNT.
        $this->assertSame($recap['assistants'][0]['rows'][0]['totalDays'], $two->getCell('AI16')->getValue());
        $this->assertSame('TOTAL', $two->getCell('A75')->getValue());
        $this->assertSame($recap['totals']['days'], $two->getCell('AI75')->getValue());
    }

    public function test_the_same_signature_image_is_stored_once_in_the_package()
    {
        $recap = $this->goldenRecap();
        $png = $this->track($this->signaturePng());
        $signatures = collect($recap['assistants'])->mapWithKeys(fn ($a) => [$a['userId'] => $png])->all();

        $path = $this->build($recap, 'final', null, $signatures);
        $zip = new ZipArchive;
        $zip->open($path);
        $media = array_filter(array_map(fn ($i) => $zip->getNameIndex($i), range(0, $zip->numFiles - 1)), fn ($n) => str_starts_with((string) $n, 'xl/media/'));
        $zip->close();

        $this->assertLessThanOrEqual(5, count($media)); // 2 logo + tanda tangan (+ pengesah), bukan ratusan salinan
    }

    // --- Warna dan baris khusus ---------------------------------------------

    public function test_special_cells_use_the_legend_colours()
    {
        $recap = $this->fakeRecap('2026-04', [3], [
            '0.0.6' => ['kind' => 'additional', 'status' => 'additional'],
            '0.1.7' => ['kind' => 'replacement', 'status' => 'replacement'],
            '0.2.8' => ['kind' => 'outside', 'outsideLab' => true],
            '0.0.13' => ['kind' => 'exam', 'status' => 'exam'],
            '0.1.14' => ['kind' => 'cancelled', 'status' => 'cancelled'],
        ]);
        $ws = $this->load($this->build($recap))->getSheet(0);
        $fill = fn (string $a) => $ws->getStyle($a)->getFill()->getStartColor()->getRGB();

        $this->assertSame('FFC000', $fill('J16')); // Tambahan
        $this->assertSame('70AD47', $fill('K17')); // Pengganti
        $this->assertSame('FFC000', $fill('L18')); // Di luar Lab IR memakai warna "Tambahan"
        $this->assertSame('AFABAB', $fill('Q16')); // UTS/UAS
        $this->assertSame('C55911', $fill('R17')); // Ditiadakan
        $this->assertNull($ws->getCell('R17')->getValue());
        $this->assertSame('0070C0', $fill('I16')); // kolom Minggu 5
        $this->assertSame('FF0000', $fill('G17')); // kolom libur 3
    }

    public function test_the_legend_keeps_its_colours_and_text_when_rows_are_added_or_removed()
    {
        foreach ([[10, 4], [8, 8], [20, 7]] as [$assistants, $rowsEach]) { // 40, 64, 140 baris
            $rows = array_fill(0, $assistants, $rowsEach);
            $total = array_sum($rows);
            $delta = $total - NgawasTemplate::DATA_ROWS;
            $ws = $this->load($this->build($this->fakeRecap('2026-04', $rows)))->getSheet(0);

            $this->assertSame('TOTAL', 'TOTAL');
            $this->assertSame('Keterangan', $ws->getCell('A'.(79 + $delta))->getValue(), "{$total} baris");

            foreach (NgawasTemplate::LEGEND as $kind => $row) {
                $this->assertSame(['sunday' => '0070C0', 'holiday' => 'FF0000', 'additional' => 'FFC000', 'replacement' => '70AD47', 'cancelled' => 'C55911', 'exam' => 'AFABAB'][$kind], $this->legendFill($ws, $row + $delta), "{$kind} @ {$total} baris");
                $this->assertNotSame('', trim((string) $ws->getCell('B'.($row + $delta))->getValue()));
            }

            $this->assertSame('Kelas Tambahan', $ws->getCell('A'.(87 + $delta))->getValue());
            $this->assertSame('Kelas Pengganti', $ws->getCell('A'.(92 + $delta))->getValue());
            $this->assertStringStartsWith('=SUM(AI16:AI', (string) $ws->getCell('AI'.(16 + $total))->getValue());
        }
    }

    public function test_additional_class_table_grows_beyond_its_template_rows()
    {
        $additional = [];
        foreach ([21, 22, 23] as $d) {
            $additional[] = ['date' => "2026-04-{$d}", 'class' => 'Teknologi Blockchain C', 'time' => '07.10 - 09.50', 'note' => 'Kelas tambahan'];
        }

        $spreadsheet = $this->load($this->build($this->fakeRecap('2026-04', [2], [], $additional)));
        $ws = $spreadsheet->getSheet(0);
        $delta = 2 - NgawasTemplate::DATA_ROWS; // 2 baris data

        $this->assertSame('Kelas Tambahan', $ws->getCell('A'.(87 + $delta))->getValue());
        $first = 89 + $delta;

        foreach ([0, 1, 2] as $i) {
            $this->assertSame('2026-04-'.(21 + $i), Date::excelToDateTimeObject($ws->getCell('A'.($first + $i))->getValue())->format('Y-m-d'));
            $this->assertSame('Teknologi Blockchain C', $ws->getCell('B'.($first + $i))->getValue());
        }

        // Tabel Pengganti turun 1 baris karena Tambahan membesar dari 2 ke 3.
        $this->assertSame('Kelas Pengganti', $ws->getCell('A'.(92 + $delta + 1))->getValue());
    }

    public function test_empty_tables_keep_their_template_rows_blank()
    {
        $ws = $this->load($this->build($this->fakeRecap('2026-04', [2])))->getSheet(0);
        $delta = 2 - NgawasTemplate::DATA_ROWS;

        foreach ([89, 90, 94, 95, 96, 97] as $row) {
            $this->assertNull($ws->getCell('B'.($row + $delta))->getValue());
        }
    }

    // --- Draft, Final, pengesah ---------------------------------------------

    public function test_draft_is_marked_and_has_no_approver_signatures()
    {
        $spreadsheet = $this->load($this->build($this->fakeRecap('2026-04'), 'draft'));

        foreach ([0, 1] as $i) {
            $this->assertStringContainsString('DRAFT', $spreadsheet->getSheet($i)->getHeaderFooter()->getOddHeader());
            $names = collect($spreadsheet->getSheet($i)->getDrawingCollection())->map(fn ($d) => $d->getName());
            $this->assertEmpty($names->filter(fn ($n) => str_starts_with($n, 'Tanda tangan pengesah')));
        }
    }

    public function test_final_places_the_approver_signatures_at_the_configured_cells()
    {
        $recap = $this->fakeRecap('2026-04', [2]); // 2 baris data: seluruh area bawah bergeser -57
        $delta = 2 - NgawasTemplate::DATA_ROWS;
        $spreadsheet = $this->load($this->build($recap, 'final'));

        $byName = fn (int $sheet) => collect($spreadsheet->getSheet($sheet)->getDrawingCollection())->keyBy(fn ($d) => $d->getName());

        $this->assertSame('T'.(77 + $delta), $byName(0)['Tanda tangan pengesah 1']->getCoordinates());
        $this->assertSame('AF'.(77 + $delta), $byName(0)['Tanda tangan pengesah 2']->getCoordinates());
        $this->assertSame('AB'.(79 + $delta), $byName(1)['Tanda tangan pengesah 2']->getCoordinates());
        $this->assertFalse($byName(1)->has('Tanda tangan pengesah 1'));

        foreach ([0, 1] as $i) {
            $this->assertSame('', $spreadsheet->getSheet($i)->getHeaderFooter()->getOddHeader());
        }

        $this->assertLessThanOrEqual(175, $byName(0)['Tanda tangan pengesah 1']->getWidth());
        $this->assertLessThanOrEqual(135, $byName(0)['Tanda tangan pengesah 1']->getHeight());
    }

    public function test_a_missing_approver_signature_is_simply_left_out()
    {
        $approvers = [
            ['position' => 'Dekan', 'name' => 'A', 'signaturePath' => null],
            ['position' => 'Kalab', 'name' => 'B', 'signaturePath' => $this->track($this->signaturePng())],
        ];
        $names = collect($this->load($this->build($this->fakeRecap(), 'final', $approvers))->getSheet(0)->getDrawingCollection())->map(fn ($d) => $d->getName());

        $this->assertFalse($names->contains('Tanda tangan pengesah 1'));
        $this->assertTrue($names->contains('Tanda tangan pengesah 2'));
    }

    // --- Bentuk template (TextBox, konektor) ---------------------------------

    /**
     * @return array<string, array{fromCol: int, fromRow: int, toCol: int|null, toRow: int|null, texts: list<string>, id: string}>
     */
    private function drawingAnchors(Spreadsheet $spreadsheet, string $path, int $sheet): array
    {
        $zip = new ZipArchive;
        $zip->open($path);
        $xml = (string) $zip->getFromName('xl/drawings/drawing'.($sheet + 1).'.xml');
        $zip->close();

        $doc = new DOMDocument;
        $doc->loadXML($xml);
        $xp = new \DOMXPath($doc);
        $xp->registerNamespace('xdr', 'http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing');
        $xp->registerNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');
        $result = [];

        foreach ($xp->query('/xdr:wsDr/*') as $anchor) {
            $name = $xp->query('.//xdr:cNvPr', $anchor)->item(0)?->getAttribute('name');

            if ($name === null || $xp->query('.//xdr:pic', $anchor)->length > 0) {
                continue;
            }

            $val = fn (string $path) => $xp->query($path, $anchor)->item(0)?->nodeValue;
            $texts = [];
            foreach ($xp->query('.//a:t', $anchor) as $t) {
                $texts[] = $t->nodeValue;
            }

            $result[$name] = [
                'fromCol' => (int) $val('xdr:from/xdr:col'), 'fromRow' => (int) $val('xdr:from/xdr:row'),
                'toCol' => $val('xdr:to/xdr:col') === null ? null : (int) $val('xdr:to/xdr:col'),
                'toRow' => $val('xdr:to/xdr:row') === null ? null : (int) $val('xdr:to/xdr:row'),
                'texts' => $texts, 'id' => (string) $xp->query('.//xdr:cNvPr', $anchor)->item(0)?->getAttribute('id'),
            ];
        }

        return $result;
    }

    public function test_text_boxes_and_the_header_line_survive_and_carry_the_approver_names()
    {
        $path = $this->build($this->fakeRecap('2026-05'));
        $shapes = $this->drawingAnchors($this->load($path), $path, 0);

        $this->assertEqualsCanonicalizing(['TextBox 1', 'TextBox 2', 'TextBox 10', 'Straight Connector 8'], array_keys($shapes));
        $this->assertSame(['Dekan Fakultas Telematika Energi IT PLN', 'Dr. Contoh Dekan'], $shapes['TextBox 1']['texts']);
        $this->assertSame(['Kepala Laboratorium Information Retrieval IT PLN', 'Contoh Kalab, M.Kom.'], $shapes['TextBox 2']['texts']);
        $this->assertContains('  1 sks = 50 menit', $shapes['TextBox 10']['texts']);

        $sheetTwo = $this->drawingAnchors($this->load($path), $path, 1);
        $this->assertSame(['Kepala Laboratorium Information Retrieval IT PLN', 'Contoh Kalab, M.Kom.'], $sheetTwo['TextBox 8']['texts']);

        $this->assertStringNotContainsString('{{', (string) json_encode([$shapes, $sheetTwo]));
    }

    public function test_approver_names_with_special_characters_are_escaped()
    {
        $approvers = [
            ['position' => 'Dekan & "Wakil" <FTE>', 'name' => "Dr. A & B <S.T.> 'M.Kom'", 'signaturePath' => null],
            ['position' => 'Kalab', 'name' => 'C', 'signaturePath' => null],
        ];
        $path = $this->build($this->fakeRecap(), 'draft', $approvers);

        $this->assertSame(['Dekan & "Wakil" <FTE>', "Dr. A & B <S.T.> 'M.Kom'"], $this->drawingAnchors($this->load($path), $path, 0)['TextBox 1']['texts']);
    }

    public function test_shapes_move_with_the_data_rows_and_the_right_edge_shrinks_with_the_removed_columns()
    {
        $may = $this->build($this->fakeRecap('2026-05', [2, 2])); // 31 hari, 4 baris: delta -55
        $feb = $this->build($this->fakeRecap('2026-02', [2, 2])); // 28 hari: 3 kolom dihapus
        $delta = 4 - NgawasTemplate::DATA_ROWS;

        $a = $this->drawingAnchors($this->load($may), $may, 0);
        $b = $this->drawingAnchors($this->load($feb), $feb, 0);

        $this->assertSame(77 + $delta, $a['TextBox 1']['fromRow']);
        $this->assertSame(77 + $delta, $a['TextBox 2']['fromRow']);
        $this->assertSame(98 + $delta, $a['TextBox 10']['fromRow']);
        $this->assertSame(6, $a['Straight Connector 8']['fromRow']); // garis kop tidak bergeser
        $this->assertSame(37, $a['TextBox 2']['toCol']);
        $this->assertSame(37, $a['Straight Connector 8']['toCol']);
        $this->assertSame(34, $b['TextBox 2']['toCol']); // 37 - 3
        $this->assertSame(34, $b['Straight Connector 8']['toCol']);
        $this->assertSame($a['TextBox 1']['toRow'] - $a['TextBox 1']['fromRow'], $b['TextBox 1']['toRow'] - $b['TextBox 1']['fromRow']); // tinggi kotak tetap
        $this->assertSame(27, $b['TextBox 1']['toCol']);
    }

    public function test_drawing_ids_are_unique_so_excel_does_not_ask_for_repair()
    {
        $path = $this->build($this->fakeRecap('2026-04', [3, 3]), 'final', null, [1 => $this->track($this->signaturePng()), 2 => $this->track($this->signaturePng())]);
        $zip = new ZipArchive;
        $zip->open($path);

        foreach ([1, 2] as $n) {
            preg_match_all('/<xdr:cNvPr id="(\d+)" name="([^"]*)"/', (string) $zip->getFromName("xl/drawings/drawing{$n}.xml"), $m, PREG_SET_ORDER);
            $byId = [];
            foreach ($m as [, $id, $name]) {
                $byId[$id][] = $name;
            }

            foreach ($byId as $id => $names) {
                // Satu-satunya ID kembar yang sah: pasangan Choice/Fallback dari satu kotak teks.
                $this->assertSame(1, count(array_unique($names)), "ID {$id} dipakai bentuk berbeda di drawing{$n}: ".implode(',', $names));
            }
        }

        $zip->close();
    }

    // --- Tata letak, cetak, paket ---------------------------------------------

    public function test_print_setup_fonts_widths_and_logos_come_from_the_template()
    {
        $template = $this->load(NgawasTemplate::path());
        $output = $this->load($this->build($this->fakeRecap('2026-05', [4, 4, 4])));

        foreach ([0, 1] as $i) {
            $t = $template->getSheet($i);
            $o = $output->getSheet($i);

            $this->assertSame($t->getPageSetup()->getOrientation(), $o->getPageSetup()->getOrientation());
            $this->assertSame($t->getPageSetup()->getPaperSize(), $o->getPageSetup()->getPaperSize());
            $this->assertSame($t->getPageSetup()->getScale(), $o->getPageSetup()->getScale());
            $this->assertSame($t->getPageSetup()->getFitToWidth(), $o->getPageSetup()->getFitToWidth());
            $this->assertSame($t->getPageSetup()->getFitToHeight(), $o->getPageSetup()->getFitToHeight());
            $this->assertSame($t->getPageMargins()->getLeft(), $o->getPageMargins()->getLeft());
            $this->assertSame($t->getPageSetup()->getHorizontalCentered(), $o->getPageSetup()->getHorizontalCentered());
            $this->assertSame($t->getSheetView()->getZoomScale(), $o->getSheetView()->getZoomScale());

            foreach (['A', 'B', 'C', 'D', 'E'] as $col) {
                $this->assertEqualsWithDelta($t->getColumnDimension($col)->getWidth(), $o->getColumnDimension($col)->getWidth(), 0.001, "kolom {$col} sheet {$i}");
            }

            $this->assertSame($t->getStyle('A13')->getFont()->getName(), $o->getStyle('A13')->getFont()->getName());
            $this->assertSame($t->getStyle('A13')->getFill()->getStartColor()->getRGB(), $o->getStyle('A13')->getFill()->getStartColor()->getRGB());
            $this->assertSame(['A1'], collect($o->getDrawingCollection())->filter(fn ($d) => str_starts_with($d->getName(), 'Picture') && $d->getCoordinates() === 'A1')->map(fn ($d) => $d->getCoordinates())->values()->all());
            $this->assertCount(2, collect($o->getDrawingCollection())->filter(fn ($d) => str_starts_with($d->getName(), 'Picture')));
        }
    }

    public function test_every_xml_part_is_well_formed_and_the_file_reopens()
    {
        $path = $this->build($this->goldenRecap(), 'final');
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);

            if (str_ends_with($name, '.xml') || str_ends_with($name, '.rels')) {
                $doc = new DOMDocument;
                $this->assertTrue($doc->loadXML((string) $zip->getFromIndex($i), LIBXML_NONET), "XML tidak valid: {$name}");
            }
        }

        $zip->close();
        $this->assertSame(2, $this->load($path)->getSheetCount());
    }

    public function test_the_filename_follows_the_campus_convention()
    {
        $this->assertSame('Rekap_Ngawas_LAB_IR_APRIL_2026.xlsx', NgawasRecapWorkbook::filename('APRIL 2026'));
    }

    // --- Verifikasi dan penolakan -------------------------------------------

    public function test_a_mismatch_between_the_recap_and_the_workbook_fails_the_export()
    {
        $recap = $this->fakeRecap('2026-04', [1, 2]); // asisten 1: 3 sesi × 1,6667 = 5,00 jam (bukan batas .5)
        $recap['assistants'][0]['hours'] += 1; // angka mesin sengaja berbeda dari hasil rumus Excel
        $recap['totals']['hours'] += 1;

        $before = glob(sys_get_temp_dir().'/ngawas*.xlsx') ?: [];

        try {
            (new NgawasRecapWorkbook)->build($recap, 'draft', [], []);
            $this->fail('Seharusnya gagal');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Verifikasi rekap Excel gagal', $e->getMessage());
            $this->assertStringContainsString('ASISTEN 1', $e->getMessage());
        }

        $this->assertSame([], array_values(array_diff(glob(sys_get_temp_dir().'/ngawas*.xlsx') ?: [], $before)), 'file hasil yang gagal tidak boleh tertinggal');
    }

    public function test_a_total_exactly_on_a_half_hour_boundary_may_round_either_way_but_not_by_more_than_one()
    {
        // Asisten 1: 3 × 1,6667 + 3 × 2,5 = 12,5 jam. Excel membulatkan ke 13; PhpSpreadsheet bisa 12 akibat galat float.
        $recap = $this->fakeRecap('2026-04', [2]);
        $this->assertSame(13, $recap['assistants'][0]['hours']);
        $this->assertFileExists($this->build($recap));

        $recap['assistants'][0]['hours'] = 15; // selisih 2 tidak pernah sah
        $recap['totals']['hours'] = 15;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Verifikasi rekap Excel gagal');

        (new NgawasRecapWorkbook)->build($recap, 'draft', [], []);
    }

    public function test_an_invalid_template_is_rejected_with_a_clear_message()
    {
        $copy = $this->track(tempnam(sys_get_temp_dir(), 'tpl').'.xlsx');
        $spreadsheet = IOFactory::load(NgawasTemplate::path());
        $spreadsheet->getSheet(0)->setTitle('Salah');
        IOFactory::createWriter($spreadsheet, 'Xlsx')->save($copy);

        try {
            (new NgawasRecapWorkbook)->build($this->fakeRecap(), 'draft', [], [], $copy);
            $this->fail('Seharusnya gagal');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Template rekap tidak valid', $e->getMessage());
            $this->assertStringContainsString('Format Aslab', $e->getMessage());
        }
    }

    public function test_a_recap_without_assistants_is_rejected()
    {
        $recap = $this->fakeRecap('2026-04', []);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Tidak ada asisten');

        (new NgawasRecapWorkbook)->build($recap, 'draft', [], []);
    }
}
