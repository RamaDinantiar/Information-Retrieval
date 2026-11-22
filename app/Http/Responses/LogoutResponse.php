<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\LogoutResponse as LogoutResponseContract;
use Laravel\Fortify\Fortify;

/**
 * Audit 05 W1: setelah keluar, tombol Back tidak boleh menampilkan halaman pengguna sebelumnya
 * (komputer lab dipakai bergantian). `clearHistory` membuat Inertia membuang kunci enkripsi
 * riwayat peramban, sehingga halaman lama di history tidak bisa dibaca lagi.
 * Berpasangan dengan INERTIA_ENCRYPT_HISTORY=true (lihat .env.production.example).
 */
class LogoutResponse implements LogoutResponseContract
{
    public function toResponse($request)
    {
        if ($request->wantsJson()) {
            return new JsonResponse('', 204);
        }

        Inertia::clearHistory();

        return redirect(Fortify::redirects('logout', '/'));
    }
}
