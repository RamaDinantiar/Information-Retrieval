<?php

namespace App\Support\Reports;

use App\Models\Setting;

/**
 * Dua pengesah rekap ngawas (keputusan P4): jabatan, nama, dan gambar tanda
 * tangan (tanpa NIP, karena tidak ada di template). Disimpan di Pengaturan
 * Sistem pada key `ngawas_recap.approvers`.
 *
 * @phpstan-type Approver array{position: string, name: string, signature_file_id: int|null}
 */
class NgawasApprovers
{
    public const KEY = 'ngawas_recap.approvers';

    public const COUNT = 2;

    /**
     * Urutan = urutan cetak di template, kiri ke kanan: Pengesah 1 = Dekan, Pengesah 2 = Kepala Laboratorium.
     *
     * @var list<Approver>
     */
    public const DEFAULTS = [
        ['position' => 'Dekan Fakultas Telematika Energi IT PLN', 'name' => '', 'signature_file_id' => null],
        ['position' => 'Kepala Laboratorium Information Retrieval IT PLN', 'name' => '', 'signature_file_id' => null],
    ];

    /**
     * @return list<Approver>
     */
    public static function all(): array
    {
        $stored = Setting::value(self::KEY);
        $stored = is_array($stored) ? array_values($stored) : [];

        $result = [];

        foreach (self::DEFAULTS as $i => $default) {
            $row = is_array($stored[$i] ?? null) ? $stored[$i] : [];

            $result[] = [
                'position' => (string) ($row['position'] ?? $default['position']),
                'name' => (string) ($row['name'] ?? $default['name']),
                'signature_file_id' => isset($row['signature_file_id']) ? (int) $row['signature_file_id'] : null,
            ];
        }

        return $result;
    }

    /**
     * @param  Approver  $approver
     */
    public static function save(int $index, array $approver, ?int $userId): void
    {
        $all = self::all();
        $all[$index] = $approver;

        Setting::put([self::KEY => $all], $userId);
    }
}
