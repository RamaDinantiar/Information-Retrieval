<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Penanda "ada data yang berubah" untuk pembaruan langsung tanpa refresh.
 *
 * Setiap simpan/hapus model menaikkan token ini; browser yang terbuka
 * menanyakannya tiap beberapa detik (`/live/version`) dan memuat ulang data
 * halaman hanya bila token berubah. Token acak tanpa isi data, jadi aman
 * dibaca tanpa login dan tanpa menyentuh sesi (tidak memperpanjang sesi idle).
 */
class LiveVersion
{
    private const KEY = 'live.version';

    public static function current(): string
    {
        return Cache::rememberForever(self::KEY, fn (): string => (string) Str::uuid());
    }

    /** Naikkan token setelah transaksi selesai agar klien tidak membaca data setengah jadi. */
    public static function bump(): void
    {
        DB::afterCommit(fn () => Cache::forever(self::KEY, (string) Str::uuid()));
    }
}
