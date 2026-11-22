<?php

namespace App\Console\Commands;

use App\Support\Reports\NgawasTemplate;
use App\Support\Reports\NgawasTemplateValidator;
use App\Support\Reports\XlsxShapes;
use DOMElement;
use Illuminate\Console\Command;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use ZipArchive;

/**
 * Membuat `resources/templates/rekap-ngawas.xlsx` dari rekap kampus yang sudah
 * ada (Catatan B2, keputusan P7: dibuat dari file Mei 2026). Data dikosongkan;
 * kop, logo, legenda, gaya sel, lebar kolom, merge header, dan pengaturan cetak
 * tetap. Jabatan/nama pengesah pada kotak teks diganti penanda. Dijalankan
 * sekali; file sumber berisi nama dan NIM asli dan TIDAK boleh masuk repo.
 *
 * ALAT PENGEMBANGAN SEKALI PAKAI, bukan bagian operasi produksi: tidak dijadwalkan
 * dan tidak perlu dijalankan saat deploy. Dipertahankan agar template bisa dibuat
 * ulang bila kampus mengganti format rekap. Cara memakai:
 *
 *   php artisan ngawas:make-template /path/ke/Rekap_Kampus.xlsx
 *   php artisan ngawas:make-template /path/ke/Rekap_Kampus.xlsx --output=/tmp/uji.xlsx
 *
 * Tanpa --output, hasil menimpa `resources/templates/rekap-ngawas.xlsx` (lihat
 * NgawasTemplate::path()). Perintah menolak hasil yang masih memuat data pribadi
 * atau tidak lolos NgawasTemplateValidator, dan tidak menulis berkas dalam kasus itu.
 * Admin juga bisa mengunggah template pengganti di Pengaturan Sistem tanpa perintah ini.
 */
class MakeNgawasTemplate extends Command
{
    protected $signature = 'ngawas:make-template {source : Path rekap kampus sumber (mis. Rekap_Ngawas_LAB_IR_MEI_2026.xlsx)} {--output= : Path hasil (bawaan resources/templates/rekap-ngawas.xlsx)}';

    protected $description = 'Buat template Rekap Jam Ngawas (dikosongkan) dari file rekap kampus';

    /** Baris pemisah kosong yang menyelip di sheet 2 file Mei (A62:AI62); dihapus agar sama dengan sheet 1. */
    private const SHEET2_STRAY_ROW = 62;

    public function handle(): int
    {
        $source = $this->argument('source');
        $option = $this->option('output');
        $output = is_string($option) && $option !== '' ? $option : NgawasTemplate::path();

        if (! is_string($source) || ! is_file($source)) {
            $this->error('File sumber tidak ditemukan: '.(is_string($source) ? $source : ''));

            return self::FAILURE;
        }

        $spreadsheet = IOFactory::load($source);

        if ($spreadsheet->getSheetCount() !== 2) {
            $this->error('Sumber harus memiliki 2 sheet.');

            return self::FAILURE;
        }

        $private = $this->privateStrings($spreadsheet, $source);

        $recap = $spreadsheet->getSheet(NgawasTemplate::RECAP);
        $attendance = $spreadsheet->getSheet(NgawasTemplate::ATTENDANCE);

        $this->prepareRecap($recap);
        $this->prepareAttendance($attendance);

        foreach ([$recap, $attendance] as $sheet) {
            $this->clearHeader($sheet);
            $this->dropSignaturePictures($sheet);
        }

        $spreadsheet->getProperties()
            ->setCreator('IR Lab Management System')->setLastModifiedBy('IR Lab Management System')
            ->setTitle('Template Rekap Jam Ngawas')->setSubject('')->setDescription('')->setKeywords('')->setCategory('')->setCompany('');
        $spreadsheet->setActiveSheetIndex(0);

        $tmp = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        IOFactory::createWriter($spreadsheet, 'Xlsx')->save($tmp);

        XlsxShapes::inject($tmp, XlsxShapes::read($source), function (int $sheet, DOMElement $anchor): void {
            if ($sheet === NgawasTemplate::ATTENDANCE && XlsxShapes::fromRow($anchor) >= self::SHEET2_STRAY_ROW) {
                XlsxShapes::shiftRows($anchor, -1);
            }

            $approver = NgawasTemplate::APPROVER_SHAPES[$sheet][XlsxShapes::name($anchor)] ?? null;

            if ($approver !== null) {
                $runs = XlsxShapes::textRuns($anchor);

                if (count($runs) >= 2) {
                    XlsxShapes::setText($runs[0], NgawasTemplate::token($approver, 'jabatan'));
                    XlsxShapes::setText($runs[count($runs) - 1], NgawasTemplate::token($approver, 'nama'));
                }
            }
        });

        $leaks = $this->findLeaks($tmp, $private);

        if ($leaks !== []) {
            @unlink($tmp);
            $this->error('Template masih memuat data pribadi: '.implode(', ', array_map(fn ($s) => mb_substr($s, 0, 3).'…', $leaks)));

            return self::FAILURE;
        }

        $errors = NgawasTemplateValidator::validate($tmp);

        if ($errors !== []) {
            @unlink($tmp);
            $this->error("Template hasil tidak valid:\n- ".implode("\n- ", $errors));

            return self::FAILURE;
        }

        @mkdir(dirname($output), 0775, true);
        copy($tmp, $output);
        @unlink($tmp);

        $this->info("Template dibuat: {$output} (".number_format((int) filesize($output)).' byte)');
        $this->line('Pemeriksaan data pribadi: bersih · validasi struktur: lolos. Buka di Excel untuk memastikan tidak ada peringatan "repair".');

        return self::SUCCESS;
    }

    private function prepareRecap(Worksheet $sheet): void
    {
        $lastCol = NgawasTemplate::FIRST_DAY_COLUMN + NgawasTemplate::MAX_DAYS + 1; // AK (total jam)
        $this->clearDataRegion($sheet, $lastCol);

        // Format jam seragam; warna kolom/sel dikosongkan (diisi builder dari legenda).
        for ($r = NgawasTemplate::FIRST_DATA_ROW; $r < NgawasTemplate::TOTAL_ROW; $r++) {
            for ($c = NgawasTemplate::FIRST_DAY_COLUMN; $c < NgawasTemplate::FIRST_DAY_COLUMN + NgawasTemplate::MAX_DAYS; $c++) {
                $sheet->getStyle(Coordinate::stringFromColumnIndex($c).$r)->getNumberFormat()->setFormatCode('0.00');
            }
        }

        $lastTotalCol = Coordinate::stringFromColumnIndex(NgawasTemplate::FIRST_DAY_COLUMN + NgawasTemplate::MAX_DAYS);
        $lastHoursCol = Coordinate::stringFromColumnIndex($lastCol);
        $firstData = NgawasTemplate::FIRST_DATA_ROW;
        $lastData = NgawasTemplate::TOTAL_ROW - 1;
        $total = NgawasTemplate::TOTAL_ROW;
        $sheet->setCellValue("{$lastTotalCol}{$total}", "=SUM({$lastTotalCol}{$firstData}:{$lastTotalCol}{$lastData})");
        $sheet->setCellValue("{$lastHoursCol}{$total}", "=ROUND(SUM({$lastHoursCol}{$firstData}:{$lastHoursCol}{$lastData}),0)");

        // Tabel catatan: kosongkan isi, lepas merge percontohan, tinggi baris otomatis (teks panjang membungkus).
        foreach ([NgawasTemplate::ADDITIONAL_TABLE, NgawasTemplate::REPLACEMENT_TABLE] as $table) {
            for ($r = $table['first']; $r < $table['first'] + $table['rows']; $r++) {
                foreach (['A', 'B', 'C', 'D'] as $col) {
                    $sheet->setCellValue($col.$r, null);
                }

                $sheet->getRowDimension($r)->setRowHeight(-1);
            }
        }

        foreach (array_values($sheet->getMergeCells()) as $range) {
            if (preg_match('/^A(\d+):A(\d+)$/', $range, $m) && (int) $m[1] >= NgawasTemplate::ADDITIONAL_TABLE['first'] && (int) $m[2] <= NgawasTemplate::REPLACEMENT_TABLE['first'] + NgawasTemplate::REPLACEMENT_TABLE['rows']) {
                $sheet->unmergeCells($range);
            }
        }
    }

    private function prepareAttendance(Worksheet $sheet): void
    {
        // Lepas merge baris pemisah lalu hapus agar sheet 2 = 59 baris data + TOTAL (seperti sheet 1).
        foreach (array_values($sheet->getMergeCells()) as $range) {
            if (preg_match('/^A'.self::SHEET2_STRAY_ROW.':/', $range)) {
                $sheet->unmergeCells($range);
            }
        }

        $sheet->removeRow(self::SHEET2_STRAY_ROW, 1);

        $lastCol = NgawasTemplate::FIRST_DAY_COLUMN + NgawasTemplate::MAX_DAYS; // AJ (total hari)
        $this->clearDataRegion($sheet, $lastCol);
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($lastCol).NgawasTemplate::TOTAL_ROW, null);
    }

    /** Kosongkan nilai, warna, dan merge blok pada baris data; gaya border/font dipertahankan sebagai contoh. */
    private function clearDataRegion(Worksheet $sheet, int $lastCol): void
    {
        foreach (array_values($sheet->getMergeCells()) as $range) {
            if (preg_match('/^[A-Z]+(\d+):[A-Z]+(\d+)$/', $range, $m) && (int) $m[1] >= NgawasTemplate::FIRST_DATA_ROW && (int) $m[2] < NgawasTemplate::TOTAL_ROW) {
                $sheet->unmergeCells($range);
            }
        }

        $lastDayCol = NgawasTemplate::FIRST_DAY_COLUMN + NgawasTemplate::MAX_DAYS - 1;

        for ($r = NgawasTemplate::FIRST_DATA_ROW; $r < NgawasTemplate::TOTAL_ROW; $r++) {
            for ($c = 1; $c <= $lastCol; $c++) {
                $address = Coordinate::stringFromColumnIndex($c).$r;
                $sheet->setCellValue($address, null);

                if ($c >= NgawasTemplate::FIRST_DAY_COLUMN && $c <= $lastDayCol) {
                    $sheet->getStyle($address)->getFill()->setFillType(Fill::FILL_NONE);
                }
            }
        }
    }

    /** Isi kop yang berubah tiap bulan dikosongkan; builder mengisinya. */
    private function clearHeader(Worksheet $sheet): void
    {
        $sheet->setCellValue('A9', '');
        $sheet->setCellValue('A10', 'SESUAI SK NOMOR: ');
        $sheet->setCellValue('A11', 'PERIODE BULAN: ');
        $sheet->setCellValue('E'.NgawasTemplate::DATE_ROW, Date::PHPToExcel(mktime(0, 0, 0, 1, 1, 2026)));

        for ($d = 1; $d <= NgawasTemplate::MAX_DAYS; $d++) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex(NgawasTemplate::FIRST_DAY_COLUMN + $d - 1).NgawasTemplate::DAY_ROW, $d);
        }
    }

    /** Hanya logo yang dipertahankan; gambar tanda tangan pengesah dibuang. */
    private function dropSignaturePictures(Worksheet $sheet): void
    {
        $collection = $sheet->getDrawingCollection();

        foreach ($collection->getArrayCopy() as $i => $drawing) {
            if (! str_starts_with($drawing->getName(), 'Picture')) {
                $collection->offsetUnset($i);
            }
        }
    }

    /**
     * Teks pribadi dari file sumber (nama, NIM, nama pada kotak teks) untuk pemeriksaan kebocoran.
     *
     * @return list<string>
     */
    private function privateStrings(Spreadsheet $spreadsheet, string $source): array
    {
        $strings = [];

        foreach ([NgawasTemplate::RECAP, NgawasTemplate::ATTENDANCE] as $i) {
            $sheet = $spreadsheet->getSheet($i);

            for ($r = NgawasTemplate::FIRST_DATA_ROW; $r <= NgawasTemplate::TOTAL_ROW + 1; $r++) {
                foreach (['B', 'C'] as $col) {
                    $value = trim((string) $sheet->getCell($col.$r)->getValue());

                    if ($value !== '' && mb_strtoupper($value) !== 'TOTAL') {
                        $strings[] = $value;
                    }
                }
            }
        }

        foreach (XlsxShapes::read($source) as $sheet => $anchors) {
            foreach ($anchors as $xml) {
                $root = XlsxShapes::anchor($xml);

                if (isset(NgawasTemplate::APPROVER_SHAPES[$sheet][XlsxShapes::name($root)])) {
                    foreach (XlsxShapes::textRuns($root) as $run) {
                        $strings[] = trim((string) $run->nodeValue);
                    }
                }
            }
        }

        return array_values(array_unique(array_filter($strings, fn (string $s) => mb_strlen($s) >= 4)));
    }

    /**
     * @param  list<string>  $private
     * @return list<string>
     */
    private function findLeaks(string $xlsx, array $private): array
    {
        $zip = new ZipArchive;
        $zip->open($xlsx);
        $haystack = '';

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);

            if (str_ends_with($name, '.xml') || str_ends_with($name, '.rels')) {
                $haystack .= html_entity_decode((string) $zip->getFromIndex($i), ENT_QUOTES | ENT_XML1);
            }
        }

        $zip->close();

        return array_values(array_filter($private, fn (string $s) => mb_stripos($haystack, $s) !== false));
    }
}
