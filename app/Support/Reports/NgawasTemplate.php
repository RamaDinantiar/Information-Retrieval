<?php

namespace App\Support\Reports;

/**
 * Letak elemen di `resources/templates/rekap-ngawas.xlsx` (PRD Bagian 7). Semua
 * koordinat berlaku untuk template dengan 59 baris data; builder menggeser
 * sendiri saat jumlah baris berbeda. Posisi tanda tangan pengesah dibuat
 * konstanta di sini agar mudah digeser saat UAT (PRD 12).
 */
final class NgawasTemplate
{
    public const RESOURCE = 'templates/rekap-ngawas.xlsx';

    /** Nama sheet persis (sheet 1 memakai spasi di akhir). */
    public const SHEET_RECAP = 'Format Aslab ';

    public const SHEET_ATTENDANCE = 'Absensi Kehadiran Aslab (TTD)';

    public const RECAP = 0;

    public const ATTENDANCE = 1;

    public const HEADER_ROW = 13;

    public const DATE_ROW = 14;

    public const DAY_ROW = 15;

    public const FIRST_DATA_ROW = 16;

    /** Kolom tanggal 1 (E) dan jumlah kolom tanggal pada template (selalu 31). */
    public const FIRST_DAY_COLUMN = 5;

    public const MAX_DAYS = 31;

    /** Baris data pada template, dan baris TOTAL tepat sesudahnya (kedua sheet). */
    public const DATA_ROWS = 59;

    public const TOTAL_ROW = 75;

    /**
     * Baris contoh gaya sel per sheet: pertama, tengah, dan terakhir dalam satu blok asisten.
     *
     * @var array<int, array{first: int, middle: int, last: int}>
     */
    public const PROTOTYPES = [
        self::RECAP => ['first' => 22, 'middle' => 23, 'last' => 28],
        self::ATTENDANCE => ['first' => 16, 'middle' => 17, 'last' => 21],
    ];

    /**
     * Sel legenda sheet 1 (kolom A) yang gaya/warnanya disalin ke sel rekap.
     *
     * @var array<string, int>
     */
    public const LEGEND = ['sunday' => 80, 'holiday' => 81, 'additional' => 82, 'replacement' => 83, 'cancelled' => 84, 'exam' => 85];

    /** `outside` memakai warna "Tambahan" (keputusan P2/P3). */
    public const KIND_LEGEND = [
        'sunday' => 'sunday', 'holiday' => 'holiday', 'additional' => 'additional', 'outside' => 'additional',
        'replacement' => 'replacement', 'cancelled' => 'cancelled', 'exam' => 'exam',
    ];

    /** Judul dan baris data tabel catatan di sheet 1 (jumlah baris data pada template). */
    public const ADDITIONAL_TABLE = ['title' => 87, 'first' => 89, 'rows' => 2];

    public const REPLACEMENT_TABLE = ['title' => 92, 'first' => 94, 'rows' => 4];

    /** Baris tempat catatan "1 sks = 50 menit" berjangkar (sheet 1); ikut bergeser bersama tabel. */
    public const NOTES_ANCHOR_ROW = 98;

    /**
     * Bentuk pengesah di template: nama bentuk → nomor pengesah (1 = kiri/Dekan, 2 = kanan/Kepala Lab).
     * Teks pertama bentuk = jabatan, teks terakhir = nama.
     *
     * @var array<int, array<string, int>>
     */
    public const APPROVER_SHAPES = [
        self::RECAP => ['TextBox 1' => 1, 'TextBox 2' => 2],
        self::ATTENDANCE => ['TextBox 8' => 2],
    ];

    /**
     * Gambar tanda tangan pengesah: sel jangkar pada template (kiri atas) dan kotak maksimum (px).
     *
     * @var array<int, array<int, array{cell: string, width: int, height: int}>>
     */
    public const SIGNATURE_SLOTS = [
        self::RECAP => [
            1 => ['cell' => 'T77', 'width' => 175, 'height' => 135],
            2 => ['cell' => 'AF77', 'width' => 175, 'height' => 135],
        ],
        self::ATTENDANCE => [
            2 => ['cell' => 'AB79', 'width' => 175, 'height' => 135],
        ],
    ];

    /** Margin gambar tanda tangan asisten di dalam sel (px), PRD 7.2. */
    public const CELL_MARGIN_PX = 2;

    public static function token(int $approver, string $field): string
    {
        return '{{pengesah_'.$approver.'_'.$field.'}}';
    }

    public static function path(): string
    {
        return resource_path(self::RESOURCE);
    }
}
