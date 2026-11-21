# Audit Pra-Deploy — 00 Baseline

Kondisi awal project sebelum audit. Tidak ada kode yang diubah untuk menghasilkan laporan ini.

- **Tanggal:** 2026-11-21
- **Branch:** `chore/pre-deploy-audit`, dari `main` @ `164cc72` (sudah memuat `feat/single-bendahara`)
- **Lingkungan:** PHP 8.4.25, Laravel 13.34.0, Node 24.21.0

## Ringkasan

| Pemeriksaan | Perintah | Hasil |
|---|---|---|
| Tes backend | `php artisan test` | Lolos: 533 tes, 533 lolos, 0 gagal, 11.354 assertion (45,5 dtk) |
| Analisis statis | `vendor/bin/phpstan` | Lolos: 0 error |
| Gaya kode PHP | `vendor/bin/pint --test` | Lolos |
| Lint + format front-end | `npm run check` (`vp check`) | Lolos: 249 file terformat, 0 peringatan/error lint di 216 file |
| Tipe TypeScript | `npm run types:check` (`tsc --noEmit`) | Lolos |
| Build front-end | `npm run build` | Lolos (4,2 dtk) |
| Audit Composer | `composer audit` | **Gagal: 12 advisory di 7 paket** |
| Audit npm | `npm audit` | **Gagal: 10 kerentanan (5 critical, 1 high, 4 moderate)** |

Catatan:

- `package.json` tidak punya script `lint`. Padanannya adalah `npm run check`, ditambah `npm run types:check` untuk tipe.
- Tes memakai SQLite `:memory:` (lihat `phpunit.xml`), jadi DB MySQL lokal tidak tersentuh.
- Build menulis ke `public/build/`, yang tidak dilacak git. `git status` tetap bersih setelahnya.

## Temuan: `composer audit`

12 advisory di 7 paket. Per `composer audit --no-dev`, 11 di antaranya ada di dependensi produksi. Kolom severity kosong pada sebagian besar advisory karena memang tidak diisi oleh sumbernya.

| Paket | Advisory | Severity |
|---|---|---|
| laravel/framework | CVE-2026-128083, Task Output Injection pada Concurrency Process | low |
| symfony/http-foundation | CVE-2026-121619, shortcut `private_ranges` pada trusted proxies mempercayai klien | n/a |
| symfony/http-foundation | CVE-2026-118514, request HEAD melewati opsi `methods` | n/a |
| symfony/http-foundation | (tanpa CVE) aturan path di depan PHP bisa dilewati dengan menambahkan path | n/a |
| symfony/http-foundation | (tanpa CVE) URL yang dihasilkan bisa menunjuk ke host pilihan klien | n/a |
| symfony/http-kernel | CVE-2026-123339, inline fragments membiarkan forwarded header yang tak tepercaya mengatur host/scheme/port | n/a |
| symfony/mime | CVE-2026-45070, injeksi header email lewat karakter non-token | medium |
| symfony/routing | CVE-2026-118516, placeholder host bisa cocok dengan awal path | n/a |
| symfony/serializer | CVE-2026-121623, `DataUriNormalizer` membuka data URI sebagai file lokal | n/a |
| symfony/serializer | CVE-2026-122053, dotted-key property-path traversal melewati `#[Ignore]` | n/a |
| symfony/serializer | CVE-2026-118515, serializer membangun `UploadedFile` dari data request | n/a |
| symfony/yaml | CVE-2026-123792, parse time eksponensial/kuadratik pada dokumen YAML | n/a |

Hal yang perlu ditindaklanjuti di tahap berikutnya:

- Sebagian besar berasal dari komponen Symfony, jadi kemungkinan besar diselesaikan lewat `composer update` dalam batas constraint Laravel. Perlu dicek versi perbaikannya (mis. `symfony/*` 7.4.23 / 8.1.11).
- Relevansi ke aplikasi ini perlu dinilai satu per satu. Yang paling dekat dengan fitur yang dipakai adalah `symfony/mime` (email OTP dan notifikasi) dan `http-foundation` (trusted proxies, penting bila deploy di balik proxy).

## Temuan: `npm audit`

10 kerentanan: 5 critical, 1 high, 4 moderate. Dengan `--omit=dev` tersisa 3 (2 critical, 1 high), karena `concurrently` dan `vite` tercatat di `package.json` sebagai dependensi non-dev.

| Paket | Severity | Jenis | Sumber masalah | Perbaikan |
|---|---|---|---|---|
| vite (8.3.0–8.3.2) | high | langsung | Beberapa advisory Vite (file di luar `server.fs.allow` bisa terbaca, eksekusi skrip lintas origin) dan `launch-editor` (injeksi argumen editor) | `npm audit fix` |
| concurrently (9.2.3–9.2.4, 10.0.0–10.0.5) | critical | langsung | via `shell-quote` | `npm audit fix` |
| shell-quote (1.8.4–1.10.0) | critical | transitif | `quote()` command injection lewat line terminator setelah token `{ comment }` | `npm audit fix` |
| vite-plus (<=1.0.0, terpasang 0.3.0) | critical | langsung | via `tinypool`, `@vitest/*`, `launch-editor` | Naik ke `vite-plus@1.7.0` (major) |
| tinypool (<=2.1.1) | critical | transitif | Prototype pollution yang berujung RCE lewat opsi worker | Ikut naik `vite-plus` |
| oxfmt | critical | transitif | via `tinypool` | Ikut naik `vite-plus` |
| vitest, @vitest/browser, @vitest/browser-preview, @vitest/mocker | moderate | transitif | via `vite-plus` | Ikut naik `vite-plus` |

Hal yang perlu ditindaklanjuti di tahap berikutnya:

- Sebagian besar celah ada di alat build dan dev server (Vite, vitest, tinypool), yang tidak berjalan di server produksi. Hasil build statis di `public/build/` yang dilayani. Ini penilaian awal dan perlu dipastikan saat audit dependensi.
- `npm audit fix` cukup untuk `vite`, `concurrently`, dan `shell-quote`. Sisanya (6 paket) menunggu kenaikan major `vite-plus` 0.3.0 → 1.7.0, yang berisiko mengubah perilaku `vp check` dan `vp build`, jadi perlu diuji.
