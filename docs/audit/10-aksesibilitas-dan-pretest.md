# Audit 10 — Aksesibilitas otomatis dan uji manual Pre-Test

**Tanggal:** 2026-11-25 · **Penguji:** Claude Code · **Sifat dokumen:** laporan; tidak ada kode aplikasi yang diubah.
**Alat:** Playwright (Chromium) + axe-core 4.10.2 (tag WCAG 2.0/2.1 A dan AA), desktop 1440 px, data seeder demo.

## 1. Aksesibilitas

### Cakupan dan batas

49 halaman diuji (3 halaman tamu, 13 Admin, 21 Asisten, 12 Praktikan). **Koreksi:** pengukuran pertama menangkap 7 halaman dengan 240 elemen "serious", tetapi sebagian besar ternyata artefak animasi fade-in (warna terukur saat elemen belum penuh opak). Diukur ulang dengan animasi dimatikan dan jeda 2,5 detik, pelanggaran nyata hanya **tiga elemen di dua halaman** (A1 di bawah); selebihnya lolos. Uji otomatis hanya menangkap sebagian masalah aksesibilitas. **Belum diuji:** pembaca layar (VoiceOver/NVDA), zoom 200%, tampilan ponsel, isi dialog, dan seluruh kondisi galat. Hasil ini bukan jaminan kepatuhan WCAG 2.1 AA.

### Temuan

| ID  | Tingkat | Halaman dan peran                                                                                                                               | Temuan                                                          | Yang diharapkan vs yang terjadi                                                                           | Pilihan perbaikan dan risiko                                                                                                                                                                                                                                             |
| --- | ------- | ----------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| A1  | Sedang  | `/asisten/class` (kode kelas dan teks "Sisa n Kursi", 4,23:1), `/asisten/ngawas-recap` (hari di kepala kolom, 9 px dengan `opacity-80`, 3,85:1) | Kontras warna kurang dari 4,5:1 (WCAG 1.4.3) pada tiga komponen | Teks normal minimal 4,5:1                                                                                 | **A.** Gelapkan hanya komponen terkait (`primary-600` untuk kode kelas, `primary-700` untuk teks kursi, hapus `opacity-80`). Risiko rendah. **B.** Gelapkan token `primary-500` global. Risiko: mengubah banyak tempat dan menyimpang dari Figma. Disarankan A (PR #28). |
| A2  | Rendah  | Semua halaman setelah login                                                                                                                     | Tidak ada tautan "Lompat ke konten" (WCAG 2.4.1, level A)       | Pengguna keyboard seharusnya bisa melewati sidebar. Saat ini harus Tab melewati seluruh menu tiap halaman | Tambah tautan tersembunyi yang tampil saat fokus di awal layout, mengarah ke `<main>`. Risiko sangat rendah.                                                                                                                                                             |

### Yang sudah baik

- `<html lang="id">` dan satu `<main>` ada.
- Dialog "Buat Tugas": fokus masuk ke dalam dialog, `Esc` menutup, dan fokus kembali ke tombol pemicu.
- Indikator fokus keyboard terlihat pada kolom sandi dan tombol di halaman login (lewat bayangan, bukan outline).
- Label form, nama tombol, urutan judul, dan teks alternatif lolos pada seluruh 49 halaman.

### Belum diselidiki

Dua tab stop terakhir di halaman login adalah elemen `DIV` tanpa outline. Belum jelas apakah itu alat dev Herd (hanya lokal) atau elemen aplikasi. Perlu dicek ulang di server tanpa Herd.

## 2. Uji manual Pre-Test

Alur dijalankan lewat UI tanpa mengubah data seeder:

| Langkah                                                                                                                                                                       | Peran            | Hasil                                                                                                                                                                              |
| ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Buat Pre-Test "[DEMO] Pre-Test Uji Akhir": mata kuliah Machine Learning, Kelas A, Pertemuan 7, mode Terjadwal, buka 25 Nov 08.00, batas 30 Nov 08.00, instruksi, Publikasikan | Asisten          | Lolos ("diterbitkan"; muncul di daftar)                                                                                                                                            |
| Pre-Test terlihat di daftar Pre-Test; status "Sedang Berlangsung", batas waktu benar                                                                                          | Praktikan (Rina) | Lolos                                                                                                                                                                              |
| Unggah satu PDF dan Kumpulkan                                                                                                                                                 | Praktikan        | Lolos (status "Terkumpul")                                                                                                                                                         |
| Muncul di tabel nilai dengan berkas dan waktu pengumpulan                                                                                                                     | Asisten          | Lolos                                                                                                                                                                              |
| Isi nilai 85 dan feedback, Simpan Seluruh Nilai, lalu Publikasikan (1)                                                                                                        | Asisten          | Lolos; status "Dipublikasikan oleh Dimas Asisten"                                                                                                                                  |
| Nilai tampil di halaman Grade                                                                                                                                                 | Praktikan        | Lolos (85 terlihat)                                                                                                                                                                |
| Export Pre/Post-Test                                                                                                                                                          | Asisten          | Lolos: berkas `IF-3101 Machine Learning - Kelas A - Rekap Nilai Pre-Post Test.xlsx`, dua sheet (Pre-Test, Post-Test), kolom "Pre-Test: [DEMO] Uji Akhir", nilai 85 pada baris Rina |

### Pengamatan

- **P1 (Rendah, sudah tercatat sebagai W6 di audit 05):** Publikasikan nilai tetap tanpa dialog konfirmasi, padahal langsung mengirim notifikasi ke praktikan.
- **P2 (Sedang, perlu keputusan Anda):** rata-rata Pre-Test dan Post-Test di Excel dihitung `=SUM(kolom)/jumlah kolom`, jadi tes yang belum dinilai ikut dihitung 0. Pada uji ini Rina hanya punya nilai 85 di satu dari dua Pre-Test, sehingga rata-ratanya menjadi 42,5. Aturan ini mengikuti keputusan sebelumnya ("sama seperti laporan"), tetapi untuk tes yang memang belum diadakan hasilnya terkesan rendah. Pilihan: **A** biarkan (konsisten dengan laporan, rumus Excel sederhana); **B** rata-rata hanya dari kolom yang terisi (`AVERAGE`), dengan risiko mahasiswa yang melewatkan satu tes tidak terkena nilai 0.
- Kolom Pre-Test menghapus kata "Pre-Test" dari judul (menjadi "[DEMO] Uji Akhir") sesuai aturan nama kolom; tidak ada masalah.

### Data uji yang dibuat (tidak dihapus)

- Tugas `[DEMO] Pre-Test Uji Akhir` (id 48, Kelas A, Pertemuan 7), pengumpulan Rina, dan nilai 85 yang sudah dipublikasikan.
- Berkas ekspor di scratchpad penguji (tidak di repositori).

## 3. Keputusan

1. A1 dan A2: diperbaiki di PR #28 (disetujui). Tautan "Lompat ke konten" dinilai layak karena murah dan memenuhi WCAG 2.4.1 level A.
2. P2: dipilih B (rata-rata hanya dari kolom terisi), diperbaiki di PR #27.
