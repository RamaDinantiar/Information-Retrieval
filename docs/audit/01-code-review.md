# Audit Pra-Deploy — 01 Tinjauan Kode

Tinjauan kode `ir-lab` sebelum deploy. **Tidak ada kode yang diubah**; dokumen ini hanya melaporkan temuan dan usulan.

- **Tanggal:** 2026-11-21
- **Basis:** `main` @ `164cc72` (sudah memuat `feat/single-bendahara`); lihat [00-baseline.md](00-baseline.md) untuk hasil tes dan audit dependensi
- **Metode:** membaca kode (rute, middleware, controller, action, model, migrasi, job, konfigurasi, dokumen deploy), pemindaian pola berbahaya dengan `grep`, dan satu percobaan kecil untuk membuktikan temuan T1
- **Dilakukan:** hanya baca. Tidak ada uji penetrasi dinamis dan tidak ada pengukuran performa (tanpa `EXPLAIN`, tanpa uji beban)

## Ringkasan

| Tingkat | Jumlah | Catatan |
|---|---|---|
| Kritis | 0 | Tidak ada celah otorisasi, injeksi SQL, XSS, atau eksekusi kode yang saya temukan |
| Tinggi | 2 | Keduanya mengenai alur yang dipakai rutin: Rekap Jam Ngawas dan impor akun |
| Sedang | 10 | Konfigurasi antrean/proxy, integritas data rekap, dan satu keputusan bisnis yang belum pasti |
| Rendah | 11 | Penanganan galat, kebersihan data, dan hal kecil |

**Rekomendasi:** perbaiki T1 dan T2 sebelum deploy, dan putuskan S4, S5, S9 (butuh keputusan Anda). S1, S6, dan S7 murah diperbaiki dan sebaiknya ikut sebelum deploy. Sisanya boleh menyusul.

Daftar temuan:

| ID | Tingkat | Judul |
|---|---|---|
| T1 | Tinggi | Bulan salah pada tanggal 29–31 (13 titik), termasuk saat Tetapkan Final |
| T2 | Tinggi | Impor akun massal mengirim ratusan email password tanpa throttle dan tanpa percobaan ulang |
| S1 | Sedang | `retry_after` antrean (90 dtk) lebih kecil dari `timeout` job (300 dtk) |
| S2 | Sedang | Password sementara tersimpan polos di `jobs`/`failed_jobs`; `failed_jobs` tidak pernah dibersihkan |
| S3 | Sedang | Teks bebas masuk sel Excel rekap sebagai formula |
| S4 | Sedang | Laporan baru bisa dibuat untuk tanggal lampau dan bulan yang sudah Final |
| S5 | Sedang | Semester laporan ngawas diambil dari semester aktif, bukan dari kelasnya |
| S6 | Sedang | `trustProxies` belum diatur (bila di belakang reverse proxy) |
| S7 | Sedang | Batas per IP bisa memblokir pengguna sah di jaringan kampus |
| S8 | Sedang | Pembaruan langsung memicu muat ulang serentak pada setiap penyimpanan data |
| S9 | Sedang | Skala huruf mutu bertanda "asumsi" di kode |
| S10 | Sedang | Foto laporan lama terhapus sebelum foto baru lolos validasi |
| R1–R11 | Rendah | Lihat bagian akhir |

---

## Tinggi

### T1. Bulan salah pada tanggal 29–31 (13 titik), termasuk saat Tetapkan Final

**Di mana:** `CarbonImmutable::createFromFormat('Y-m', $month)->startOfMonth()` muncul di 13 tempat:

| File | Baris |
|---|---|
| `app/Support/Reports/NgawasRecap.php` | 46 |
| `app/Support/Reports/HoursRecap.php` | 25 |
| `app/Support/Reports/NgawasRecapChecks.php` | 33 |
| `app/Http/Controllers/Asisten/NgawasRecapController.php` | 35, 78, 133 |
| `app/Http/Controllers/Asisten/NgawasRecapOrderController.php` | 81 |
| `app/Http/Controllers/Asisten/OutsideClassController.php` | 42 |
| `app/Http/Controllers/Asisten/ReportController.php` | 40 |
| `app/Http/Controllers/Asisten/GenerateController.php` | 31 |
| `app/Jobs/GenerateMonthlyReport.php` | 41, 81 |
| `app/Listeners/SendLabNotifications.php` | 109 |

**Masalah:** `createFromFormat('Y-m', ...)` tanpa penanda reset (`!`) mengisi hari dengan **hari ini**. Bila hari ini tanggal D dan bulan yang diminta punya kurang dari D hari, hasilnya meluap ke bulan berikutnya, lalu `startOfMonth()` memilih bulan yang salah.

**Bukti** (dijalankan dengan waktu uji di PHP 8.4 + Carbon proyek):

```
today=2026-03-31 request=2026-02 -> startOfMonth=2026-03-01  WRONG MONTH
today=2026-05-31 request=2026-06 -> startOfMonth=2026-07-01  WRONG MONTH
today=2026-01-30 request=2026-02 -> startOfMonth=2026-03-01  WRONG MONTH
today=2026-11-21 request=2026-02 -> startOfMonth=2026-02-01  OK
```

**Dampak:**

- Pada tanggal 29–31, memilih bulan yang lebih pendek menampilkan data bulan lain dengan label bulan yang diminta.
- `FinalizeRecap::handle()` (`app/Actions/NgawasRecap/FinalizeRecap.php:37`) memanggil `RecapEngine::build($semester, $month)`. Bila hari itu tanggal 29–31, snapshot dan berkas Excel **Final** dibangun dari bulan yang salah, tetapi disimpan dengan kunci `$month` yang diminta. Berkas itu resmi dan dibaca Dekan.
- Tes tidak menangkapnya karena `phpunit` tidak membekukan tanggal ke 29–31. Hari ini (21) kebetulan aman.

**Usulan:** satu pembantu terpusat, mis. `Months::start(string $month): CarbonImmutable` yang memakai `CarbonImmutable::createFromFormat('!Y-m', $month)` (tanda `!` mereset hari ke 1), lalu ganti 13 pemakaian. Tambah tes dengan `Carbon::setTestNow('2026-03-31')` untuk `NgawasRecap::build`, `FinalizeRecap`, dan `ReportController::index`.

---

### T2. Impor akun massal mengirim ratusan email password tanpa throttle dan tanpa percobaan ulang

**Di mana:** `app/Actions/User/ImportUsers.php:73` → `CreateUserAccount.php:43` → `IssueTemporaryPassword.php:31` → `app/Notifications/AccountCredentialsNotification.php:15`; worker di `docs/08-deployment.md:99` (`--tries=1`).

**Masalah:**

- Impor mendukung hingga 1.000 baris (`ImportUsers::MAX_ROWS`). Tiap baris langsung memasukkan satu email ke antrean, **tanpa jeda**.
- Untuk notifikasi biasa, `Notify::mail()` (`app/Support/Notifications/Notify.php:35-53`) sengaja memecah menjadi 20 penerima per 30 detik "agar tidak melewati batas kirim SMTP", dan `LabMailNotification` punya `$tries = 3` dengan backoff. Jalur impor melewati keduanya.
- `AccountCredentialsNotification` tidak punya `$tries`, jadi memakai `--tries=1` dari worker. Satu penolakan SMTP (pembatasan Office 365) langsung membuat job gagal permanen.
- Admin tetap melihat toast sukses ("N akun praktikan dibuat"). Praktikan yang emailnya gagal tidak punya cara tahu, dan Admin tidak diberi tahu.

**Dampak:** impor awal semester, alur onboarding utama, bisa meninggalkan sebagian praktikan tanpa password. Pemulihannya manual: reset password satu per satu.

**Usulan:**

1. Beri `AccountCredentialsNotification` `$tries = 3` dan `$backoff = [60, 300]`, seperti `LabMailNotification`.
2. Pada impor, sebar pengiriman dengan `->delay()` bertahap (pola yang sama dengan `Notify::mail`).
3. Tampilkan jumlah email gagal ke Admin (mis. lewat status di hasil impor, atau tautan ke daftar akun yang belum menerima email).

---

## Sedang

### S1. `retry_after` antrean (90 dtk) lebih kecil dari `timeout` job (300 dtk)

**Di mana:** `config/queue.php:43` (`DB_QUEUE_RETRY_AFTER` default 90); `app/Jobs/GenerateNgawasRecapWorkbook.php:30` dan `app/Jobs/GenerateMonthlyReport.php:31` (`timeout = 300`); `.env.production.example` tidak mengatur `DB_QUEUE_RETRY_AFTER`; `docs/08-deployment.md:99-103` hanya menyebut `--timeout`.

**Masalah:** untuk driver antrean `database`, job yang berjalan lebih dari `retry_after` dianggap hilang dan dilepas lagi ke antrean. Worker kedua mengambilnya, melebihi `--tries=1`, lalu memanggil `failed()` yang menandai ekspor "gagal" padahal job pertama masih berjalan dan akan menandainya "selesai". Hasilnya status tidak menentu dan bisa ada file ganda.

**Catatan:** saya belum mengukur lama ekspor. Bila selalu di bawah 90 detik, ini tidak muncul, tetapi konfigurasinya tetap salah menurut aturan Laravel (`retry_after` harus lebih besar dari `timeout`).

**Usulan:** `DB_QUEUE_RETRY_AFTER=330` di `.env.production.example`, dan tambahkan catatan di `docs/08-deployment.md` bersama `--timeout=320`.

### S2. Password sementara tersimpan polos di antrean; `failed_jobs` tidak pernah dibersihkan

**Di mana:** `AccountCredentialsNotification.php:20` (properti `temporaryPassword` ikut di-serialisasi ke `jobs.payload`); tidak ada `queue:prune-failed` di `routes/console.php`.

**Masalah:** selama job belum diproses, dan **selamanya** bila gagal, password sementara yang masih berlaku tersimpan polos di tabel `jobs`/`failed_jobs`. Siapa pun yang bisa membaca database (cadangan, akses DB) bisa membacanya. `must_change_password` membatasi, tetapi password tetap valid sampai pengguna pertama kali masuk.

**Usulan:** implementasikan `Illuminate\Contracts\Queue\ShouldBeEncrypted` pada notifikasi itu, dan jadwalkan `queue:prune-failed --hours=48`.

### S3. Teks bebas masuk sel Excel rekap sebagai formula

**Di mana:** `app/Support/Reports/NgawasRecapWorkbook.php:239` (nama asisten), `:243` (label kelas), `:525-527` (kelas dan catatan tabel Tambahan/Pengganti). Sumber teks bebas: `replacement_note` laporan (`SaveSupervisionReport.php:203`), nama kelas di luar Lab IR (`OutsideClassController`), `name` pengguna.

**Masalah:** `setCellValue()` PhpSpreadsheet mengubah string berawalan `=` menjadi formula. Seorang asisten bisa mengisi catatan pengganti seperti `=HYPERLINK("http://…","klik")` atau fungsi pembaca data lain. Berkas rekap itu dibuka Sekretaris/Bendahara dan dikirim ke Dekan.

**Pembanding:** `SessionController.php:121-122` sudah memitigasi hal yang sama untuk CSV presensi (awalan `'`). Ekspor nilai (`GradeController::export`) memakai OpenSpout yang menulis string apa adanya, jadi aman.

**Usulan:** tulis semua teks pengguna dengan `setCellValueExplicit(..., DataType::TYPE_STRING)`, atau bungkus satu pembantu `safeText()` yang memberi awalan `'` pada `=`, `+`, `-`, `@`, tab, dan CR.

### S4. Laporan baru bisa dibuat untuk tanggal lampau dan bulan yang sudah Final

**Di mana:** `app/Actions/Report/SaveSupervisionReport.php:41-44` (hanya menolak tanggal masa depan), `:84` (`editable_until = tanggal + 1 hari`); `ReviewSupervisionReport.php:53-64` (`guard()` tidak memeriksa status rekap).

**Masalah:** kunci H+1 hanya membatasi **pengubahan** laporan. Pembuatan laporan baru untuk tanggal apa pun sebelum hari ini tetap diterima (langsung terkunci karena `editable_until` sudah lewat, tetapi tetap bisa berstatus Diajukan dan lalu Diverifikasi). Tidak ada pemeriksaan bahwa bulan itu sudah `Final`. Satu-satunya pengaman adalah penanda `changedSinceFinal` di tampilan (`NgawasRecapController.php:150`), yang hanya mengingatkan.

**Pertanyaan untuk Anda:** apakah laporan susulan memang diizinkan? Bila tidak, batasi pembuatan ke H+1 (kecuali Admin) dan tolak verifikasi untuk bulan yang sudah Final sampai dibuka kembali.

### S5. Semester laporan ngawas diambil dari semester aktif, bukan dari kelasnya

**Di mana:** `SaveSupervisionReport.php:73` — `$semesterId = Semester::current()->id ?? $class->semester_id ?? $outside?->semester_id`.

**Masalah:** setelah semester baru diaktifkan (semester lama otomatis Arsip, `ActivateSemester`), laporan untuk kelas semester lama yang masih dalam jendela H+1 tersimpan dengan `semester_id` semester baru. `NgawasRecap::build()` memfilter `semester_id` (`NgawasRecap.php:53`), jadi laporan itu tidak muncul di rekap bulan terakhir semester lama, dan tidak muncul juga di semester baru karena tanggalnya di luar rentang.

**Usulan:** turunkan `semester_id` dari kelas atau kelas di luar Lab IR yang dipilih (`$class->semester_id ?? $outside->semester_id`), dan gunakan semester aktif hanya sebagai cadangan.

### S6. `trustProxies` belum diatur (bila di belakang reverse proxy)

**Di mana:** `bootstrap/app.php:33-47` tidak memanggil `trustProxies()`; tidak ada rujukan `TRUSTED_PROXIES` di repo atau dokumen deploy.

**Masalah:** bila server berada di belakang reverse proxy, WAF, atau CDN, `$request->ip()` bernilai IP proxy untuk semua pengguna dan `isSecure()` bernilai `false`. Akibatnya:

- Semua pembatas per IP menjadi **satu batas global**: login (`FortifyServiceProvider.php:105`), registrasi (`CreateNewUser.php:30,48`), reset password (`ThrottlePasswordReset.php:21-23`), `live/version` (`bootstrap/app.php:29`).
- HSTS tidak dikirim (`AddSecurityHeaders.php:27`); tautan ber-signed URL bisa dihasilkan dengan `http://` sehingga diblokir sebagai mixed content di halaman HTTPS.
- Kolom IP audit log (`AuditLog.php:50`) berisi IP proxy.

**Catatan:** `docs/08-deployment.md` menggambarkan Nginx + PHP-FPM langsung, di mana ini tidak muncul. Mohon konfirmasi topologi sebenarnya.

**Usulan:** `$middleware->trustProxies(at: env('TRUSTED_PROXIES'))` dan dokumentasikan nilainya.

### S7. Batas per IP bisa memblokir pengguna sah di jaringan kampus

**Di mana:** `CreateNewUser.php:30,45-48` (registrasi: 10 percobaan per IP per jam, dihitung **sebelum** validasi, jadi salah ketik ikut terhitung); `ThrottlePasswordReset.php:21-23` (reset: 20/jam per IP); `bootstrap/app.php:29` (`live/version`: 120/menit per IP).

**Masalah:** kode sendiri mengakui bahwa praktikan satu kampus berbagi IP (`ThrottlePasswordReset.php:12-14`). Saat pendaftaran dibuka di awal semester, belasan mahasiswa di Wi-Fi kampus yang sama bisa menghabiskan batas registrasi satu jam, dan sisanya diblokir. Polling `live/version` (S8) memperparah: 10 tab terbuka sudah mencapai 120 permintaan/menit.

**Usulan:** hitung percobaan registrasi hanya saat validasi lolos, naikkan batas per IP (mis. 60/jam) dengan batas per email tetap ketat, dan naikkan batas `live/version` atau hapus batas IP untuk rute baca-saja itu.

### S8. Pembaruan langsung memicu muat ulang serentak pada setiap penyimpanan data

**Di mana:** `AppServiceProvider.php:53-55` (semua `saved`/`deleted` model menaikkan token); `LiveVersion.php:27-30`; `resources/js/hooks/use-live-updates.ts:3,35-51` (polling 5 dtk; `router.reload()` bila token berubah).

**Masalah:** token bersifat **global**. Satu praktikan mengumpulkan tugas, satu notifikasi dibuat, atau satu nilai disimpan, dan **semua** klien terbuka memuat ulang data halamannya. Pada tenggat tugas atau saat sesi kelas berlangsung, penulisan beruntun berarti muat ulang beruntun dari semua pengguna aktif. Setiap muat ulang juga menjalankan query bersama (`HandleInertiaRequests`: semester aktif, jumlah dan daftar notifikasi).

**Catatan:** ini penalaran dari kode, belum diukur. Desain `DB::afterCommit` sudah menghindari kunci baris, dan skala lab (ratusan pengguna) mungkin masih memadai.

**Usulan:** ukur dulu pada beban tenggat. Bila perlu, pecah token per cakupan (kelas atau pengguna) alih-alih satu token global.

### S9. Skala huruf mutu bertanda "asumsi" di kode

**Di mana:** `app/Support/Grading/FinalScore.php:56-69`, komentar baris 57: "asumsi skala umum; sesuaikan dengan kebijakan kampus".

**Masalah:** batas A ≥ 85, AB ≥ 80, B ≥ 70, BC ≥ 65, C ≥ 55, D ≥ 40 belum dikonfirmasi ke aturan akademik IT PLN. Huruf ini tampil ke praktikan dan masuk ekspor nilai.

**Pertanyaan untuk Anda:** apakah skala ini sesuai aturan kampus? Bila belum pasti, pertimbangkan menyembunyikan kolom huruf sampai dikonfirmasi.

### S10. Foto laporan lama terhapus sebelum foto baru lolos validasi

**Di mana:** `SaveSupervisionReport.php:113-116`.

**Masalah:** `$report->photos()->get()->each->delete()` berjalan **sebelum** `FileStore::store()` memeriksa foto baru. Hook `deleted` pada `StoredFile` (`StoredFile.php:85`) menghapus file di disk seketika. Bila foto baru ditolak (MIME salah), transaksi database dibatalkan dan baris lama kembali, tetapi file lamanya sudah hilang. Rekaman rusak: baris ada, file tidak.

**Pembanding:** `AvatarController::update` (`AvatarController.php:44-47`) sudah memakai urutan yang benar (simpan baru, baru hapus lama).

**Usulan:** simpan foto baru dulu, hapus yang lama setelah `store()` berhasil.

---

## Rendah

| ID | File:baris | Temuan | Usulan |
|---|---|---|---|
| R1 | `ReportController.php:39`, `GenerateController.php:30` | Regex bulan `\d{2}` menerima `2026-13`/`2026-00`, sehingga `createFromFormat` melempar galat dan halaman 500. Controller lain sudah memakai `(0[1-9]\|1[0-2])`. | Samakan regex, atau gabungkan dengan perbaikan T1. |
| R2 | `NgawasRecapController.php:168-176` | `semesterFor()` jatuh ke semester aktif bila bulan di luar semua semester, sehingga pesan "Bulan ini tidak termasuk semester mana pun" tidak pernah muncul selama ada semester aktif, dan bulan di luar rentang bisa difinalkan. | Kembalikan `null` bila tidak ada semester yang mencakup bulan itu. |
| R3 | `NgawasRecapController.php:97-107` → `FinalizeRecap.php:63-67` | Tetapkan Final membangun berkas Excel (PhpSpreadsheet + tanda tangan) di dalam request HTTP, sedangkan ekspor Draft lewat antrean (`:90`). Dengan banyak asisten bisa melewati `max_execution_time` PHP-FPM atau timeout Nginx. | Ukur waktunya; bila lambat, pindahkan ke antrean dengan status progres. |
| R4 | `GradeController.php:93`, `SaveGradesBatch.php:63-75` | `submission_id` ganda dalam satu batch membuat dua `new Grade` untuk submission yang sama, melanggar unique `grades.submission_id`, dan hasilnya 500. | Validasi `grades.*.submission_id` dengan `distinct`. |
| R5 | `SubmitTask.php:46-48,89` | Cek pengumpulan ganda dan perhitungan `version` dilakukan di luar kunci. Dua permintaan bersamaan (klik ganda) sama-sama lolos, lalu yang kedua gagal di unique `(task_target_id, user_id, version)`. Data tetap utuh, tetapi pengguna melihat galat 500. | Pindahkan cek dan `max(version)` ke dalam transaksi dengan `lockForUpdate`, atau tangkap `QueryException` dan tampilkan pesan ramah. |
| R6 | `NgawasSignatureFiles.php:25` | `tempnam()` membuat berkas kosong, lalu kode menambahkan `.png` ke jalur dan menulis ke berkas lain. Berkas kosong asli tidak pernah dihapus, jadi setiap ekspor meninggalkan satu berkas sampah per tanda tangan di folder sementara. | Hapus berkas dasar `tempnam`, atau tulis langsung ke jalur `tempnam` tanpa menambah ekstensi. |
| R7 | `routes/console.php:70-99` | Tidak ada pembersihan untuk `notifications` (satu baris per penerima per notifikasi), `password_reset_codes` (baris kedaluwarsa tidak dihapus), dan `audit_logs`. Tumbuh tanpa batas, tetapi masih wajar untuk skala lab. | Jadwalkan pembersihan notifikasi terbaca > 90 hari dan kode OTP kedaluwarsa. |
| R8 | `AvatarController.php:22-34`, `routes/settings.php:17` | Foto profil dapat dibaca pengguna mana pun yang sudah masuk lewat ID berurutan (komentar kode menyebut ini disengaja). Gambar tidak di-encode ulang, jadi metadata EXIF (mis. lokasi) tetap ikut. | Opsional: encode ulang dengan GD saat unggah (sudah ada pola di `SignatureImage`). |
| R9 | `AuditLog.php:70-75`, `SaveGradesBatch.php:76` | Append-only hanya ditegakkan di level model (`updating`/`deleting`); `AuditLog::query()->delete()` melewatinya. Audit `grade.saved` menyimpan **isi feedback** tiap praktikan. | Pertimbangkan pembatasan hak DB untuk tabel ini; catat hanya skor, bukan isi feedback. |
| R10 | `NgawasRecapChecks.php:179` | `$date->isFuture()` bernilai salah untuk hari ini pukul 00:00, jadi kelas yang terjadwal hari ini muncul sebagai "kemungkinan lupa lapor" sebelum kelasnya berlangsung. Hanya peringatan. | Bila hari ini ikut dilewati, ganti dengan `$date->greaterThanOrEqualTo(today())`. |
| R11 | `docs/10-security.md:40` | ClamAV belum aktif; `scan_status` langsung `clean`. Sudah terdokumentasi, dicatat di sini agar tidak terlewat saat deploy. | Putuskan apakah cukup untuk peluncuran awal. |

---

## Yang sudah baik

Bagian ini penting agar perbaikan tidak merusak apa yang sudah benar.

**Keamanan**

- **Otorisasi per peran dan per objek konsisten.** Grup rute memakai `role:` (`routes/web.php:62`); setiap controller yang mengambil objek lewat ID memeriksa kepemilikan (`AuthorizesClassAssistant`, `Task::isManagedBy`, `abort_unless($enrollment->class_id === $class->id)`, `Export::user_id`, `fileVisibleTo`). Saya tidak menemukan IDOR pada controller yang diperiksa.
- **Hak Sekretaris/Bendahara** dipusatkan di `User::canVerifyReports/canViewAllRecaps/canManageNgawasRecap` dan ditegakkan di server, bukan hanya di UI (`ReviewSupervisionReport::guard`, `NgawasRecapController`). Larangan memverifikasi laporan sendiri juga ada di server.
- **Akses file:** disk privat, nama UUID, signed URL 15 menit, dan `visibleTo` per pemilik (`FileController`, `StoredFile`). MIME asli dibaca dari isi file (`finfo`), ZIP diperiksa tanpa ekstraksi (path traversal, symlink, jumlah entri, ukuran). Tanda tangan diproses ulang menjadi PNG (`SignatureImage`).
- **Injeksi:** semua `*Raw` bersifat statis tanpa input pengguna; tidak ada `$request->all()` ke model; tidak ada `dangerouslySetInnerHTML` di `resources/js`.
- **Sandi dan sesi:** OTP reset di-hash, berumur 10 menit, maksimal 5 salah, jeda kirim ulang 60 detik, dan dikirim sinkron agar kegagalan SMTP terlihat. Sesi memakai `EnsureUserIsActive` (akun dinonaktifkan langsung keluar) dan `EnsurePasswordIsChanged`. Header keamanan sudah ada.
- **Keputusan produk yang sudah diterima:** pesan "belum terdaftar" pada lupa password (memengaruhi enumerasi akun) adalah keputusan yang tercatat, jadi tidak dianggap temuan.

**Rekap Jam Ngawas**

- Rumus sel `SKS × 50 / 60` benar (`SupervisionReport::recapHours`), dan Excel memakai formula yang sama (`='SKS'*50/60`, `NgawasRecapWorkbook.php:259`).
- Pembulatan **per asisten** setengah ke atas dengan toleransi float (`NgawasRecap::roundHours`), total keseluruhan = jumlah total yang sudah dibulatkan, sesuai PRD dan konsisten dengan `ROUND(SUM(...),0)` di Excel.
- Status Ditiadakan menghasilkan 0 jam; duplikat asisten × kelas × tanggal diblokir; asisten tanpa kelas mendapat baris `-`; Asisten Umum hanya melihat barisnya sendiri; Tetapkan Final menyimpan snapshot data dan tanda tangan, dan perubahan pengesah tidak mengubah rekap lama.

**Data dan performa**

- Unique index yang tepat (`submissions(task_target_id,user_id,version)`, `grades.submission_id`, `enrollments(class_id,user_id)`, `meetings(class_id,number)`), `lockForUpdate` pada `JoinClass` dan `ActivateSemester`, penguncian optimistis nilai lewat `version`.
- Pengiriman email massal untuk notifikasi biasa dipecah per 20 penerima.
- Pada `HandleInertiaRequests`, shared props memakai closure; hampir semua daftar memakai `with()` (tidak ditemukan N+1 yang jelas pada controller yang saya baca).

---

## Cakupan dan keterbatasan

**Dibaca penuh atau sebagian besar:** `routes/*`, semua middleware, `bootstrap/app.php`, `FileController`, `FileStore`, `SignatureImage`, `ZipBrowser`, `Avatar/Signature/NgawasApprover/NgawasTemplate` controller, `ForgotPasswordController`, `PasswordResetCode`, `CreateNewUser` (sebagian), `UserController`/`UserRequest`/`ImportUsers`/`CreateUserAccount`/`IssueTemporaryPassword`, `Asisten\TaskController`/`TaskRequest`/`SaveTask`, `GradeController` + `SaveGradesBatch`/`PublishGrades`/`FinalScore`, `ReportController` + `SaveSupervisionReport`/`ReviewSupervisionReport`, `SessionController` (sebagian), `Praktikan\TaskController` (sebagian) + `SubmitTask`/`JoinClass`, `SearchController`, seluruh engine rekap (`NgawasRecap`, `NgawasRecapChecks`, `FinalizeRecap`, `ReopenRecap`, `GenerateNgawasRecapWorkbook`, `NgawasApprovers`, `NgawasSignatureFiles`), model inti, migrasi tabel utama, `config/queue.php`, `.env*.example`, `docs/08-deployment.md`.

**Hanya dipindai dengan `grep` atau diperiksa daftar otorisasinya, tidak dibaca baris per baris:** `ClassDetailController`, `MeetingController`, `ShiftController`/`ShiftSwapController` (dan action `SwapShift`), `ClassAnnouncementController`, `TaskTargetController`, `OutsideClassController`, `NgawasRecapOrderController`, `Praktikan\SeatController`/`GradeController`, controller Admin selain yang tercantum di atas.

**Belum diperiksa:**

- Modul Program (nonaktif lewat `LAB_PROGRAMS_ENABLED=false`) dan pemetaan kursi.
- Detail pemetaan sel di `NgawasRecapWorkbook` (hanya dibaca penggunaan `setCellValue` dan kontrol verifikasi angka), serta `HoursRecap`, `DailyActivityReport`, dan template Word `${...}`.
- Komponen React di luar pemindaian pola; passkey/2FA Fortify; templat email.
- Perilaku `ZipArchive` terhadap ukuran terdeklarasi yang dipalsukan pada ZIP berbahaya (pemeriksaan `inspectZip` dan `ZipBrowser` mengandalkan ukuran dari direktori pusat); tidak saya verifikasi.
- Waktu eksekusi nyata ekspor, kueri lambat, dan perilaku di bawah beban.

---

## Usulan urutan perbaikan (menunggu persetujuan Anda)

| Tahap | Isi | Butuh keputusan Anda? |
|---|---|---|
| 1 | T1 (pembantu bulan + tes tanggal 29–31) dengan R1 dan R2 sekalian | Tidak |
| 2 | T2 + S2 (email kredensial: retry, jeda, enkripsi payload, pembersihan `failed_jobs`) dan S1 (`retry_after`) | Tidak |
| 3 | S3 (formula Excel), S10 (urutan hapus foto), R4–R6 (galat kecil) | Tidak |
| 4 | S6 + S7 (proxy dan batas per IP) | Ya: topologi server (di belakang proxy atau tidak) |
| 5 | S4 + S5 (aturan laporan susulan dan semester) | Ya: kebijakan laporan susulan dan bulan Final |
| 6 | S9 (skala huruf mutu) | Ya: skala resmi kampus |
| 7 | S8 (beban pembaruan langsung), R3, R7–R11 | Setelah pengukuran beban |

Tiap tahap akan saya kerjakan dengan tes baru, lalu saya jalankan ulang `php artisan test`, PHPStan, Pint, `npm run check`, dan build.
