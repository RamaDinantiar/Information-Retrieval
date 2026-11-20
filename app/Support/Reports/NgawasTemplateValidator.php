<?php

namespace App\Support\Reports;

use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use Throwable;

/**
 * Validasi struktur minimal template rekap ngawas (FR-RJN-12): dua sheet
 * bernama sama, header di baris 13, dan legenda di A79:B85. Dipakai saat
 * ekspor dan saat template dibuat/diganti; bila gagal, template lama tetap dipakai.
 */
class NgawasTemplateValidator
{
    /**
     * @return list<string> daftar masalah; kosong = valid
     */
    public static function validate(string $path): array
    {
        try {
            // Dipaksa sebagai xlsx: pembaca otomatis akan menebak CSV/HTML untuk berkas teks sembarang.
            $reader = new XlsxReader;

            if (! $reader->canRead($path)) {
                return ['File template tidak dapat dibaca: bukan berkas .xlsx yang valid.'];
            }

            $spreadsheet = $reader->load($path);
        } catch (Throwable $e) {
            return ['File template tidak dapat dibaca sebagai .xlsx: '.$e->getMessage()];
        }

        try {
            return self::check($spreadsheet, $path);
        } finally {
            // Tanpa ini setiap validasi menahan ~5 MB (referensi melingkar PhpSpreadsheet) dan worker antrean membengkak.
            $spreadsheet->disconnectWorksheets();
        }
    }

    /**
     * @return list<string>
     */
    private static function check(Spreadsheet $spreadsheet, string $path): array
    {
        $errors = [];

        if ($spreadsheet->getSheetCount() !== 2) {
            return ['Template harus memiliki tepat 2 sheet.'];
        }

        $recap = $spreadsheet->getSheet(NgawasTemplate::RECAP);
        $attendance = $spreadsheet->getSheet(NgawasTemplate::ATTENDANCE);

        if ($recap->getTitle() !== NgawasTemplate::SHEET_RECAP) {
            $errors[] = 'Sheet 1 harus bernama persis "'.NgawasTemplate::SHEET_RECAP.'" (termasuk spasi di akhir).';
        }

        if ($attendance->getTitle() !== NgawasTemplate::SHEET_ATTENDANCE) {
            $errors[] = 'Sheet 2 harus bernama persis "'.NgawasTemplate::SHEET_ATTENDANCE.'".';
        }

        foreach ([$recap, $attendance] as $sheet) {
            $title = trim($sheet->getTitle());

            foreach (['A' => 'NO', 'B' => 'NAMA MAHASISWA', 'C' => 'NIM', 'D' => 'MATA KULIAH'] as $col => $label) {
                if (mb_strtoupper(trim((string) $sheet->getCell($col.NgawasTemplate::HEADER_ROW)->getValue())) !== $label) {
                    $errors[] = "Sheet \"{$title}\": header baris 13 kolom {$col} harus \"{$label}\".";
                }
            }

            if (! str_contains(mb_strtoupper((string) $sheet->getCell('E'.NgawasTemplate::HEADER_ROW)->getValue()), 'TANGGAL')) {
                $errors[] = "Sheet \"{$title}\": header tanggal (E13) tidak ditemukan.";
            }
        }

        if (mb_strtolower(trim((string) $recap->getCell('A79')->getValue())) !== 'keterangan') {
            $errors[] = 'Legenda "Keterangan" harus berada di A79.';
        }

        foreach (NgawasTemplate::LEGEND as $kind => $row) {
            $fill = $recap->getStyle('A'.$row)->getFill();

            if ($fill->getFillType() !== Fill::FILL_SOLID) {
                $errors[] = "Warna legenda \"{$kind}\" (A{$row}) tidak ditemukan.";
            }

            if (trim((string) $recap->getCell('B'.$row)->getValue()) === '') {
                $errors[] = "Teks legenda \"{$kind}\" (B{$row}) kosong.";
            }
        }

        if (trim((string) $recap->getCell('A'.NgawasTemplate::ADDITIONAL_TABLE['title'])->getValue()) !== 'Kelas Tambahan'
            || trim((string) $recap->getCell('A'.NgawasTemplate::REPLACEMENT_TABLE['title'])->getValue()) !== 'Kelas Pengganti') {
            $errors[] = 'Judul tabel "Kelas Tambahan" (A87) dan "Kelas Pengganti" (A92) tidak ditemukan.';
        }

        if (mb_strtoupper(trim((string) $attendance->getCell('A'.NgawasTemplate::TOTAL_ROW)->getValue())) !== 'TOTAL') {
            $errors[] = 'Baris TOTAL sheet 2 harus berada di A'.NgawasTemplate::TOTAL_ROW.'.';
        }

        $shapes = XlsxShapes::read($path);

        foreach (NgawasTemplate::APPROVER_SHAPES as $sheet => $names) {
            $xml = implode('', $shapes[$sheet] ?? []);

            foreach (array_unique($names) as $approver) {
                if (! str_contains($xml, NgawasTemplate::token($approver, 'jabatan')) || ! str_contains($xml, NgawasTemplate::token($approver, 'nama'))) {
                    $errors[] = 'Kotak teks pengesah '.$approver.' (penanda jabatan/nama) tidak ditemukan pada sheet '.($sheet + 1).'.';
                }
            }
        }

        return $errors;
    }
}
