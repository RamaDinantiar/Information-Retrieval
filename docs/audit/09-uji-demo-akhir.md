# Audit 09 — Uji demo akhir sebelum deploy

**Tanggal:** 2026-11-24/25 · **Branch:** `test/uji-demo-akhir` (dari `main` bersih) · **Penguji:** Claude Code
**Alat:** Playwright (Python) dengan Chromium terpisah. "Claude in Chrome" tidak tersedia di sesi ini; Chrome dan Edge Anda tidak disentuh.
**Lingkungan:** `http://ir-lab.test` (Herd), Vite dev, `queue:listen`, `MAIL_MAILER=log`, data seeder demo.
**Sifat dokumen:** laporan. **Tidak ada kode aplikasi yang diubah di branch ini.** Temuan menunggu keputusan Anda.

## 1. Ringkasan

- **Hasil:** semua alur utama berjalan. **2 temuan Sedang (T1, T2)** dan **2 pengamatan Rendah (T3, T4)**. Tidak ada temuan Kritis atau Tinggi.
- **Tidak ada** console error, request HTTP gagal, atau overflow horizontal pada 46 halaman × 2 ukuran layar.
- **Hak akses** benar pada 26 kasus.
- **12 dari 14 screenshot** tersimpan. Dua yang belum (11 dan 12) dijelaskan di bagian 5.

## 2. Hasil per alur

### Tamu
| Alur | Hasil |
| --- | --- |
| Login: sandi salah, form kosong, login benar | Lolos |
| Logout lalu tombol Back | **Gagal sebagian: T1** |
| Buka URL terproteksi setelah logout | Lolos (diarahkan ke login) |
| Daftar: email non-@itpln.ac.id, sandi tak cocok, sandi lemah | Lolos (ditolak). Data valid membuat akun dan diarahkan ke `/email/verify` |
| Lupa password: belum terdaftar ("belum terdaftar"), non-kampus, OTP salah (sisa percobaan tampil), OTP benar dari log, sandi baru | Lolos |

### Admin
| Alur | Hasil |
| --- | --- |
| Dashboard, Semester (Nomor SK tampil, form kosong ditolak), Kalender (dialog Tambah), Mata Kuliah (opsi Video; tombol Tambah nonaktif bila bobot ≠ 100%), Kelas (form kosong ditolak, dialog Ubah), Pengguna (cari, form kosong ditolak, dialog Import, template CSV 200), Ruang, Kontrak Kursi, Pengumuman (form kosong ditolak), Pengaturan Sistem, Audit Log | Lolos, tanpa console/HTTP error |
| Pengesah Rekap (nama, tanda tangan), template Excel unggahan (valid diterima, salah ditolak dengan pesan) | Lolos (UAT sebelumnya) |

### Asisten
| Alur | Hasil |
| --- | --- |
| Buat Post-Test `[DEMO]` (judul, mata kuliah, kelas, tanggal dan jam, instruksi) → Publikasikan | Lolos ("diterbitkan") |
| Laporan Mengawas Terlaksana: konfirmasi ringkasan, tersimpan Diajukan; tanggal yang tidak sesuai jadwal kelas ditolak server dengan peringatan | Lolos, kecuali **T2** |
| Verifikasi: pembuat tidak bisa memverifikasi laporannya sendiri; Sekretaris mengembalikan laporan Michael dengan catatan, Michael menerima notifikasi dan mengajukan ulang, Sekretaris memverifikasi (DB: `verified`) | Lolos |
| Penilaian Split-Screen, status Mengumpulkan/Hanya Melihat/Tidak Mengumpulkan, klik baris, daftar berkas | Lolos (diuji di sesi ini sebelumnya) |
| Rekap Jam Ngawas: Draft, Tetapkan Final, Buka Kembali, atur urutan, kelas di luar Lab IR, template, pengesah | Lolos; posisi tanda tangan diperbaiki di branch `fix/ngawas-posisi-ttd` |
| Generate: Word harian (1 Sep) valid dan memuat materi dan asisten; Excel rekap mengawas (3 sheet: Ringkasan, Per Mata Kuliah, Detail) dan ZIP (18 berkas .docx valid) dihasilkan lewat antrean | Lolos (lihat **T4**) |

### Praktikan (Rina)
| Alur | Hasil |
| --- | --- |
| Dashboard, Class, Task, Grade, Kontrak Kursi, Notifikasi, Profil | Lolos |
| Kumpulkan Laporan: 2 PDF → dialog "Kumpulkan sekarang?" → terkumpul (label Terlambat karena lewat tenggat) → **Batalkan Pengumpulan (Unsend)** → kembali belum terkumpul | Lolos |

## 3. Temuan

| ID | Tingkat | Temuan | Penyebab (hasil debug) | Pilihan perbaikan dan risiko |
| --- | --- | --- | --- | --- |
| **T1** | Sedang → **Bukan bug kode** | Setelah logout, tombol **Back** menampilkan halaman terproteksi (`/asisten/class`, termasuk data kelas). Klik menu baru mengarah ke login. Di komputer lab bersama, data terlihat oleh orang berikutnya. | Respons halaman terautentikasi memakai `Cache-Control: no-cache, private`, bukan `no-store`. Browser memulihkan halaman dari bfcache tanpa request ke server (terbukti: hanya `/live/version` yang diminta). | **A.** Tambah `Cache-Control: no-store` di middleware web untuk pengguna masuk. Risiko: Back sedikit lebih lambat (muat ulang). **B.** `Clear-Site-Data: "cache"` pada respons logout. Risiko: dukungan browser tidak merata, dan tidak mencakup halaman yang sudah di-cache sebelum logout. Saya sarankan A. |
| **T2** | Sedang | Dialog **Laporan Mengawas**: klik Simpan dengan form kosong, lalu pilih Mata Kuliah/Kelas (kolom "Kelas / Kegiatan" terisi otomatis) → tombol Simpan tidak berbuat apa-apa; hanya tooltip bawaan "Isian tidak valid." yang bisa muncul. Tidak ada request terkirim. | `resources/js/lib/validation-id.ts` memasang pesan kustom saat event `invalid` dan baru menghapusnya saat `input`/`change`. Pengisian otomatis oleh React tidak memicu event itu, sehingga pesan lama membuat kolom tetap tidak valid. | **A.** Hapus semua pesan kustom pada form saat tombol kirim diklik (fase capture), sebelum validasi browser. Risiko rendah. **B.** Picu event `input` pada kolom yang diisi otomatis. Risiko: harus diulang untuk tiap kolom otomatis. Saya sarankan A. |
| T3 | Rendah | Pada halaman tugas praktikan, klik **Kumpulkan** tanpa berkas langsung memunculkan dialog "Kumpulkan sekarang?"; validasi berkas tidak terlihat sebelum konfirmasi. | Dialog konfirmasi dibuka sebelum validasi. Perilaku setelah konfirmasi tanpa berkas belum saya uji. | Pertanyaan desain: validasi dulu lalu konfirmasi, atau biarkan. |
| T4 | Rendah | **Generate & Unduh** (Excel/ZIP) tidak langsung mengunduh: berkas dibuat lewat antrean dan tersedia lewat notifikasi/daftar ekspor. Pengguna yang menunggu unduhan langsung bisa mengira tombolnya rusak. | Rancangan: pekerjaan antrean (`exports`). | Pertanyaan desain: tambah toast "sedang diproses, cek notifikasi", atau biarkan. |

**Catatan lingkungan:** lencana "Edit with Herd" (hanya di dev) menutupi tombol di kanan bawah pada layar 1440×900 dan membuat klik otomatis gagal. Bukan masalah aplikasi.

## 4. Keputusan yang menunggu Anda

1. T1 dan T2: pilih perbaikan A, B, atau tunda.
2. T3 dan T4: ubah perilaku atau biarkan.
3. Apakah branch `fix/ngawas-posisi-ttd` (commit `f1a1fca`, belum di-push) langsung dibuatkan PR?

## 5. Screenshot

Tersimpan di `../../BERKAS-FINAL/screenshot-laporan/` (1440 px, data demo, tanpa console error): `01-login`, `02-dashboard-admin`, `03-dashboard-asisten`, `04-dashboard-praktikan`, `05-kelas-token`, `06-buat-tugas`, `07-penilaian-split-screen`, `08-laporan-ngawas`, `09-verifikasi-laporan`, `10-rekap-jam-ngawas`, `13-kontrak-kursi`, `14-audit-log`.

**Belum ada: `11-excel-rekap.png` dan `12-word-laporan-harian.png`.** Saya mencoba memotret jendela Excel dan Word, tetapi layar sedang dipakai aplikasi lain (satu hasil tangkapan ternyata layar game, satu lagi Excel dalam mode edit karena ada ketikan masuk). Kedua gambar itu saya hapus. Berkas sumbernya sudah disiapkan di `WEBSITE-IR/hasil-uat-ngawas/` (`Rekap Ngawas Sept (demo).xlsx` dan `Laporan Harian 2026-09-01 (demo).docx`); silakan dibuka dan di-screenshot sendiri.

## 6. Data uji yang dibuat (tidak dihapus)

- Tugas `[DEMO] Post-Test Uji Akhir` (Kelas A, buka 25 Nov).
- Laporan ngawas 23 Nov 2026: id 83 (Dimas, Diajukan) dan id 84 (Michael, Terverifikasi).
- Pengumpulan Rina pada Laporan Pertemuan 06 ML dibatalkan (Unsend); berkasnya menjadi draf.
- Akun uji `uji.demo@itpln.ac.id` (praktikan; sandi diganti lewat alur lupa password).
- Ekspor Excel/ZIP/Word beberapa entri, Draft Rekap Ngawas September, dan notifikasi terkait.

## 7. Yang tidak diuji

Email nyata, HTTPS, beban banyak pengguna, aksesibilitas, tampilan ponsel untuk alur yang menulis data, status UTS/UAS, Pengganti dan Tambahan pada sesi ini (teruji di audit 08), tukar shift, impor pengguna dengan berkas, dan gabung kelas dengan token valid (kelas Rina sudah lengkap).

## 8. Tindak lanjut T1 dan T2 (2026-11-25)

- **T1:** diagnosis awal (header `Cache-Control`/bfcache) keliru. Penyebab sebenarnya: Inertia memulihkan halaman dari riwayat browser, dan enkripsi riwayat (`INERTIA_ENCRYPT_HISTORY=true`, dibuang kuncinya oleh `LogoutResponse`, Audit 05 W1) tidak aktif di `.env` lokal. Diuji ulang dengan flag itu lewat HTTPS (`https://ir-lab.test`): setelah logout, Back menampilkan halaman login. Flag sudah ada di `.env.production.example`. Syaratnya, produksi wajib HTTPS (enkripsi riwayat butuh `crypto.subtle`; lewat HTTP biasa login macet). Tidak ada perubahan kode. Header `no-store` sempat dicoba, tidak diperlukan, dan dibatalkan.
- **T2:** diperbaiki di `resources/js/lib/validation-id.ts` (opsi A: pesan kustom dihapus saat tombol kirim diklik, fase capture). Diuji: form kosong diklik, kolom diisi tanpa event `input`, klik ulang → form terkirim. Seluruh tes (681) lulus.
