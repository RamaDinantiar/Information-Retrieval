# Audit Pra-Deploy — 06 Checklist Deploy Final

Checklist tunggal untuk hari deploy. **Menggantikan** [03-checklist-deploy.md](03-checklist-deploy.md) dan merangkum hasil tahap 01–05. Ia melengkapi, bukan menggantikan, [08 · Deployment](../08-deployment.md): langkah teknis rinci (Supervisor, cron, Nginx, cadangan, akun darurat) tetap ada di 08, dan checklist ini menunjuk ke sana bila perlu.

- **Basis kode:** `main` @ `2aeb973`; CI `tests` hijau pada commit ini (terverifikasi)
- **Isi rilis:** semua perbaikan audit, 669 test (coverage 91,6%), uji browser tiga peran (05). Kode aplikasi tidak berubah sejak PR #10.
- **Migrasi:** **tidak ada migrasi baru sejak `23f4848`** (titik `main` sebelum audit; `git diff` pada `database/migrations` kosong, terverifikasi). Server baru menjalankan seluruh 49 migrasi; server yang sudah menjalankan `23f4848` tidak punya migrasi tertunda.
- **Dependensi:** `composer audit` 0 advisory; `npm audit --omit=dev` 0 (7 kerentanan hanya di alat pengembangan `vite-plus`, lihat bagian 8)
- **Cara membaca penanda:** ✅ **sudah teruji di lokal** (hasil di [05](05-webapp-testing.md)), tetap ulangi di produksi; 🖥️ **hanya bisa diuji di server**; ⚠️ **butuh keputusan Anda**

## 1. Status ringkas

| Bidang                               | Status                                                                             |
| ------------------------------------ | ---------------------------------------------------------------------------------- |
| Kode dan test                        | Siap. CI hijau, 0 advisory dependensi produksi                                     |
| Alur utama di browser                | Teruji di lokal (05); belum diuji di server produksi                               |
| Keputusan yang masih terbuka         | 5 butir di bagian 2; dua di antaranya (S6, S9) menentukan konfigurasi hari deploy  |
| Pemantauan produksi                  | **Belum ada** pelacak galat atau peringatan antrean (T1 di [04](04-tech-debt.md))  |
| Hal yang tidak bisa dibuktikan lokal | Email nyata, HTTPS/HSTS, IP klien di belakang proxy, beban banyak pengguna, ClamAV |

**Rekomendasi:** boleh deploy bila bagian 2 butir 1 dan 2 sudah diputuskan dan bagian 3 semuanya tercentang. Butir lain boleh menyusul dengan catatan.

## 2. Keputusan sebelum hari deploy

- [ ] ⚠️ **1. Topologi server (S6) → nilai `TRUSTED_PROXIES`. Wajib.**

    | Kondisi server                                                      | Nilai `TRUSTED_PROXIES`                                                                   |
    | ------------------------------------------------------------------- | ----------------------------------------------------------------------------------------- |
    | Nginx langsung menerima koneksi pengguna (VPS tanpa CDN/proxy)      | kosongkan                                                                                 |
    | Nginx di belakang reverse proxy lain di mesin atau jaringan sendiri | IP proxy itu, mis. `10.0.0.5` (pisah koma bila banyak)                                    |
    | Cloudflare                                                          | daftar IP Cloudflare dari dokumentasi resmi mereka (bukan `*`), dan perbarui bila berubah |
    | Shared hosting / cPanel kampus, atau belum tahu                     | tanyakan ke pengelola server; selama belum tahu, kosongkan lalu lakukan uji IP (bagian 6) |

    `*` mempercayai semua pengirim header `X-Forwarded-For`, sehingga siapa pun bisa memalsukan IP-nya dan melewati pembatas per IP. Pakai hanya bila server **hanya** bisa dijangkau lewat proxy.

- [ ] ⚠️ **2. Akun SMTP untuk email. Wajib.** `.env.production.example` mengarah ke `smtp.office365.com:587` dengan akun `irlab@itpln.ac.id`. Pastikan akun itu boleh mengirim lewat SMTP dengan kata sandi/aplikasi yang Anda miliki (kebijakan Microsoft 365 kampus bisa melarang SMTP AUTH; tanyakan ke pengelola email kampus). Tanpa email, **password sementara dan reset password tidak sampai**, dan Admin tidak bisa membuat akun yang berguna. Mailer `log` menulis OTP dan password sementara ke `laravel.log`, jadi jangan dipakai di produksi.

- [ ] ⚠️ **3. Skala huruf mutu (S9). Boleh menyusul.** Skala saat ini bertanda "ASUMSI S9 – menunggu skala resmi IT PLN" di `FinalScore::LETTER_SCALE`, dengan salinan `GRADE_SCALE` di `resources/js/pages/asisten/grades/review.tsx`:

    | Nilai | Huruf |
    | ----- | ----- |
    | ≥ 85  | A     |
    | ≥ 80  | AB    |
    | ≥ 70  | B     |
    | ≥ 65  | BC    |
    | ≥ 55  | C     |
    | ≥ 40  | D     |
    | < 40  | E     |

    Bila sama: hapus komentar "asumsi", catat sumbernya. Bila berbeda: kirim skalanya; ubah PHP, TSX, dan dua test lama yang menulis huruf langsung (N9). Bila belum pasti: **biarkan**, dan beri tahu asisten bahwa huruf mutu bersifat sementara.

- [ ] ✅/🖥️ **4. Back setelah logout (W1). Diperbaiki di kode, tinggal diuji di server.** Respons logout (`App\Http\Responses\LogoutResponse`) memanggil `Inertia::clearHistory()`; test PHP memastikan penanda `clearHistory` terbawa ke halaman berikutnya (dan gagal bila baris itu dihapus). Syarat di server: `INERTIA_ENCRYPT_HISTORY=true` di `.env` (sudah di `.env.production.example`) dan HTTPS. **Belum diuji di browser**: enkripsi riwayat butuh konteks aman. Uji setelah deploy: login, buka dashboard, logout, tekan Back; halaman lama tidak boleh tampil.

- [x] ✅ **5. ClamAV (R11). Diputuskan: rilis pertama tanpa pemindaian virus (opsi a).** Pemindaian belum aktif: `FileStore` menandai semua unggahan `clean` langsung. Kompensasinya sudah berjalan dan diuji (`FileStoreTest`, 15 test): ekstensi dan MIME asli dari isi file (finfo), batas ukuran per jenis (PDF 10 MB, ZIP 20 MB, gambar 5 MB), pemeriksaan ZIP tanpa ekstraksi (path traversal, symlink, zip bomb), disk privat `lab` di luar web root dengan nama UUID dan unduhan hanya lewat `FileController` (`attachment`, `nosniff`, `private, no-store`). **Tugas setelah rilis:** pasang ClamAV (opsi b), lihat bagian 8.

Keputusan lain yang tercatat tetapi **tidak menghalangi**: pelacak galat eksternal atau cukup peringatan `failed_jobs` (T1), modul Program tetap nonaktif (T8), job CI MySQL (T2). Lihat [04](04-tech-debt.md).

## 3. Gerbang sebelum deploy

- [ ] CI `tests` hijau untuk **commit yang akan dideploy** (`gh run list --branch main --limit 1`; saat ini `2aeb973` hijau)
- [ ] Commit yang dideploy dicatat: `git rev-parse HEAD` → ______ (dipakai untuk rollback; sebaiknya diberi tag, mis. `v1.0.0`)
- [ ] `composer audit` bersih; `npm audit --omit=dev` = 0
- [ ] Keputusan 1 dan 2 (bagian 2) sudah terisi
- [ ] **Cadangan database dan `storage/app/private/`** sudah dibuat dan **uji pulihnya** pernah dicoba (08 §7). Pada server baru yang masih kosong, lewati
- [ ] Jendela deploy dipilih di luar jam kelas dan tenggat tugas, dan pengguna diberi tahu
- [ ] Orang yang bisa mengakses server (SSH) dan Admin siaga selama jendela deploy

## 4. Server dan `.env`

Rincian kebutuhan server: 08 §1. Konfigurasi `.env` disalin dari `.env.production.example`, lalu pastikan:

- [ ] `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://<domain>`; HTTPS aktif
- [ ] `SESSION_SECURE_COOKIE=true`, `SESSION_ENCRYPT=true`, `SESSION_LIFETIME=30`, `INERTIA_ENCRYPT_HISTORY=true` (W1)
- [ ] `DB_*` memakai user khusus (bukan `root`); `DB_QUEUE_RETRY_AFTER=330` (harus **lebih besar** dari `--timeout` worker 320 dan job rekap 300)
- [ ] `TRUSTED_PROXIES=` sesuai keputusan 1 (dibaca lewat `config/app.php`, jadi `php artisan config:cache` wajib setelah mengubahnya)
- [ ] `MAIL_MAILER=smtp` dengan kredensial sungguhan (keputusan 2)
- [ ] `LAB_PROGRAMS_ENABLED=false`
- [ ] `INERTIA_ENCRYPT_HISTORY=true` bila keputusan 4 diambil (variabel ini belum ada di `.env.production.example`; tambahkan)
- [ ] `ADMIN_NAME` dan `ADMIN_EMAIL` terisi (`ADMIN_EMAIL=lab.ir@itpln.ac.id`, akun lab, diputuskan Anda); `ADMIN_PASSWORD` dikosongkan agar dibuat acak
- [ ] `chmod 600 .env`; `storage/` dan `bootstrap/cache/` dapat ditulis user web server
- [ ] PHP 8.4 dengan `pdo_mysql, mbstring, xml, dom, fileinfo, zip, gd, intl`; `upload_max_filesize` dan `post_max_size` ≥ 25M; Nginx `client_max_body_size 25M`; document root ke `public/`

## 5. Langkah deploy

Detail dan contoh konfigurasi: 08 §3–4. Urutan ringkas:

- [ ] Mode pemeliharaan bila ini pembaruan: `php artisan down`
- [ ] Ambil kode pada commit yang dicatat
- [ ] `composer install --no-dev --optimize-autoloader`
- [ ] `npm ci && npm run build`, lalu `npm run types:check` **setelah** build (rute Wayfinder dihasilkan saat build). Build memakai Node 22 (CI) atau 24 (teruji lokal); `package.json` tidak mengunci versi Node
- [ ] `php artisan migrate --force` (**jangan** `migrate:fresh`: menghapus semua data)
- [ ] `php artisan config:cache && route:cache && view:cache && event:cache`
- [ ] Server baru: `php artisan db:seed --class=AdminSeeder --force`, catat password sementara yang tampil sekali, lalu `config:cache` ulang
- [ ] **`php artisan queue:restart`** (wajib: worker lama masih memegang kode dan konfigurasi lama)
- [ ] Worker Supervisor berjalan dengan `--sleep=3 --tries=1 --timeout=320` (`--tries=1` aman: notifikasi email membawa `$tries = 3` sendiri)
- [ ] Cron `* * * * * php artisan schedule:run` terpasang untuk user web server. Sembilan perintah terjadwal: `meetings:sync`, `announcements:notify`, `tasks:remind-deadlines`, `reminders:send`, `signatures:remind`, `shifts:expire-swaps`, `exports:prune`, `queue:prune-failed`, `housekeeping:prune`
- [ ] `php artisan up`

## 6. Uji setelah deploy

Urutan: lakukan butir 🖥️ dulu (hanya bisa di server), lalu alur ✅ yang sudah teruji di lokal.

**Hanya bisa di server**

- [ ] 🖥️ `https://<domain>/dev/login-as/admin` → **404** (bila terbuka, `APP_ENV` belum `production`)
- [ ] 🖥️ Halaman yang tidak ada → halaman 404 ramah, bukan halaman debug
- [ ] 🖥️ `curl -I https://<domain>/login` memuat `Strict-Transport-Security`, `X-Frame-Options`, `X-Content-Type-Options`. Bila HSTS tidak ada padahal HTTPS aktif, proxy belum dipercaya (`TRUSTED_PROXIES`). _Catatan: alihan tamu dan 404 untuk URL tanpa rute tidak membawa header ini (N13); itu perilaku yang diketahui_
- [ ] 🖥️ **IP klien (S6):** masuk sebagai Admin, lakukan satu aksi tercatat, buka Audit Log: kolom IP harus IP perangkat Anda, **bukan IP server atau proxy**
- [ ] 🖥️ **Email nyata:** Admin › Pengguna: buat dua atau tiga akun uji → email password sementara benar-benar masuk (pengiriman bertahap 20 per 30 detik, tunggu sampai sekitar satu menit). Lalu Lupa Password → kode OTP masuk. Hapus atau nonaktifkan akun ujinya
- [ ] 🖥️ Tabel `failed_jobs` kosong sesudahnya (`php artisan queue:failed`)
- [ ] 🖥️ Back setelah logout tidak menampilkan dashboard sebelumnya (hanya bila keputusan 4 diambil; perlu HTTPS)
- [ ] 🖥️ Backup harian berjalan sekali dan hasilnya terbaca (08 §7)

**Alur yang sudah teruji di lokal (05), ulangi singkat di produksi**

- [ ] ✅ Login dengan NIM dan dengan email; password salah → pesan galat yang sama untuk akun ada atau tidak
- [ ] 🖥️ 6 kali password salah → diminta menunggu (dari 08 §6; pembatas login **tidak** saya uji di browser, hanya di test PHP)
- [ ] ✅ Praktikan: gabung kelas dengan token, kumpul PDF, lihat nilai setelah dipublikasikan
- [ ] ✅ Asisten: buat tugas ke satu kelas, nilai (koma desimal `85,5` diterima), publikasikan, ubah nilai Published → praktikan menerima notifikasi "Nilai diperbarui"
- [ ] ✅ Import anggota ke kelas terkunci: peringatan "Kelas sedang dikunci" tampil dan import tetap berjalan
- [ ] ✅ Laporan ngawas: buat, verifikasi oleh Sekretaris/Bendahara, tampil di rekap
- [ ] ✅ **Generate › Rekap Laporan Bulanan:** ZIP Word dan Excel berubah dari "Antre" ke "Selesai", terunduh, dan terbuka di Word dan Excel (di lokal hanya isi XML yang diperiksa)
- [ ] ✅ **Rekap Jam Ngawas:** Tetapkan Final lalu Buka Kembali berjalan (teruji di browser)
- [ ] Diuji di test PHP, **belum di browser**: bulan di luar semua semester → kondisi kosong tanpa tombol Ekspor/Final (R2); bulan berbeda → label dan jumlah kolom hari sesuai (T1 audit)
- [ ] ✅ Admin menonaktifkan akun → sesi berjalan akun itu langsung berakhir
- [ ] Diuji di test PHP, **belum di browser**: catatan laporan yang diawali `=` tampil sebagai teks biasa di Excel rekap (S3), bukan formula; periksa di Excel sungguhan

## 7. Pemantauan minggu pertama

Belum ada pelacak galat (T1), jadi pemantauan ini manual. Tentukan siapa yang memeriksa dan kapan.

- [ ] Harian: `php artisan queue:failed` dan ukuran `storage/logs/` (log harian level `warning`); notifikasi lonceng Admin "Email password sementara gagal terkirim"
- [ ] **Beban pembaruan langsung (S8).** Browser menanyakan `/live/version` tiap 5 detik dan memuat ulang data bila token berubah. Di lokal, perubahan sampai ke sesi lain dalam 2,0 detik; beban banyak pengguna **belum diukur**. Pantau saat tenggat tugas atau sesi kelas ramai. Tanda perlu tindakan: halaman melambat saat banyak pengumpulan bersamaan, atau CPU/DB tinggi terus-menerus. Catat jumlah pengguna aktif saat itu
- [ ] **Waktu Tetapkan Final (R3).** Di lokal 1,34 detik untuk 4 asisten dan 1 laporan (batas bawah). Catat durasi saat rekap nyata pertama ditetapkan. Bila mendekati 30 detik (`max_execution_time` PHP-FPM) atau melewati timeout Nginx, pindahkan proses itu ke antrean
- [ ] Setelah rekap pertama ditetapkan Final: laporan baru untuk bulan itu ditolak dengan pesan "Rekap … sudah Final" dan berjalan lagi setelah rekap dibuka kembali (S4)
- [ ] Ukuran `jobs`, `sessions`, dan `cache` (semuanya di database); `housekeeping:prune` dan `queue:prune-failed` berjalan lewat cron
- [ ] Ukur ulang T6 dan T10 di [04](04-tech-debt.md) bila halaman terasa lambat

## 8. Item terbuka setelah deploy (tidak menghalangi)

| Item                                             | Sumber  | Status                                                                                                                                   | Kapan                             |
| ------------------------------------------------ | ------- | ---------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------- |
| Tidak ada pelacak galat atau peringatan antrean  | T1      | Belum ada                                                                                                                                | Minggu pertama (versi terkecil)   |
| Test dan CI hanya SQLite                         | T2      | Belum ada job MySQL; N8 aman di MySQL pada pengecekan manual (05)                                                                        | Dua minggu pertama                |
| Skala huruf mutu dua tempat dan belum resmi      | T3, S9  | Menunggu skala resmi                                                                                                                     | Sebelum nilai akhir dipakai resmi |
| Dependensi tertinggal; `vite-plus` 0.3.0 → 1.x   | T4      | 7 kerentanan npm hanya di alat pengembangan. Butuh `overrides` dan lockfile baru; **jangan** pakai `vp migrate`                          | Setelah deploy awal, PR terpisah  |
| Tidak ada test komponen frontend                 | T5      | Belum ada                                                                                                                                | Bulan pertama                     |
| `AddSecurityHeaders` tidak global                | T7, N13 | Alihan tamu dan 404 tanpa rute tidak berheader                                                                                           | Dua minggu pertama                |
| Modul Program nonaktif dan belum ditinjau        | T8      | `LAB_PROGRAMS_ENABLED=false`                                                                                                             | Tinjau sebelum diaktifkan         |
| Mode tinjauan nilai di ponsel                    | W2      | Kolom kosong di kanan                                                                                                                    | Opsional                          |
| Jam kosong lolos ke dialog konfirmasi laporan    | W3      | Server menolak setelah konfirmasi                                                                                                        | Opsional                          |
| Laporan lewat H+1 bisa dibuat (lahir terkunci)   | W4      | Pertanyaan kebijakan                                                                                                                     | Putuskan bersama Kepala Lab       |
| Publikasi nilai tanpa konfirmasi                 | W6      | Pertanyaan kebijakan                                                                                                                     | Putuskan bersama Kepala Lab       |
| Entri audit `grade.saved` pertama `status: null` | W5      | Kecil                                                                                                                                    | Opsional                          |
| Foto profil terbaca antar-pengguna               | R8      | Disengaja                                                                                                                                | Opsional                          |
| Pembatasan hak DB untuk `audit_logs`             | —       | Di luar kode aplikasi                                                                                                                    | Opsional, oleh pengelola DB       |
| Pemindaian virus ClamAV belum aktif              | R11     | Diterima untuk rilis pertama (bagian 2, butir 5). Pasang ClamAV, ubah `FileStore` agar `scan_status` mengikuti hasil pindai, tambah test | Minggu pertama sampai kedua       |

## 9. Rollback

**Putuskan sebelum deploy, bukan saat insiden.** Pemicu rollback:

- Login atau halaman utama (dashboard tiga peran) gagal dengan 5xx dan tidak pulih dalam 15 menit
- Email tidak terkirim sama sekali setelah SMTP diperiksa (akun tidak bisa dibuat atau reset password buntu) dan tidak ada perbaikan konfigurasi cepat
- Worker tidak memproses antrean (ekspor tertahan "Antre") dan `queue:restart` serta Supervisor tidak memulihkan
- Data tampak rusak atau hilang (nilai, pengumpulan, laporan)

Langkah:

- [ ] Kode: deploy ulang commit yang dicatat di bagian 3 (atau tag rilis sebelumnya), lalu `php artisan queue:restart`, `php artisan config:cache`, `php artisan route:cache`
- [ ] **Database:** rilis ini tidak menambah migrasi sejak `23f4848`, jadi mundur kode **tidak** butuh `migrate:rollback`. Bila server memang baru, rollback berarti menghentikan layanan, bukan memulihkan versi lama
- [ ] Data yang rusak: pulihkan dari cadangan database dan `storage/app/private/` (bagian 3). Pulihkan **keduanya** dari waktu yang sama
- [ ] Konfigurasi baru (`TRUSTED_PROXIES`, `DB_QUEUE_RETRY_AFTER`, `INERTIA_ENCRYPT_HISTORY`) aman diabaikan oleh versi lama
- [ ] Setelah rollback: beri tahu pengguna, catat penyebab di bagian 8, dan jangan deploy ulang sebelum penyebabnya dipahami

Rekap yang sudah ditetapkan Final dan berkas yang dihasilkan ada di database dan `storage/app/private/`; keduanya ikut tercakup cadangan.

## 10. Tanda terima

| Tahap                                 | Oleh | Waktu | Catatan |
| ------------------------------------- | ---- | ----- | ------- |
| Keputusan bagian 2 terisi             |      |       |         |
| Gerbang bagian 3 lolos                |      |       |         |
| Deploy selesai (bagian 5)             |      |       |         |
| Uji setelah deploy lolos (bagian 6)   |      |       |         |
| Pemantauan minggu pertama dijadwalkan |      |       |         |

## Yang tidak bisa saya buktikan

- Perilaku di server produksi (HTTPS, HSTS, proxy, SMTP, ClamAV, beban banyak pengguna). Semua ada di bagian 6 dan 7 sebagai butir yang harus diuji di tempat.
- Bahwa berkas Word dan Excel terbuka mulus di aplikasinya; di lokal hanya isi XML yang diperiksa.
- Bahwa akun SMTP kampus mengizinkan pengiriman dari aplikasi. Itu keputusan 2.
