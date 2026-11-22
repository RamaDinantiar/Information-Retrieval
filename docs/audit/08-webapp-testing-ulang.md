# Audit 08 — Uji ulang alur utama di browser (3 peran, desktop dan ponsel)

**Tanggal:** 2026-11-22 · **Dasar:** `main` @ `22fd2f1` · **Penguji:** Claude Code (Playwright, Chromium)
**Sifat dokumen:** laporan. **Tidak ada kode aplikasi yang diubah.** Temuan dilaporkan dulu; perbaikan menunggu keputusan Anda.

> **Nama berkas.** Permintaan menyebut `04-webapp-testing.md`, tetapi nomor 04 sudah dipakai `04-tech-debt.md`, dan uji browser tahap sebelumnya ada di `05-webapp-testing.md`. Dokumen ini memakai nomor berikutnya (08). Dokumen 05 tidak diubah.

## 1. Ringkasan

- **Hasil:** semua alur yang diminta berjalan. **1 temuan baru bermakna (W10)**, 2 pengamatan kecil (W11, W12), dan 2 temuan lama terkonfirmasi lagi (W2, W6).
- **W10 (sedang):** di MySQL, banner **"Data berubah sejak Final"** muncul seketika setiap rekap ditetapkan Final, padahal data tidak berubah. Penyebabnya terbukti (bagian 3).
- **Kesalahan pengujian saya** yang perlu Anda ketahui (bagian 5), termasuk satu yang **sempat mengubah Nomor SK semester aktif**. Nilainya sudah saya pulihkan dan diverifikasi identik dengan cadangan.
- Data uji yang saya buat sudah dihapus, dan dibandingkan dengan snapshot sebelum pengujian (bagian 6).

**Lingkungan.** Herd melayani `http://ir-lab.test` dengan aset hasil build, MySQL lokal, `php artisan queue:work` dijalankan untuk ekspor dan notifikasi. **Menyimpang dari permintaan:** saya tidak memakai `php artisan serve` dan `npm run dev`. Herd sudah melayani aplikasi, dan `npm run dev` akan mengalihkan aset ke server Vite lewat `public/hot`, yang menguji build yang berbeda dari produksi.

## 2. Cakupan dan hasil

Desktop 1366 px, ponsel 390 px. "(D)" desktop, "(P)" ponsel. Bukti: [`08-screenshots/`](08-screenshots/).

### Praktikan (Rina, NIM 202331001)

| Alur                   | Yang diuji                                                                                                                                                                               | Hasil                                            |
| ---------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------ |
| Login                  | Password salah: tetap di `/login` dengan pesan galat. Login dengan NIM: masuk ke dashboard (D, P)                                                                                        | Lolos                                            |
| Jadwal                 | Halaman jadwal terbuka, memuat mata kuliah dan jam, tanpa overflow (D, P)                                                                                                                | Lolos                                            |
| Gabung kelas via token | Token salah → "Kode kelas tidak ditemukan". Token `fq5hle` (huruf kecil) diterima, Database System · Kelas A muncul di daftar kelas; tercatat di DB                                      | Lolos. Kelas terkunci: lihat catatan P5 di bawah |
| Kumpulkan tugas        | Tugas E2E-08 terbuka; PDF + teks terkumpul, berkas tampil; kuitansi `IRL-261122-000420` di DB                                                                                            | Lolos                                            |
| Lihat nilai            | Nilai draf tidak terlihat; setelah publikasi, ringkasan menunjukkan rata-rata Post-Test dan nilai akhir yang benar (lihat W11); notifikasi "Nilai dipublikasikan" dan "Nilai diperbarui" | Lolos                                            |
| Halaman lain di ponsel | Daftar kelas, tugas, nilai: tanpa overflow                                                                                                                                               | Lolos                                            |

**Catatan P5 (kelas terkunci).** Saya mencoba token kelas terkunci `BB8656` dengan Rina dan mendapat "Anda sudah tergabung di kelas ini". Itu jawaban yang benar, karena Rina memang anggota kelas 1. Semua kelas terkunci di data demo ada di mata kuliah yang sudah diambil Rina, jadi alur "kelas terkunci" tidak bisa diuji dengan akun ini. Alur itu sudah teruji di tahap 05 dan di `JoinClassTest` (pesan "Kelas sudah ditutup, hubungi asisten."). Saya tidak menghitungnya sebagai lolos maupun gagal di sini.

### Asisten (Dimas Asisten dan Michael Christia)

| Alur                       | Yang diuji                                                                                                                                                                                                      | Hasil                                                             |
| -------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------- |
| Buat tugas                 | Form kosong ditolak; Post-Test untuk Kelas A (21 praktikan) terbit; 21 notifikasi terkirim                                                                                                                      | Lolos                                                             |
| Nilai tugas                | Tinjauan menampilkan berkas Rina; skor `101`, `-5`, `abc` tidak tersimpan (DB: hanya dua `grade.saved`, yakni 85.5 dan 92); draf 85.5 tersimpan (toast, Grade A); publikasi; ubah nilai terpublikasi menjadi 92 | Lolos, publikasi **tanpa dialog konfirmasi** (W6, lihat bagian 4) |
| N12                        | Nilai 92 langsung terlihat praktikan, notifikasi "Nilai diperbarui", entri audit `grade.updated_after_publish`, versi nilai naik ke 2                                                                           | Lolos                                                             |
| Laporan **Ditiadakan**     | Status Ditiadakan, tanpa foto: tersimpan sebagai `cancelled`, badge "Ditiadakan"                                                                                                                                | Lolos                                                             |
| Laporan **Pengganti**      | Tanggal asli 17 Nov, pelaksanaan 18 Nov, keterangan: tersimpan; daftar menulis "Pengganti dari Selasa, 17 November 2026"                                                                                        | Lolos                                                             |
| Laporan **di luar Lab IR** | Kelas Data Warehouse (KR) milik Lab Sistem Informasi, 18.30–20.10: tersimpan dengan `outside_class_id`, badge "Di luar Lab IR"                                                                                  | Lolos                                                             |
| Tanda tangan               | Simpan kosong tidak dikirim; tanda tangan hasil coretan tersimpan (`signature`, `scan_status=clean`); profil di ponsel tanpa overflow. **Tab "Unggah gambar" tidak diuji**                                      | Lolos (sebagian)                                                  |
| Halaman di ponsel          | Daftar tugas, daftar nilai, mode tinjauan, tab verifikasi, dialog laporan status Pengganti: tanpa overflow, dialog muat di layar. Mode tinjauan masih menyisakan kolom kosong di kanan (**W2, terulang**)       | Lolos, kecuali W2                                                 |

### Sekretaris/Bendahara (Dimas Asisten)

| Alur                  | Yang diuji                                                                                                                                                                                                                | Hasil             |
| --------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------- |
| Verifikasi laporan    | Tab "Perlu Verifikasi" memuat 3 laporan; ketiganya `verified` oleh Dimas (DB). Skrip saya sempat menghitung 2 karena salah menangkap satu respons, tetapi DB memastikan ketiganya                                         | Lolos             |
| Rekap Jam Ngawas      | Total jam 4 (= 2,5 untuk IR 3 SKS + 1,67 untuk kelas luar 2 SKS + 0 untuk yang ditiadakan, 4,17 dibulatkan per asisten); tanda tangan terunggah 1/4; kelas luar Lab IR dan kelas pengganti tampil di bagian masing-masing | Lolos             |
| Unduh Excel **Draft** | Lewat antrean (selesai dalam hitungan detik). Berkas berisi Michael dan Data Warehouse, penanda DRAFT ada di `sheet1.xml` dan `sheet2.xml`                                                                                | Lolos             |
| **Tetapkan Final**    | Toast "Rekap 2026-11 ditetapkan Final", badge "Final rev. 0", tombol Unduh Final dan Buka Kembali; 0,7 detik. **Banner "Data berubah sejak Final" langsung muncul (W10)**                                                 | Lolos, dengan W10 |
| Unduh Excel **Final** | Berisi Michael dan Data Warehouse, **tanpa** penanda DRAFT, 3 gambar; berkas dibuka sebagai ZIP dan diperiksa XML-nya, **tidak dibuka di Excel**                                                                          | Lolos             |
| **Buka Kembali**      | Dengan catatan revisi; rekap kembali Draft dan tombol Tetapkan Final muncul                                                                                                                                               | Lolos             |
| Ponsel                | Halaman rekap tanpa overflow                                                                                                                                                                                              | Lolos             |

### Admin

| Alur                    | Yang diuji                                                                                                                                                                                                                        | Hasil                                                                   |
| ----------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------- |
| Semester + **Nomor SK** | Form kosong ditolak; semester "E2E-08 Uji 2027" dibuat dengan Nomor SK dan tanggal dari pemilih tanggal; SK tampil di daftar. **Ubah SK:** berjalan, tetapi saya mengujinya di baris yang salah (bagian 5) dan tidak mengulangnya | Lolos (buat); ubah SK terbukti berfungsi tetapi bukan pada semester uji |
| Kalender akademik       | Tambah Libur Nasional 18–19 Jan 2027, ubah keterangan, hapus. Pemilih tanggal membatasi ke rentang semester: semua 31 tanggal Des 2026 nonaktif dan bulan sebelumnya tidak bisa dibuka                                            | Lolos                                                                   |
| Pengesah                | Nama kosong tidak dikirim; pengesah 1 disimpan dan terbaca setelah muat ulang. **Unggah gambar tanda tangan pengesah tidak diuji**                                                                                                | Lolos (sebagian)                                                        |
| Pengguna                | Daftar (25 per halaman, 67 total), pencarian "Michael" dan "Rina", form tambah kosong ditolak dan tidak ada akun baru (DB). **Pembuatan akun valid tidak diulang di sini** (teruji di 05)                                         | Lolos                                                                   |
| Ponsel                  | Semester, kalender, pengaturan, pengguna: tanpa overflow                                                                                                                                                                          | Lolos                                                                   |

## 3. Temuan baru

| ID      | Temuan                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                     | Dampak                                                                                                                                                  | Usulan                                                                                                                                                     |
| ------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **W10** | **Banner "Data berubah sejak Final" selalu muncul di MySQL.** `NgawasRecapController::finalInfo` (baris 150) membandingkan `md5(json_encode($live))` dengan `md5(json_encode($snapshot['recap']))`. Kolom `snapshot_json` bertipe `json` di MySQL, yang **menyusun ulang kunci objek** (kunci pendek lebih dulu). Urutan kunci snapshot menjadi `month,totals,columns,reports,semester,…`, sedangkan data hidup `month,monthLabel,daysInMonth,semester,columns,…`. Isi identik: setelah kunci diurutkan, kedua sisi sama (`true`). Test memakai SQLite yang menyimpan teks apa adanya, jadi tidak terlihat | Sedang. Peringatan palsu setiap kali rekap Final, sehingga peringatan yang benar (data memang berubah) tidak bisa dibedakan. Berkas Final sendiri benar | Bandingkan setelah mengurutkan kunci secara rekursif (atau simpan hash saat Final). Tambah test dengan snapshot berkunci acak agar SQLite pun menangkapnya |
| W11     | Tugas yang tidak dikaitkan ke pertemuan ("Tidak terkait") **ikut dihitung** di rata-rata Post-Test dan nilai akhir praktikan, tetapi tidak muncul di tabel per pertemuan. Rata-rata Post-Test Rina menjadi 82,5 dari (73 + 92) / 2 tanpa praktikan bisa melihat baris 92                                                                                                                                                                                                                                                                                                                                   | Rendah. Praktikan melihat angka rata-rata yang tidak bisa ditelusuri ke satu baris                                                                      | Pertanyaan desain: tampilkan tugas tak terkait di tabel, atau wajibkan pertemuan                                                                           |
| W12     | Periode semester yang **tumpang tindih diterima**. Semester uji 4 Jan–28 Mei 2027 dibuat tanpa galat padahal "Genap 2026/2027" berjalan 15 Feb–18 Jun 2027. Tidak ada validasi tumpang tindih di `SemesterController`                                                                                                                                                                                                                                                                                                                                                                                      | Rendah selama semester berstatus Draft. Risiko bila dua semester tumpang tindih diaktifkan atau dicari berdasarkan tanggal (`semesterFor(bulan)`)       | Putuskan kebijakan: tolak tumpang tindih, atau biarkan dengan peringatan                                                                                   |

## 4. Temuan lama yang terkonfirmasi lagi

- **W6** (05): tombol "Publikasikan (1)" menerbitkan nilai langsung tanpa dialog konfirmasi. Terlihat dari DB: nilai `published` empat detik setelah disimpan.
- **W2** (05): mode tinjauan nilai di ponsel masih menyisakan kolom kosong di kanan.

## 5. Koreksi atas pengujian saya sendiri

Beberapa hasil awal salah karena skrip saya, bukan aplikasi. Semuanya sudah dicek ulang lewat database atau tangkapan layar:

- **A3, merusak data lalu dipulihkan (paling penting).** XPath yang saya pakai untuk memilih tombol "Ubah" pada semester uji ternyata memilih tombol pertama di halaman, milik **semester aktif Ganjil 2026/2027**. Nomor SK-nya berubah dari `01/SK/Ganjil/IRLab/2026` menjadi `99/SK/E2E/IRLab/2027-REV`, dan skrip melaporkan lolos karena teks `2027-REV` memang tampil di halaman. Saya baru melihatnya saat membandingkan baris database dengan cadangan. Nilai asli sudah saya kembalikan lewat database dan diverifikasi identik dengan cadangan. **Audit Log mencatat perubahan itu (`semester.updated`) tetapi tidak mencatat pemulihannya**, karena pemulihan lewat database. Nomor SK yang salah bertahan dari sekitar 14:22 sampai saya memulihkannya beberapa menit kemudian; tidak ada Rekap Final yang dibuat dalam rentang itu.
- **P5**: kesalahan data uji, bukan aplikasi (bagian 2).
- **G1**: jawaban teks berada di panel `<details>` tertutup, jadi teksnya tidak terbaca. **G2/G3**: status 303 adalah redirect sukses, tetapi asersi saya menganggapnya gagal. Asersi pesan galat G2 sempat cocok dengan label statis "Skor 0–100"; buktinya saya ambil dari DB (hanya dua `grade.saved`).
- **G7/G9**: halaman nilai praktikan hanya menampilkan ringkasan per mata kuliah, jadi saya mencari angka di tempat yang salah. Rata-rata 82,5 dan nilai akhir 76,6 / B sudah benar.
- **K2/K3**: berkas Excel Draft berpenanda DRAFT di XML lembar, bukan di `sharedStrings`; label status Final bukan "Status rekap: Final". Keduanya diperiksa ulang dari berkas dan tangkapan layar.
- **A5**: asersi menghitung tombol nonaktif sebagai tersedia. Ke-31 tanggal Des 2026 ternyata nonaktif.
- **U1**: Rina ada di halaman lain karena daftar 25 per halaman.
- Satu perintah `tinker` sempat menggantung karena ada `cat` nyasar yang menunggu input. Sudah dihentikan.

## 6. Data uji dan pembersihan

Sebelum pengujian saya mengambil snapshot jumlah baris dan id maksimum semua tabel, serta cadangan baris `semesters`, `settings`, `academic_calendar_events`, dan `users`. Setelah pengujian saya hapus semua yang saya buat: 1 tugas (id 26) beserta target, pengumpulan, dan nilainya; 3 laporan ngawas (id 80–82); 1 pendaftaran kelas (Rina ke kelas 5); rekap November 2026 beserta revisi dan 2 ekspor; semester uji (id 5); pengaturan `ngawas_recap.approvers`; 42 notifikasi; dan 8 berkas (termasuk berkas fisik). Tanda tangan Michael juga dilepas.

Verifikasi: snapshot sesudah pembersihan sama dengan sebelumnya pada semua tabel **kecuali `audit_logs`**, yang sengaja dipertahankan (22 entri baru). Baris `semesters`, `settings`, `academic_calendar_events`, dan `users` identik dengan cadangan. Tidak ada berkas fisik baru di `storage/app/private/lab`.

## 7. Yang tidak diuji

- Email nyata (SMTP), HTTPS, `TRUSTED_PROXIES`, ClamAV, beban banyak pengguna.
- Membuka Excel di aplikasinya (hanya ZIP dan XML); peramban selain Chromium; aksesibilitas.
- Tab "Unggah gambar" tanda tangan asisten, unggah tanda tangan pengesah, dan unggah template Excel.
- Pembuatan akun pengguna yang valid, impor pengguna, dan alur tukar shift (hanya di 05 atau tidak sama sekali).
- Alur "kelas terkunci" untuk praktikan (bagian 2, catatan P5).

## 8. Cara mengulang

Skrip ada di folder scratchpad sesi dan tidak masuk repo. Kerangkanya: Playwright (Python) dengan Chromium, login cepat lewat `/dev/login-as/{peran}`, `php artisan queue:work` untuk ekspor, dan snapshot database sebelum serta sesudah. **Jangan memilih baris dengan XPath `ancestor::*[…][1]` di dalam tanda kurung** (A3); gunakan elemen baris yang spesifik dan periksa DB setelah setiap langkah yang menulis data.
