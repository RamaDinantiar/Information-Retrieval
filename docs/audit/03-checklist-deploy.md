# Audit Pra-Deploy — 03 Checklist Deploy Final

> **Digantikan oleh [06-deploy-checklist.md](06-deploy-checklist.md).** Berkas ini dipertahankan sebagai riwayat; rujukan baris kode dan nama test di bawah sudah bergeser dan tidak dipakai lagi.

Checklist ini merangkum **item terbuka dari audit** ([02-perbaikan.md](02-perbaikan.md)) dan hal baru yang ikut berubah. Ia melengkapi, bukan menggantikan, [08 · Deployment](../08-deployment.md). Kerjakan 08 lebih dulu, lalu pakai checklist ini sebagai lapisan tambahan.

- **Basis kode:** `main` @ `8d8dd77` (merge PR #7), CI `tests` lolos
- **Migrasi database:** audit tidak menambah migrasi atau seeder; `php artisan migrate --force` hanya menjalankan migrasi lama yang belum ada di server
- **Dependensi:** `composer audit` 0 advisory; `npm audit --omit=dev` 0

## A. Keputusan yang harus diambil sebelum deploy

Dua butir ini tidak bisa saya putuskan. Selesaikan sebelum hari deploy.

- [ ] **Skala huruf mutu (S9).** Konfirmasi skala resmi IT PLN (buku pedoman akademik atau Kepala Lab). Skala di kode saat ini, bertanda "asumsi" di `app/Support/Grading/FinalScore.php:56-69`:

    | Nilai | Huruf |
    | ----- | ----- |
    | ≥ 85  | A     |
    | ≥ 80  | AB    |
    | ≥ 70  | B     |
    | ≥ 65  | BC    |
    | ≥ 55  | C     |
    | ≥ 40  | D     |
    | < 40  | E     |

    Bila sama: hapus komentar "asumsi" dan catat sumbernya. Bila berbeda: kirim skalanya agar kode diubah (tes `GradingTest::test_final_score_is_a_normalised_weighted_average` ikut disesuaikan). Bila belum pasti: **biarkan, catat sebagai item terbuka**, dan beri tahu asisten bahwa huruf mutu bersifat sementara.

- [ ] **Topologi server (S6).** Tentukan nilai `TRUSTED_PROXIES`:

    | Kondisi server                                                      | Nilai `TRUSTED_PROXIES`                                                                    |
    | ------------------------------------------------------------------- | ------------------------------------------------------------------------------------------ |
    | Nginx langsung menerima koneksi pengguna (VPS tanpa CDN/proxy)      | kosongkan                                                                                  |
    | Nginx di belakang reverse proxy lain di mesin atau jaringan sendiri | IP proxy itu, mis. `10.0.0.5` (pisah koma bila banyak)                                     |
    | Cloudflare                                                          | daftar IP Cloudflare dari dokumentasi resmi mereka (bukan `*`), dan perbarui bila berubah  |
    | Shared hosting / cPanel kampus, atau belum tahu                     | tanyakan ke pengelola server; selama belum tahu, kosongkan lalu lakukan uji IP di bagian D |

    `*` mempercayai semua pengirim header `X-Forwarded-For`, sehingga siapa pun bisa memalsukan IP-nya dan melewati pembatas per IP. Pakai hanya bila server **hanya** bisa dijangkau lewat proxy.

## B. Konfigurasi `.env` produksi

Salin dari `.env.production.example`, lalu pastikan butir yang berubah atau ditambahkan oleh audit:

- [ ] `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://<domain>`
- [ ] `SESSION_SECURE_COOKIE=true`, `SESSION_ENCRYPT=true`
- [ ] `DB_QUEUE_RETRY_AFTER=330` (baru, S1). **Harus lebih besar dari** `--timeout` worker (320) dan `timeout` job rekap (300). Bila kosong, default di `config/queue.php` sudah 330, tetapi tuliskan eksplisit.
- [ ] `TRUSTED_PROXIES=` sesuai keputusan di bagian A (baru, S6). Setelah mengubah `.env`, jalankan `php artisan config:cache` agar berlaku (nilainya dibaca lewat `config/app.php`, bukan langsung dari `.env`).
- [ ] `MAIL_MAILER=smtp` dengan kredensial sungguhan. Mailer `log` menulis OTP dan password sementara ke `laravel.log`.
- [ ] `LAB_PROGRAMS_ENABLED=false` (modul Program belum ditinjau audit)
- [ ] `chmod 600 .env`

## C. Langkah deploy

Urutan penuh ada di [08 · Deployment §3](../08-deployment.md). Tambahan dari audit:

- [ ] **Cadangkan database dan `storage/app/private/`** sebelum `migrate` (08 §7).
- [ ] Node untuk build `^22.18` atau `^24.11` (bila build di server). CI memakai Node 22.
- [ ] `composer install --no-dev --optimize-autoloader`
- [ ] `npm ci && npm run build`. Jalankan `npm run types:check` **setelah** build, bukan sebelumnya: rute Wayfinder dihasilkan saat build.
- [ ] `php artisan migrate --force` (jangan `migrate:fresh`)
- [ ] `php artisan config:cache && route:cache && view:cache && event:cache`
- [ ] **`php artisan queue:restart`.** Wajib: worker lama masih memegang kode dan konfigurasi lama (Laravel 13.34, `retry_after` lama).
- [ ] Worker Supervisor memakai `--tries=1 --timeout=320`. `--tries=1` aman: notifikasi email membawa `$tries = 3` sendiri yang menimpanya.
- [ ] Cron `* * * * * php artisan schedule:run` terpasang. Dua perintah baru berjalan otomatis lewat jadwal: `queue:prune-failed --hours=48` dan `housekeeping:prune`.

## D. Uji setelah deploy

Tambahan di atas daftar di [08 · Deployment §6](../08-deployment.md):

- [ ] CI `tests` di `main` hijau untuk commit yang dideploy
- [ ] **IP klien (S6).** Masuk sebagai Admin, buat satu aksi yang tercatat (mis. ubah satu pengaturan), lalu buka **Audit Log**: kolom IP harus IP perangkat Anda, **bukan IP server atau proxy**. Bila yang tampil IP proxy, `TRUSTED_PROXIES` belum benar.
- [ ] `curl -I https://<domain>/login` memuat `Strict-Transport-Security`. Bila tidak ada padahal HTTPS aktif, proxy belum dipercaya (`TRUSTED_PROXIES`).
- [ ] **Email password sementara (T2, S2).** Buat dua atau tiga akun uji lewat **Admin › Pengguna › Impor**, lalu pastikan emailnya benar-benar masuk (pengiriman bertahap 20 per 30 detik, jadi tunggu sampai sekitar satu menit untuk file kecil). Hapus atau nonaktifkan akun ujinya.
- [ ] Tabel `failed_jobs` kosong setelah uji di atas (`php artisan queue:failed`)
- [ ] `php artisan queue:work` memproses ekspor: **Generate › Rekap Jam Ngawas › Ekspor Excel** berubah dari "Antre" ke "Selesai", dan berkasnya terbuka di Excel
- [ ] Catatan teks yang diawali `=` di laporan pengganti muncul sebagai teks biasa di Excel rekap (S3), bukan formula
- [ ] Pilih bulan lain di halaman Rekap Jam Ngawas (khususnya bila hari ini tanggal 29–31): label bulan dan jumlah kolom hari sesuai bulan yang dipilih (T1)
- [ ] Di halaman Rekap Jam Ngawas, pilih bulan di luar semua semester (di luar rentang tanggal semester mana pun): halaman menampilkan kondisi kosong tanpa tombol Ekspor/Final, dan **tidak** menampilkan rekap semester aktif untuk bulan itu (R2)

## E. Pemantauan setelah deploy

Dua temuan audit sengaja ditunda karena butuh data nyata. Ukur pada beban sungguhan, lalu putuskan.

- [ ] **Beban pembaruan langsung (S8).** Saat tenggat tugas atau sesi kelas ramai, pantau waktu respons dan beban server: setiap penyimpanan data memuat ulang halaman semua pengguna yang terbuka (polling 5 detik). Tanda perlu ditindaklanjuti: halaman melambat saat banyak pengumpulan bersamaan, atau CPU/DB tinggi terus-menerus. Catat jumlah pengguna aktif saat itu.
- [ ] **Waktu Tetapkan Final (R3).** Saat pertama kali menetapkan rekap Final dengan data nyata, catat berapa lama prosesnya. Bila mendekati 30 detik (`max_execution_time` PHP-FPM) atau melewati timeout Nginx, proses itu perlu dipindah ke antrean.
- [ ] Pantau `failed_jobs` dan notifikasi lonceng Admin "Email password sementara gagal terkirim" selama minggu pertama (akan muncul bila email kredensial tetap gagal setelah 3 percobaan).
- [ ] Setelah rekap pertama ditetapkan Final: pastikan laporan baru untuk bulan itu ditolak dengan pesan "Rekap … sudah Final" dan berjalan lagi setelah rekap dibuka kembali (S4).

## F. Item terbuka setelah deploy (tidak menghalangi)

| Item                                       | Status                                                                                                                                                   | Kapan dikerjakan                                     |
| ------------------------------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------- |
| `vite-plus` 0.3.0 → 1.x (7 kerentanan npm) | Ditunda. Hanya alat pengembangan; `npm audit --omit=dev` = 0. Butuh alias `overrides` dan lockfile baru, **jangan** pakai `vp migrate` (pindah ke pnpm). | Setelah deploy awal, sebagai tahap terpisah          |
| S9 skala huruf mutu                        | Menunggu skala resmi (bagian A)                                                                                                                          | Sebelum nilai akhir dipakai resmi                    |
| S8, R3                                     | Menunggu pengukuran (bagian E)                                                                                                                           | Setelah ada data beban nyata                         |
| R8 foto profil terbaca antar-pengguna      | Disengaja                                                                                                                                                | Opsional                                             |
| R11 ClamAV belum aktif                     | Keputusan peluncuran                                                                                                                                     | Putuskan sebelum membuka unggahan ke banyak pengguna |
| Pembatasan hak DB untuk `audit_logs`       | Di luar kode aplikasi                                                                                                                                    | Opsional, oleh pengelola DB                          |
| Modul Program                              | Nonaktif (`LAB_PROGRAMS_ENABLED=false`), belum ditinjau audit                                                                                            | Tinjau sebelum diaktifkan                            |

## G. Rollback

- Kode: deploy ulang `23f4848` (merge PR #6, `main` tepat sebelum audit), lalu `php artisan queue:restart` dan `php artisan config:cache`.
- Database: audit tidak menambah migrasi, jadi mundur kode tidak membutuhkan `migrate:rollback` untuk perubahan audit.
- Konfigurasi baru (`TRUSTED_PROXIES`, `DB_QUEUE_RETRY_AFTER`) aman diabaikan oleh versi lama.
- Rekap yang sudah ditetapkan Final dan berkas yang dihasilkan ada di database dan `storage/app/private/`, jadi pulihkan dari cadangan (bagian C) bila perlu.
