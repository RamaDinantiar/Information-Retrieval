<?php

namespace App\Support;

use Illuminate\Http\Middleware\TrustProxies;

/**
 * Di belakang reverse proxy/CDN/WAF, IP klien dan skema (https) hanya terbaca benar bila proxy dipercaya.
 * Tanpa itu semua pembatas per IP menjadi satu batas global, HSTS tidak terkirim, dan IP di audit log salah.
 *
 * Nilai dari config `app.trusted_proxies` (env TRUSTED_PROXIES): IP dipisah koma, atau `*` untuk semua.
 * Dibaca lewat config (bukan env() di bootstrap/app.php) agar tetap berlaku saat `config:cache`.
 */
class TrustedProxies
{
    public static function configure(?string $proxies): void
    {
        $proxies = trim((string) $proxies);

        if ($proxies === '') {
            return;
        }

        TrustProxies::at($proxies === '*' ? '*' : array_values(array_filter(array_map('trim', explode(',', $proxies)))));
    }
}
