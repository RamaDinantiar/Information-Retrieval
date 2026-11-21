# Audit Pra-Deploy — 02 Status Perbaikan

Status tiap temuan di [01-code-review.md](01-code-review.md) setelah perbaikan. Semua perubahan ada di branch `chore/pre-deploy-audit` dan belum di-push.

- **Tanggal:** 2026-11-21
- **Hasil akhir:** `php artisan test` 554 lolos (baseline 533), PHPStan 0 error, Pint lolos, `npm run check`, `npm run types:check`, dan `npm run build` lolos

Legenda: **Selesai** = diperbaiki dan diuji. **Disesuaikan** = diperbaiki, tetapi berbeda dari usulan di laporan (alasan dicantumkan). **Ditunda** = tidak diubah, dengan alasan.

## Tinggi

| ID  | Status  | Commit    | Catatan                                                                                                                                 |
| --- | ------- | --------- | --------------------------------------------------------------------------------------------------------------------------------------- |
| T1  | Selesai | `b0becf8` | Semua 13 pemakaian memakai `createFromFormat('!Y-m', …)`. 4 dari 5 tes baru gagal tanpa perbaikan.                                      |
| T2  | Selesai | `8b7de09` | Impor menyebar email 20 per 30 detik; notifikasi punya `$tries = 3` dengan backoff; Admin mendapat notifikasi lonceng bila tetap gagal. |

## Sedang

| ID  | Status          | Commit    | Catatan                                                                                                                                                                  |
| --- | --------------- | --------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| S1  | Selesai         | `8b7de09` | `DB_QUEUE_RETRY_AFTER` default 330, ada di `.env.example`, `.env.production.example`, dan panduan deploy.                                                                |
| S2  | Selesai         | `8b7de09` | Payload dienkripsi (`ShouldBeEncrypted`, dibuktikan lewat tabel `jobs` sungguhan); `queue:prune-failed --hours=48` dijadwalkan harian.                                   |
| S3  | Selesai         | `ecf0e25` | Teks pengguna di Excel rekap ditulis sebagai string. Tes gagal tanpa perbaikan.                                                                                          |
| S4  | **Disesuaikan** | `3d907ad` | Hanya bagian "bulan Final tidak boleh berubah" yang diterapkan. Pembatasan pembuatan laporan ke H+1 **tidak** diterapkan, lihat di bawah.                                |
| S5  | Selesai         | `3d907ad` | `semester_id` laporan diambil dari kelasnya. Pemeriksaan "kelas di luar Lab IR milik semester aktif" dihapus karena menjadi tidak berarti.                               |
| S6  | Selesai         | `0ae5129` | `TRUSTED_PROXIES` lewat `config/app.php` (bukan `env()` di `bootstrap/app.php`, yang akan diabaikan saat `config:cache`). Diuji dengan permintaan ber-`X-Forwarded-For`. |
| S7  | Selesai         | `0ae5129` | Registrasi 10 → 60/jam per IP, kirim kode reset 20 → 60/jam, `live/version` 120 → 600/menit.                                                                             |
| S8  | **Ditunda**     |           | Butuh pengukuran beban pada tenggat tugas lebih dulu; saya tidak punya data.                                                                                             |
| S9  | **Ditunda**     |           | Skala resmi kampus belum diketahui. Kode tidak diubah.                                                                                                                   |
| S10 | Selesai         | `ecf0e25` | Foto baru disimpan dulu, foto lama dihapus setelahnya. Tes gagal tanpa perbaikan.                                                                                        |

## Rendah

| ID  | Status             | Commit    | Catatan                                                                                                                                                                  |
| --- | ------------------ | --------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| R1  | Selesai            | `b0becf8` | Regex bulan `(0[1-9]\|1[0-2])` di `ReportController` dan `GenerateController`.                                                                                           |
| R2  | Selesai            | `b0becf8` | `semesterFor()` tidak lagi jatuh ke semester aktif; bulan di luar semester ditolak.                                                                                      |
| R3  | Ditunda            |           | Tetapkan Final masih sinkron di request HTTP; butuh pengukuran waktu nyata.                                                                                              |
| R4  | Selesai            | `ecf0e25` | `distinct` pada `grades.*.submission_id`.                                                                                                                                |
| R5  | Selesai            | `ecf0e25` | Baris target dikunci dan pengumpulan ganda diperiksa ulang di dalam transaksi. Tidak ada tes konkurensi deterministik; hanya tes regresi yang ada yang menjaga perilaku. |
| R6  | Selesai            | `ecf0e25` | Berkas dasar `tempnam` dihapus.                                                                                                                                          |
| R7  | Selesai            | `5ec0e4b` | `housekeeping:prune` harian (notifikasi terbaca > 90 hari atau apa pun > 1 tahun, OTP kedaluwarsa).                                                                      |
| R8  | Tidak diubah       |           | Foto profil terbaca antar-pengguna adalah desain yang disengaja; encode ulang opsional.                                                                                  |
| R9  | Selesai (sebagian) | `5ec0e4b` | Audit nilai tidak lagi menyimpan isi feedback. Pembatasan hak DB untuk `audit_logs` tidak dikerjakan (di luar kode aplikasi).                                            |
| R10 | Selesai            | `5ec0e4b` | Kelas hari ini dilewati sampai harinya lewat.                                                                                                                            |
| R11 | Tidak diubah       |           | ClamAV: keputusan peluncuran, bukan perubahan kode.                                                                                                                      |

## Penyimpangan dari usulan, dan alasannya

**S4.** Saya sempat mengusulkan membatasi pembuatan laporan ke H+1. Itu tidak diterapkan karena Admin tidak bisa membuat laporan sebagai pengganti asisten (aturan "Anda harus termasuk dalam tim" hanya menerima peran Asisten), sehingga asisten yang lupa lapor tidak punya jalan pulih. Pemeriksaan "kelas tanpa laporan" di rekap juga mengandalkan laporan susulan. Yang diterapkan memiliki jalan pulih: setelah Final, laporan tidak bisa dibuat, diubah, diverifikasi, atau dikembalikan sampai Sekretaris/Bendahara membuka kembali rekapnya.

## Yang masih perlu keputusan atau tindakan Anda

1. **S9, skala huruf mutu.** Konfirmasi skala resmi IT PLN (`app/Support/Grading/FinalScore.php:56-69`).
2. **S6, topologi server.** Bila di belakang proxy/CDN/WAF, isi `TRUSTED_PROXIES` di `.env` produksi, lalu cek kolom IP di Audit Log setelah deploy.
3. **Dependensi (tidak termasuk dalam tahap ini).** `composer audit` (12 advisory) dan `npm audit` (10 kerentanan) di [00-baseline.md](00-baseline.md) belum diperbaiki.
4. **S8 dan R3.** Ukur beban pada tenggat tugas dan waktu Tetapkan Final dengan data nyata sebelum memutuskan.
