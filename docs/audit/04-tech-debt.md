# Audit Pra-Deploy — 04 Utang Teknis

Daftar utang teknis yang tersisa setelah audit kode, perbaikan, dan tahap test, diurutkan dengan rumus **Prioritas = (Dampak + Risiko) × (6 − Upaya)**, masing-masing skala 1–5 (upaya dibalik: makin kecil upaya, makin tinggi prioritas). **Tahap ini hanya dokumen.** Tidak ada kode yang diubah.

- **Basis:** `main` @ `6769e48` (setelah merge PR #10), 669 test lolos, coverage 91,6% (pcov di CI, hanya laporan)
- **Metode:** pembacaan kode dan penghitungan dengan `grep`/`wc`, `composer outdated`, `npm outdated`, dan hasil audit sebelumnya (`01`–`03`). **Tidak ada pengukuran kinerja.** Butir yang bergantung pada beban nyata ditandai _perlu diukur_, bukan dianggap pasti.
- **Penanda bukti:** **terverifikasi** = saya membaca atau menghitungnya di repo; **inferensi** = kesimpulan dari konfigurasi, belum diuji.

## 1. Ringkasan

Basis kodenya bersih menurut ukuran yang bisa dihitung: PHPStan level 7 tanpa baseline, nol `TODO` di `app/` dan `resources/js/` (satu `TODO` ada di teks contoh seeder), dua `eslint-disable`, nol `any` di TypeScript, nol `Model::all()`, berkas hasil generate Wayfinder tidak ikut di Git. Utang yang nyata ada di tiga tempat:

1. **Pengamatan produksi belum ada.** Tidak ada pelacak galat dan tidak ada peringatan untuk `failed_jobs`. Audit sudah menunda dua keputusan (S8, R3) justru karena butuh data beban nyata, dan data itu tidak akan ada tanpa pemantauan.
2. **Test dan produksi memakai mesin database berbeda.** CI dan test lokal memakai SQLite, produksi MySQL. Dua temuan audit (N7, N8) dan makro `whereDateBetween` (20 pemakaian) lahir dari selisih ini.
3. **Frontend tidak punya jaring pengaman.** Sepuluh halaman React di atas 600 baris (terbesar 1.939) tanpa satu pun test komponen.

Tidak ada butir yang **wajib** selesai sebelum deploy. Dua butir (T1, T9) sebaiknya dimulai sebelum deploy, dalam bentuk paling kecil.

## 2. Daftar berurutan prioritas

| #   | Utang                                                   | Jenis         | Dampak | Risiko | Upaya | Prioritas | Estimasi        |
| --- | ------------------------------------------------------- | ------------- | ------ | ------ | ----- | --------- | --------------- |
| T1  | Tidak ada pelacak galat dan peringatan antrean          | Infrastruktur | 3      | 4      | 2     | **28**    | 2–4 jam         |
| T2  | Test dan CI tidak memakai MySQL                         | Test          | 3      | 5      | 3     | **24**    | 4–8 jam         |
| T3  | Skala huruf mutu ganda (PHP dan TSX) dan belum resmi    | Kode          | 2      | 4      | 2     | **24**    | 1–2 jam         |
| T4  | Dependensi tertinggal                                   | Dependensi    | 2      | 3      | 2     | **20**    | 2–4 jam         |
| T5  | Tidak ada test komponen frontend                        | Test          | 3      | 3      | 3     | **18**    | 4–8 jam (awal)  |
| T6  | `Semester::current()` dipanggil di 37 tempat tanpa memo | Kode          | 2      | 2      | 2     | **16**    | 1–2 jam         |
| T7  | `AddSecurityHeaders` tidak global (N13)                 | Kode          | 1      | 2      | 1     | **15**    | 30 menit        |
| T8  | Modul Program dikirim tapi nonaktif dan belum ditinjau  | Arsitektur    | 2      | 3      | 3     | **15**    | 1–2 hari        |
| T9  | Dokumen audit lama rujukannya usang                     | Dokumentasi   | 1      | 2      | 1     | **15**    | 1 jam           |
| T10 | Polling 5 detik membaca cache lewat database            | Arsitektur    | 3      | 3      | 4     | **12**    | _tunggu ukuran_ |
| T11 | Format tanggal ditulis ulang di 17 berkas TSX           | Kode          | 2      | 1      | 2     | **12**    | 2–3 jam         |
| T12 | Urutan status `CASE …` ditulis ulang di 6 tempat        | Kode          | 2      | 1      | 2     | **12**    | 1–2 jam         |
| T13 | Halaman React raksasa                                   | Kode          | 4      | 3      | 5     | **7**     | bertahap        |

## 3. Rincian

### T1 — Tidak ada pelacak galat dan peringatan antrean (28)

- **Bukti (terverifikasi):** `composer.json` tidak memuat Sentry, Flare, Nightwatch, Pulse, atau sejenisnya. `.env.production.example` hanya mengatur log harian level `warning`. Satu-satunya peringatan operasional ke Admin yang saya temukan adalah email kredensial gagal terkirim.
- **Dampak:** galat 500 dan job gagal hanya terlihat bila seseorang membuka `laravel.log` atau menjalankan `queue:failed`. Pengguna yang kena galat jarang melapor.
- **Langkah terkecil:** (a) jadwalkan satu perintah harian yang memberi notifikasi Admin bila `failed_jobs` tidak kosong; (b) putuskan memakai pelacak galat eksternal atau tidak (**keputusan Anda**: ada biaya dan data keluar dari server kampus).
- **Mengapa sebelum deploy:** ia menentukan apakah S8 dan R3 bisa diputuskan dengan data.

### T2 — Test dan CI tidak memakai MySQL (24)

- **Bukti (terverifikasi):** `phpunit.xml` dan `tests.yml` memakai `DB_CONNECTION=sqlite`. Produksi MySQL. Kode menambal selisih tanggal lewat makro `whereDateBetween` (20 pemakaian) dan `whereDate` (25 pemakaian). Temuan N8 (`GenerateClassShifts` menggandakan shift di SQLite) dan N7 muncul di test justru karena SQLite.
- **Risiko:** perilaku SQLite dan MySQL berbeda pada tanggal, urutan, dan kolasi. Test hijau tidak membuktikan perilaku di produksi, dan yang paling mungkin lolos adalah query tanggal.
- **Langkah:** tambahkan job CI kedua dengan layanan MySQL 8 yang menjalankan suite yang sama (`continue-on-error` dulu, tanpa mengganggu job SQLite), lalu perbaiki yang gagal. Sebelum itu, **verifikasi N8 manual di MySQL setelah deploy** (jalankan "Generate shift" dua kali pada data kecil).
- **Catatan:** menjalankan penuh di MySQL menambah menit CI. Ukur dulu.

### T3 — Skala huruf mutu ganda dan belum resmi (24)

- **Bukti (terverifikasi):** `FinalScore::LETTER_SCALE` (PHP) dan `GRADE_SCALE` di `resources/js/pages/asisten/grades/review.tsx:56`. `GradeScaleTest` menjaga keduanya sama, tetapi dua sumber tetap dua sumber. Skala masih "ASUMSI S9 – menunggu skala resmi IT PLN". Dua test lama menulis huruf langsung (N9).
- **Langkah:** kirim skala dari PHP ke halaman lewat prop Inertia sehingga TSX tidak punya salinan; hapus drift guard. Lakukan **bersamaan** dengan konfirmasi skala resmi, supaya perubahan angka tetap satu tempat.
- **Menunggu Anda:** skala resmi dari IT PLN atau Kepala Lab.

### T4 — Dependensi tertinggal (20)

- **Bukti (terverifikasi dari `composer outdated` dan `npm outdated`):**
    - PHP, hanya patch/minor: `inertia-laravel` 3.5.1→3.7.0, `fortify` 1.40.0→1.43.0, `openspout` 5.12.0→5.15.3, `larastan`, `pint`, `pail`, `tinker`. `phpunit` 12→13 adalah major, tunda.
    - JS: `@inertiajs/react` dan `@inertiajs/vite` 3.8.0→3.11.0, semua paket `@radix-ui/*`, `@tailwindcss/vite`, `@tiptap/*` selisih minor.
    - `vite-plus` 0.3.0→1.x (7 kerentanan npm, hanya alat pengembangan; `npm audit --omit=dev` = 0). Sudah dicatat di checklist: jangan pakai `vp migrate`.
    - `npm outdated` juga menampilkan paket platform (`@rollup/rollup-win32-x64-msvc` dan sejenisnya) sebagai `MISSING`. Itu opsional per platform dan **bukan** masalah.
- **Langkah:** satu PR "minor/patch" per ekosistem (composer, npm) dengan suite penuh dan build; `vite-plus` PR terpisah. Jangan digabung dengan perubahan fitur.

### T5 — Tidak ada test komponen frontend (18)

- **Bukti (terverifikasi):** `package.json` tidak punya skrip `test`, vitest, atau jest.
- **Langkah:** pasang vitest dan React Testing Library, lalu tulis test untuk logika murni lebih dulu (`lib/datetime.ts`, penghitung nilai di `review.tsx`, pembuat payload ekspor). Hindari test tampilan yang rapuh. Target awal: 10–15 test untuk fungsi, bukan persentase.
- **Hubungan:** T3 dan T11 lebih aman dikerjakan bila T5 sudah ada.

### T6 — `Semester::current()` tanpa memo (16)

- **Bukti (terverifikasi):** 37 pemanggilan di `app/`; implementasinya `static::active()->first()` (satu query tiap panggilan). **Dampak per halaman belum diukur** (inferensi bahwa sebagian halaman memanggilnya lebih dari sekali).
- **Langkah:** ukur jumlah query satu halaman ramai (dasbor Admin, Generate) dengan `DB::getQueryLog`; bila ada pemanggilan ganda, memoikan per request (properti statis yang di-reset di test, atau binding terbatas request). Jangan memoikan lintas request: semester aktif bisa berganti.

### T7 — `AddSecurityHeaders` tidak global (15)

- **Bukti (terverifikasi di test P2):** middleware ditambahkan ke grup `web`. Alihan tamu dari middleware `auth` dan 404 untuk URL yang tidak cocok rute apa pun tidak membawa `X-Frame-Options`, `nosniff`, dan CSP.
- **Langkah:** daftarkan sebagai middleware global (`$middleware->append`) dan tambahkan test untuk kedua kasus. Ini temuan N13.

### T8 — Modul Program dikirim tetapi nonaktif dan belum ditinjau (15)

- **Bukti (terverifikasi):** `LAB_PROGRAMS_ENABLED` default `false`; ada dua `ProgramController` (334 baris di sisi asisten), halaman `asisten/programs/show.tsx` 1.281 baris, `program-form-dialog.tsx` 768 baris. Audit tidak mencakup modul ini.
- **Risiko:** kode yang dikirim tetapi mati menua tanpa tinjauan setara modul lain, dan bisa diaktifkan lewat satu variabel `.env`.
- **Pilihan (keputusan Anda):** (a) tinjau dan uji sebelum diaktifkan, (b) pindahkan ke branch sampai dibutuhkan, (c) biarkan nonaktif dan catat bahwa mengaktifkannya memerlukan audit. Rekomendasi: (c) sekarang, (a) bila modul ini akan dipakai semester depan.

### T9 — Dokumen audit lama usang (15)

- **Bukti (terverifikasi):** `docs/audit/03-checklist-deploy.md` menyebut `FinalScore.php:56-69` dan test `GradingTest::test_final_score_is_a_normalised_weighted_average`; baris itu bergeser setelah refactor `LETTER_SCALE`. Checklist final akan ditulis ulang sebagai `06-deploy-checklist.md`, jadi cukup pastikan berkas lama tidak dipakai sebagai acuan.
- **Langkah:** tandai di bagian atas `03-checklist-deploy.md` bahwa ia digantikan oleh `06`, atau hapus setelah `06` jadi.

### T10 — Polling 5 detik membaca cache lewat database (12)

- **Bukti:** `LiveVersion::current()` memakai `Cache::rememberForever`, dan `CACHE_STORE=database` di `.env.production.example` (terverifikasi). Bahwa tiap polling menjadi satu query database adalah **inferensi** dari dua fakta itu; beban nyatanya belum diukur. Ini bagian dari S8.
- **Langkah:** tunggu data beban (lihat T1). Bila perlu: pindahkan hanya cache ke `file` atau Redis, atau perpanjang interval polling saat tab tidak aktif. Jangan dikerjakan sebelum ada angka.

### T11 — Format tanggal di 17 berkas TSX (12)

- **Bukti (terverifikasi):** `resources/js/lib/datetime.ts` sudah punya `formatDate`, `formatDateRange`, `formatClock`, dan sebagainya, tetapi 17 berkas di luar `lib/` masih memakai `toLocaleDateString` atau `Intl.DateTimeFormat` langsung.
- **Risiko:** format tanggal tidak seragam dan zona waktu tersebar. Sebagian mungkin sengaja (opsi format khusus); periksa satu per satu.
- **Langkah:** kerjakan setelah T5, dengan test untuk helper.

### T12 — Urutan status `CASE …` (12)

- **Bukti (terverifikasi):** `orderByRaw("CASE status WHEN … END")` di `AdminDashboardData`, `UserController`, `SemesterController`, dan dua `ProgramController`; `orderByRaw("status = 'active' desc")` di dua controller rekap.
- **Catatan keamanan:** semua string literal tanpa input pengguna, jadi **bukan** celah injeksi. Utangnya hanya duplikasi.
- **Langkah:** scope Eloquent per model (misalnya `Semester::scopeActiveFirst`). Kerjakan bersamaan T2 karena sebagian ekspresi ini bergantung dialek SQL.

### T13 — Halaman React raksasa (7)

- **Bukti (terverifikasi):** `asisten/report.tsx` 1.939 baris, `components/tasks/task-form-dialog.tsx` 1.532, `asisten/programs/show.tsx` 1.281, `asisten/tasks/index.tsx` 1.044; sepuluh halaman di atas 600 baris.
- **Dampak:** sulit ditinjau dan diubah tanpa merusak bagian lain; tidak ada test yang menahan.
- **Rekomendasi:** **jangan** refactor menyeluruh sebelum deploy. Pecah satu bagian hanya saat berkas itu memang sedang diubah untuk fitur lain, dan setelah T5 ada. Upaya "5" mencerminkan refactor menyeluruh; pemecahan bertahap lebih murah per langkah.

## 4. Rencana bertahap

| Fase                              | Isi                                                                                              | Syarat selesai                                                                 |
| --------------------------------- | ------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------ |
| **0 — sebelum deploy** (opsional) | T1 versi terkecil (peringatan `failed_jobs`), putuskan pelacak galat; T9 tandai berkas usang     | Admin diberi tahu bila antrean gagal; tidak ada dokumen usang yang menyesatkan |
| **1 — dua minggu pertama**        | Verifikasi N8 di MySQL, T7, T4 (PR minor/patch), mulai T2 (job MySQL `continue-on-error`)        | Job MySQL berjalan; PR dependensi digabung                                     |
| **2 — bulan pertama**             | T2 selesai (job MySQL wajib hijau), T3 dengan skala resmi, T6 bila terukur, T5 (vitest awal)     | Test suite hijau di dua mesin; skala huruf satu sumber                         |
| **3 — oportunistik**              | T10 bila data beban menuntut, T11, T12, T13 saat berkas bersangkutan diubah, T8 sesuai keputusan | —                                                                              |

Fase 1 dan 2 bisa dikerjakan di sela fitur karena tiap butir kecil dan terpisah (satu PR per butir).

## 5. Yang diperiksa dan **bukan** utang

- Tidak ada `Model::all()` di controller; tidak ada `DB::raw` dengan input pengguna.
- Satu `TODO` hanya di teks contoh soal di seeder.
- PHPStan level 7 lolos tanpa baseline; Pint dan `npm run check` lolos.
- Tidak ada `any` di TypeScript; dua `eslint-disable` saja.
- `env()` tidak dipakai di luar `config/` (`TrustedProxies` sengaja dibaca lewat config agar tahan `config:cache`).
- File hasil generate (`resources/js/actions`, `routes`, `wayfinder`) ada di `.gitignore`, bukan di repo.
- Ada 13 baris `throttle` di `routes/` (login, reset password, ekspor, dan lainnya).

## 6. Keputusan yang dibutuhkan dari Anda

1. **T1:** pakai pelacak galat eksternal (misalnya Sentry atau Flare) atau cukup peringatan `failed_jobs` ke lonceng Admin?
2. **T3:** kapan skala huruf resmi bisa dikonfirmasi? (Perbaikan T3 menunggu ini.)
3. **T8:** modul Program: tinjau sekarang, tunda, atau pindahkan ke branch?
4. **T2:** setuju menambah job CI MySQL (menambah waktu CI)?

## 7. Batasan dokumen ini

- Angka dampak dan risiko adalah penilaian saya dari kode, bukan dari insiden nyata; belum ada data produksi.
- T6 dan T10 bergantung pada jumlah query dan beban yang belum diukur. Skornya bisa naik atau turun setelah diukur.
- Saya tidak menilai desain antarmuka, aksesibilitas, atau isi `docs/` selain rujukan yang bertabrakan dengan hasil audit; itu masuk `05-webapp-testing.md` bila diperlukan.
