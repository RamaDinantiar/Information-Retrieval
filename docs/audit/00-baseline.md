# Audit Pra-Deploy — 00 Baseline

Kondisi awal project sebelum audit. Tidak ada kode yang diubah untuk menghasilkan laporan ini. Hasil setelah perbaikan dependensi ada di bagian "Setelah perbaikan dependensi".

- **Tanggal:** 2026-11-21
- **Branch:** `chore/pre-deploy-audit`, dari `main` @ `164cc72` (sudah memuat `feat/single-bendahara`)
- **Lingkungan:** PHP 8.4.25, Laravel 13.34.0, Node 24.21.0

## Ringkasan

| Pemeriksaan             | Perintah                               | Hasil                                                           |
| ----------------------- | -------------------------------------- | --------------------------------------------------------------- |
| Tes backend             | `php artisan test`                     | Lolos: 533 tes, 533 lolos, 0 gagal, 11.354 assertion (45,5 dtk) |
| Analisis statis         | `vendor/bin/phpstan`                   | Lolos: 0 error                                                  |
| Gaya kode PHP           | `vendor/bin/pint --test`               | Lolos                                                           |
| Lint + format front-end | `npm run check` (`vp check`)           | Lolos: 249 file terformat, 0 peringatan/error lint di 216 file  |
| Tipe TypeScript         | `npm run types:check` (`tsc --noEmit`) | Lolos                                                           |
| Build front-end         | `npm run build`                        | Lolos (4,2 dtk)                                                 |
| Audit Composer          | `composer audit`                       | **Gagal: 12 advisory di 7 paket**                               |
| Audit npm               | `npm audit`                            | **Gagal: 10 kerentanan (5 critical, 1 high, 4 moderate)**       |

Catatan:

- `package.json` tidak punya script `lint`. Padanannya adalah `npm run check`, ditambah `npm run types:check` untuk tipe.
- Tes memakai SQLite `:memory:` (lihat `phpunit.xml`), jadi DB MySQL lokal tidak tersentuh.
- Build menulis ke `public/build/`, yang tidak dilacak git. `git status` tetap bersih setelahnya.

## Setelah perbaikan dependensi (2026-11-21)

Bagian di bawah ini (kondisi awal) dibiarkan apa adanya sebagai catatan. Hasil setelah perbaikan, di branch yang sama:

| Pemeriksaan                             | Awal                                           | Sesudah                                                                      |
| --------------------------------------- | ---------------------------------------------- | ---------------------------------------------------------------------------- |
| `composer audit`                        | 12 advisory di 7 paket                         | **0** ("No security vulnerability advisories found")                         |
| `npm audit` (semua)                     | 10 kerentanan (5 critical, 1 high, 4 moderate) | **7** (3 critical, 4 moderate), semuanya alat pengembangan lewat `vite-plus` |
| `npm audit --omit=dev`                  | 3 (2 critical, 1 high)                         | **0**                                                                        |
| `php artisan test`                      | 533 lolos                                      | 554 lolos (tes audit ditambahkan), 0 gagal                                   |
| PHPStan / Pint                          | 0 error / lolos                                | 0 error / lolos                                                              |
| `npm run check`, `types:check`, `build` | lolos                                          | lolos                                                                        |

### Composer: versi sebelum → sesudah

Semua pembaruan minor/patch di dalam rentang `composer.json` (`laravel/framework: ^13.17`). Tidak ada lompatan major.

| Paket                   | Sebelum | Sesudah | Advisory yang tertutup                                         | Lingkungan  |
| ----------------------- | ------- | ------- | -------------------------------------------------------------- | ----------- |
| laravel/framework       | 13.34.0 | 13.43.0 | CVE-2026-128083 (low)                                          | produksi    |
| symfony/http-foundation | 8.1.8   | 8.1.11  | 4 advisory (CVE-2026-121619, CVE-2026-118514, dan 2 tanpa CVE) | produksi    |
| symfony/http-kernel     | 8.1.8   | 8.1.11  | CVE-2026-123339                                                | produksi    |
| symfony/mime            | 8.1.7   | 8.1.10  | CVE-2026-45070 (medium)                                        | produksi    |
| symfony/routing         | 8.1.8   | 8.1.11  | CVE-2026-118516                                                | produksi    |
| symfony/serializer      | 8.1.8   | 8.1.11  | 3 advisory (CVE-2026-121623, -122053, -118515)                 | produksi    |
| symfony/yaml            | 8.1.8   | 8.1.11  | CVE-2026-123792                                                | development |
| laravel/wayfinder       | 0.1.21  | 0.1.23  | (bukan advisory; lihat di bawah)                               | development |

Ikut naik 37 paket turunan (mis. `guzzlehttp/guzzle` 8.2.0 → 8.5.4, `nesbot/carbon` 3.14.2 → 3.15.1, `symfony/console`, `symfony/translation`, `monolog/monolog`, `ramsey/uuid`) karena Laravel 13.43 membutuhkannya.

Dua efek samping yang harus ikut diperbaiki:

- **Wayfinder:** Laravel 13.43 menambahkan metode HTTP `QUERY` pada rute. `laravel/wayfinder` 0.1.21 menghasilkan TypeScript dengan `method: 'query'` yang tidak dikenal tipe `Method`, sehingga `npm run types:check` gagal. Naik ke 0.1.23 menyelesaikannya. **Catatan urutan:** `routes/index.ts` dihasilkan saat `build`, jadi `types:check` harus dijalankan setelah `build` (atau `php artisan wayfinder:generate --with-form`), kalau tidak, ia memeriksa berkas lama.
- **PHPStan:** `MakeNgawasTemplate` memakai `(string) $this->argument()`; Laravel 13.43 memperketat tipe kembaliannya. Tipenya kini divalidasi.

### npm: versi sebelum → sesudah

Semua pembaruan patch/minor kecil tanpa `--force`.

| Paket                | Sebelum        | Sesudah        | Advisory yang tertutup                           |
| -------------------- | -------------- | -------------- | ------------------------------------------------ |
| vite                 | 8.3.2          | 8.3.8          | high: beberapa advisory Vite dan `launch-editor` |
| concurrently         | 10.0.5         | 10.0.7         | critical (lewat `shell-quote`)                   |
| shell-quote          | 1.9.0          | 1.12.0         | critical: command injection pada `quote()`       |
| rolldown (+ binding) | 1.2.12         | 1.2.19         | (ikut naik bersama vite)                         |
| postcss, nanoid      | 8.5.28, 3.3.19 | 8.5.33, 3.3.20 | (patch)                                          |

Enam paket fallback WASM opsional milik rolldown (`@emnapi/*`, `@napi-rs/wasm-runtime`, dsb.) ikut terpasang.

### Yang masih terbuka: 7 kerentanan npm lewat `vite-plus`

Semua berada di alat pengembangan (lint, format, build lokal); `npm audit --omit=dev` melaporkan 0. Hasil build statis di `public/build/` yang dilayani, tidak ada paket ini di server produksi.

| Paket                                    | Terpasang | Severity | Masalah                                                 |
| ---------------------------------------- | --------- | -------- | ------------------------------------------------------- |
| vite-plus                                | 0.3.0     | critical | via `tinypool`, `@vitest/*`, `launch-editor`            |
| tinypool                                 | 2.1.0     | critical | prototype pollution yang berujung RCE lewat opsi worker |
| oxfmt                                    | 0.64.0    | critical | via `tinypool`                                          |
| vitest                                   | 4.1.11    | moderate | via `@vitest/*`                                         |
| @vitest/browser, @vitest/browser-preview | 4.1.11    | moderate | via `vitest`                                            |
| @vitest/mocker                           | 4.1.11    | moderate | injeksi kode lewat registrasi mock manual               |

Satu-satunya jalan keluar adalah `vite-plus` 0.3.0 → 1.x (major) dan **belum diterapkan**, menunggu persetujuan. Hasil percobaan di salinan sementara (di luar repo):

- `npm install vite-plus@1.7.0` di atas pohon yang ada gagal `ERESOLVE` (konflik peer `vitest@5.1.0`). Berhasil hanya dengan resolusi dari nol, yang menaikkan seluruh pohon dan mengubah `package-lock.json` secara besar.
- Dengan 1.7.0, `npm run check` dan `types:check` lolos tanpa perubahan format, tetapi `npm run build` **gagal**: 1.x mewajibkan alias `vite` → `@voidzero-dev/vite-plus-core@8.3.8000`.
- `vp migrate` otomatis memperbaikinya dengan **pindah ke pnpm** (`pnpm-lock.yaml`, `catalog:`), yang merusak `npm ci` di CI dan panduan deploy. Padanan native npm (`"vite": "npm:@voidzero-dev/vite-plus-core@8.3.8000"` + `overrides`) membuat build, tipe, dan audit lolos (0 kerentanan).
- Node minimal 1.7.0 adalah `^22.18.0 || ^24.11.0 || >=26`; CI memakai Node `22` (bergeser ke 22.x terbaru, memenuhi syarat).

## Temuan: `composer audit`

12 advisory di 7 paket. Per `composer audit --no-dev`, 11 di antaranya ada di dependensi produksi. Kolom severity kosong pada sebagian besar advisory karena memang tidak diisi oleh sumbernya.

| Paket                   | Advisory                                                                                                   | Severity |
| ----------------------- | ---------------------------------------------------------------------------------------------------------- | -------- |
| laravel/framework       | CVE-2026-128083, Task Output Injection pada Concurrency Process                                            | low      |
| symfony/http-foundation | CVE-2026-121619, shortcut `private_ranges` pada trusted proxies mempercayai klien                          | n/a      |
| symfony/http-foundation | CVE-2026-118514, request HEAD melewati opsi `methods`                                                      | n/a      |
| symfony/http-foundation | (tanpa CVE) aturan path di depan PHP bisa dilewati dengan menambahkan path                                 | n/a      |
| symfony/http-foundation | (tanpa CVE) URL yang dihasilkan bisa menunjuk ke host pilihan klien                                        | n/a      |
| symfony/http-kernel     | CVE-2026-123339, inline fragments membiarkan forwarded header yang tak tepercaya mengatur host/scheme/port | n/a      |
| symfony/mime            | CVE-2026-45070, injeksi header email lewat karakter non-token                                              | medium   |
| symfony/routing         | CVE-2026-118516, placeholder host bisa cocok dengan awal path                                              | n/a      |
| symfony/serializer      | CVE-2026-121623, `DataUriNormalizer` membuka data URI sebagai file lokal                                   | n/a      |
| symfony/serializer      | CVE-2026-122053, dotted-key property-path traversal melewati `#[Ignore]`                                   | n/a      |
| symfony/serializer      | CVE-2026-118515, serializer membangun `UploadedFile` dari data request                                     | n/a      |
| symfony/yaml            | CVE-2026-123792, parse time eksponensial/kuadratik pada dokumen YAML                                       | n/a      |

Hal yang perlu ditindaklanjuti di tahap berikutnya:

- Sebagian besar berasal dari komponen Symfony, jadi kemungkinan besar diselesaikan lewat `composer update` dalam batas constraint Laravel. Perlu dicek versi perbaikannya (mis. `symfony/*` 7.4.23 / 8.1.11).
- Relevansi ke aplikasi ini perlu dinilai satu per satu. Yang paling dekat dengan fitur yang dipakai adalah `symfony/mime` (email OTP dan notifikasi) dan `http-foundation` (trusted proxies, penting bila deploy di balik proxy).

## Temuan: `npm audit`

10 kerentanan: 5 critical, 1 high, 4 moderate. Dengan `--omit=dev` tersisa 3 (2 critical, 1 high), karena `concurrently` dan `vite` tercatat di `package.json` sebagai dependensi non-dev.

| Paket                                                            | Severity | Jenis     | Sumber masalah                                                                                                                                  | Perbaikan                         |
| ---------------------------------------------------------------- | -------- | --------- | ----------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------- |
| vite (8.3.0–8.3.2)                                               | high     | langsung  | Beberapa advisory Vite (file di luar `server.fs.allow` bisa terbaca, eksekusi skrip lintas origin) dan `launch-editor` (injeksi argumen editor) | `npm audit fix`                   |
| concurrently (9.2.3–9.2.4, 10.0.0–10.0.5)                        | critical | langsung  | via `shell-quote`                                                                                                                               | `npm audit fix`                   |
| shell-quote (1.8.4–1.10.0)                                       | critical | transitif | `quote()` command injection lewat line terminator setelah token `{ comment }`                                                                   | `npm audit fix`                   |
| vite-plus (<=1.0.0, terpasang 0.3.0)                             | critical | langsung  | via `tinypool`, `@vitest/*`, `launch-editor`                                                                                                    | Naik ke `vite-plus@1.7.0` (major) |
| tinypool (<=2.1.1)                                               | critical | transitif | Prototype pollution yang berujung RCE lewat opsi worker                                                                                         | Ikut naik `vite-plus`             |
| oxfmt                                                            | critical | transitif | via `tinypool`                                                                                                                                  | Ikut naik `vite-plus`             |
| vitest, @vitest/browser, @vitest/browser-preview, @vitest/mocker | moderate | transitif | via `vite-plus`                                                                                                                                 | Ikut naik `vite-plus`             |

Hal yang perlu ditindaklanjuti di tahap berikutnya:

- Sebagian besar celah ada di alat build dan dev server (Vite, vitest, tinypool), yang tidak berjalan di server produksi. Hasil build statis di `public/build/` yang dilayani. Ini penilaian awal dan perlu dipastikan saat audit dependensi.
- `npm audit fix` cukup untuk `vite`, `concurrently`, dan `shell-quote`. Sisanya (6 paket) menunggu kenaikan major `vite-plus` 0.3.0 → 1.7.0, yang berisiko mengubah perilaku `vp check` dan `vp build`, jadi perlu diuji.
