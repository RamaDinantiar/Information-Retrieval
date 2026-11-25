# 12 · Serah Terima untuk Perawat Sistem

[← Kembali ke README](../README.md)

Panduan singkat bagi orang yang merawat IR Lab setelah deploy. Langkah pemasangan ada di
[08 · Deployment](08-deployment.md); dokumen ini berisi **apa yang dirawat, apa yang dipantau, dan apa yang dilakukan saat ada masalah**.

## 1. Gambaran sistem

- Aplikasi Laravel 13 + Inertia/React, database MySQL 8, PHP 8.4. Detail: [03 · Arsitektur](03-architecture.md), [04 · Database](04-database.md).
- Tiga hal harus selalu hidup: **web server (HTTPS)**, **worker antrean** (Supervisor), dan **cron penjadwal** ([08 bagian 4](08-deployment.md)).
- Data penting ada di dua tempat: database dan folder `storage/app/private/`.

## 2. Perawatan rutin

| Kapan                    | Apa                                                                         | Cara                                                                                        |
| ------------------------ | --------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------- |
| Harian                   | Cadangan database dan `storage/app/private/`                                | [08 bagian 7](08-deployment.md); simpan di luar server                                      |
| Mingguan                 | Cek worker hidup dan tidak ada job gagal menumpuk                           | `supervisorctl status ir-lab-worker`; `php artisan queue:failed`                            |
| Mingguan                 | Cek `storage/logs/laravel.log` dan `worker.log` untuk galat berulang        | `grep -c ERROR storage/logs/laravel.log`                                                    |
| Tiap deploy              | Migrasi, cache, restart worker                                              | `php artisan migrate --force`, `config:cache`, `route:cache`, `view:cache`, `queue:restart` |
| Tiap semester            | Buat semester baru, mata kuliah dan kelas semester itu, impor pengguna baru | Admin › Semester, Mata Kuliah, Kelas, Pengguna (mata kuliah menempel pada satu semester)    |
| Tiap pergantian pengurus | Buat akun Admin pribadi baru, nonaktifkan yang lama                         | Admin › Pengguna ([08 bagian 5](08-deployment.md))                                          |
| Berkala                  | Uji pemulihan cadangan; perbarui dependensi yang punya perbaikan keamanan   | `composer audit`, `npm audit`                                                               |

**Jangan** menjalankan `migrate:fresh`, `migrate:reset`, atau `db:wipe` di produksi: semua data terhapus.

## 3. Gejala dan tindakan

| Gejala                                                   | Kemungkinan penyebab                                                 | Tindakan                                                                         |
| -------------------------------------------------------- | -------------------------------------------------------------------- | -------------------------------------------------------------------------------- |
| Ekspor Excel/ZIP tertahan di "Antre"                     | Worker antrean mati                                                  | `supervisorctl restart ir-lab-worker`                                            |
| Email (OTP, password sementara, notifikasi) tidak masuk  | Worker mati, SMTP salah, atau saklar email mati                      | Cek worker; cek `MAIL_*` di `.env`; Admin › Pengaturan Sistem › Notifikasi Email |
| Sesi kelas tidak mulai/berhenti, Pre-Test tidak terbuka  | Cron tidak jalan                                                     | Cek crontab user web server; `php artisan schedule:run` manual                   |
| Semua pengguna terbaca satu IP, login terkunci bersamaan | `TRUSTED_PROXIES` belum diisi di belakang proxy                      | Isi `TRUSTED_PROXIES`, lalu `php artisan config:cache`                           |
| Setelah logout, tombol Back menampilkan halaman lama     | `INERTIA_ENCRYPT_HISTORY` tidak aktif atau situs tidak memakai HTTPS | Pastikan `INERTIA_ENCRYPT_HISTORY=true` dan HTTPS aktif                          |
| Semua Admin terkunci                                     | Akun terkunci atau pemegang akun pergi                               | `php artisan lab:recover-admin <email>` ([08 bagian 8](08-deployment.md))        |
| Halaman galat 500                                        | Lihat `storage/logs/laravel.log`                                     | Perbaiki penyebab; jangan menyalakan `APP_DEBUG=true` di produksi                |
| Unggahan gagal                                           | Batas ukuran PHP/Nginx di bawah 25 MB                                | [08 bagian 1](08-deployment.md)                                                  |

## 4. Rahasia dan akses

- `.env` produksi: `chmod 600`, jangan masuk git. Isinya kata sandi database, SMTP, `APP_KEY` (jangan diganti setelah ada data: sesi dan data terenkripsi menjadi tidak terbaca).
- Akun demo (`@irlab.test`, kata sandi `password`) tidak ada di produksi.
- Tinjau [10 · Keamanan](10-security.md) bila menambah fitur atau pengelola.

## 5. Repositori dan perubahan kode

- Cabang `main` dilindungi: perubahan lewat Pull Request dan wajib CI (`ci`) lulus; tidak bisa force-push atau hapus.
- CI menjalankan format (`vp check`), Pint, PHPStan, pemeriksaan tipe, dan seluruh tes. Jalankan lokal sebelum PR: `composer ci:check`.
- Riwayat pengujian dan keputusan ada di [`docs/audit/`](audit/).

## 6. Keputusan yang masih terbuka saat dokumen ini ditulis

Perbarui bagian ini setelah dijawab.

- [ ] **`TRUSTED_PROXIES`**: menunggu jawaban pengelola server.
- [ ] **SMTP**: menunggu jawaban pengelola email kampus.
- [ ] **Skala huruf mutu** (`app/Support/Grading/FinalScore.php`): menunggu skala resmi dari dosen pembimbing atau kepala lab.
- [ ] **Uji di server**: login, logout, tekan Back lewat HTTPS ([audit 05 W1](audit/05-webapp-testing.md)).
- [ ] **C4 setelah deploy awal**: hapus dua baris `@rollup/rollup-*` di `optionalDependencies` ([audit 07](audit/07-kode-mati-dan-dependensi.md)).

## 7. Kontak

Isi sebelum diserahkan:

| Peran                         | Nama | Kontak |
| ----------------------------- | ---- | ------ |
| Penanggung jawab lab          |      |        |
| Pengelola server/hosting      |      |        |
| Pengelola email kampus (SMTP) |      |        |
| Pengembang                    |      |        |
