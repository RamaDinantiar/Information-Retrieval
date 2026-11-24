# PRD — Fitur Rekap Jam Ngawas (Format Kampus)

**Produk:** IR Lab Management System · **Modul:** Asisten › Generate (perluasan FR-AGN) dan Asisten › Report (perluasan FR-AR)
**Versi:** v1.1 (siap implementasi; semua keputusan terkunci) · **Tanggal:** 20 November 2026 · **Penyusun:** Rama Dinantiar (PM/Dev)
**Acuan:** `docs/02-prd.md` v2.0 (PRD utama, tidak diubah oleh dokumen ini), file contoh `Rekap_Ngawas_LAB_IR_APRIL_2026.xlsx` & `Rekap_Ngawas_LAB_IR_MEI_2026.xlsx`, dan penjelasan Bendahara lab (3 voice note, 13 Nov 2026).

**Penanda:** **[Wajib]** = harus ada di rilis pertama fitur ini · **[Sebaiknya]** = boleh menyusul. Keputusan klarifikasi tercatat di Bagian 12.

| Versi | Tanggal     | Keterangan                                                                                                                                                                                                                                                                                  |
| ----- | ----------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| v1.0  | 20 Nov 2026 | Draft awal dengan 8 pertanyaan terbuka                                                                                                                                                                                                                                                      |
| v1.1  | 20 Nov 2026 | Keputusan P1–P8 dimasukkan: pembulatan ikut template, warna oranye untuk kelas lab lain dan kelas IR di luar ruang lab, dua tanda tangan pengesah bernama, tanda tangan asisten otomatis tanpa konfirmasi, NIM format en dash, template dari file Mei 2026, semua asisten aktif ditampilkan |

---

## 0. Ringkasan

Setiap bulan Sekretaris/Bendahara lab menyusun **Rekap Ngawas** dalam Excel dengan template dari atasan kampus. Isinya dua sheet: **"Format Aslab"** (jam ngawas per asisten per kelas per tanggal) dan **"Absensi Kehadiran Aslab (TTD)"** (tanda tangan asisten per tanggal hadir). Rekap ini diperiksa **Kak Naomi** (pemeriksa dari kampus), lalu disahkan **Bu Dine (Kepala Lab)** dan **Dekan**.

Sekarang rekap dibuat manual: rumus SKS diketik per sel, sel diwarnai satu per satu, catatan kelas pengganti ditulis tangan, dan gambar tanda tangan ditempel satu per satu. Akibatnya sering ada revisi.

Fitur ini membuat sistem **menghasilkan file Excel itu secara otomatis** dari laporan ngawas harian yang sudah diverifikasi di website. Hasilnya sama persis dengan template kampus: kop, rumus, warna, catatan, dan tanda tangan.

**Satu sumber data:** laporan harian (yang juga menjadi dokumen Word) adalah sumber rekap Excel. Keduanya tidak mungkin lagi saling bertentangan.

### Dalam cakupan

- Status pelaksanaan kelas pada laporan ngawas: Terlaksana, Ditiadakan, Pengganti, Tambahan, Ujian (UTS/UAS).
- Kalender akademik: libur nasional dan periode UTS/UAS. Hari Minggu dideteksi otomatis.
- Kelas di luar Lab IR yang tetap diawas asisten lab IR.
- Perhitungan jam berbasis SKS mata kuliah.
- Halaman pratinjau grid bulanan, pemeriksaan otomatis sebelum final, dan alur Draft → Final → Revisi.
- Tanda tangan digital asisten yang terpasang otomatis (sheet 2) dan dua tanda tangan pengesah bernama (sheet 1).
- Ekspor `.xlsx` dari template kampus. PDF bersifat **[Sebaiknya]**.

### Di luar cakupan

- Akun untuk Kak Naomi, Bu Dine, atau Dekan. Mereka tidak masuk sistem; revisi dari Kak Naomi tetap datang lewat grup.
- Perhitungan honor atau tarif. Ini sesuai revisi Kepala Lab 12 Nov 2026 yang menghapus honor dari sistem.
- Mengubah tata letak template kampus, yang **tidak boleh diubah sedikit pun** (lihat Bagian 7).
- Konfirmasi otomatis ke lab lain untuk kelas di luar Lab IR. Itu tetap dilakukan Bendahara di luar sistem.

---

## 1. Latar Belakang & Masalah

Bagian ini dirangkum dari penjelasan Bendahara lab (voice note 13 Nov 2026) dan pemeriksaan file contoh.

| #   | Masalah saat ini                                                                                                      | Bukti                                                                                                                              | Dampak                                                                                                                                  |
| --- | --------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------- |
| M1  | SKS salah: rumus 3 SKS dipakai untuk mata kuliah 2 SKS                                                                | Voice note 2 ("Data Warehouse harusnya 2 SKS…"); file April sel `F33` Hilda memakai `2*50/60` di baris yang lain memakai `3*50/60` | Ditandai "SKS tidak sesuai", revisi                                                                                                     |
| M2  | Tanggal salah: kehadiran Senin tertaruh di Selasa                                                                     | Voice note 2                                                                                                                       | Revisi                                                                                                                                  |
| M3  | Excel tidak sesuai dengan laporan harian Word, misalnya Word menulis "kelas ditiadakan" tetapi Excel masih berisi jam | Voice note 3                                                                                                                       | Revisi "kelas tidak sesuai"                                                                                                             |
| M4  | Pewarnaan sel manual dan sulit di ratusan sel                                                                         | Voice note 1 & 2                                                                                                                   | Warna salah atau terlewat                                                                                                               |
| M5  | Gambar tanda tangan asisten ditempel satu per satu di sheet 2; total kehadiran sheet 2 diketik manual                 | Voice note 1                                                                                                                       | Pekerjaan paling lama                                                                                                                   |
| M6  | Rentang rumus total jam tidak konsisten (mulai kolom `F`, bukan `E`, sehingga tanggal 1 tidak terhitung)              | April & Mei: rumus total asisten no. 4–9 dimulai kolom `F`                                                                         | **April 2026: 9,17 jam tidak terhitung** (Zahra 1,67; Michael 2,5; Bintang 3,33; Rafi 1,67); total Excel 484 jam, seharusnya 494,17 jam |
| M7  | Catatan kelas pengganti dan tambahan ditulis manual di bawah tabel                                                    | Kedua file                                                                                                                         | Tidak konsisten dan terlewat                                                                                                            |
| M8  | Nomor urut ganda dan baris kelas kosong                                                                               | April: nomor 8 dua kali; "DATA WAREHOUSE (KR)" kosong                                                                              | Kesan tidak rapi                                                                                                                        |

### Tujuan

1. **Nol kesalahan SKS, tanggal, dan rumus.** Semua dihitung dari data master dan laporan terverifikasi.
2. **Excel konsisten dengan laporan harian.** Kelas yang ditiadakan tidak pernah menghasilkan jam.
3. **Waktu penyusunan rekap bulanan turun** dari beberapa jam menjadi kurang dari 10 menit, termasuk pemeriksaan.
4. **Tanda tangan sheet 2 terpasang otomatis** sesuai nama dan tanggal hadir.

### KPI (diukur 2 bulan setelah rilis)

| KPI                                                                                | Target                                |
| ---------------------------------------------------------------------------------- | ------------------------------------- |
| Revisi dari Kak Naomi terkait SKS, tanggal, warna, atau ketidaksesuaian Word–Excel | 0 per bulan                           |
| Waktu dari "laporan terakhir diverifikasi" sampai "rekap Final diunduh"            | ≤ 10 menit kerja Sekretaris/Bendahara |
| Rekap yang dibuat lewat sistem (bukan manual)                                      | 100%                                  |

---

## 2. Pengguna & Hak Akses

| Pihak                                | Di sistem?                                   | Peran dalam fitur ini                                                                                                                                                                                                         |
| ------------------------------------ | -------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Asisten Umum**                     | Ya (peran Asisten)                           | Mengisi laporan ngawas beserta status pelaksanaan; mengunggah tanda tangan sekali; melihat pratinjau rekap **miliknya**                                                                                                       |
| **Sekretaris/Bendahara**             | Ya (Asisten, jabatan `sekretaris_bendahara`) | Semua hak Asisten, ditambah: memverifikasi laporan (sudah ada); mengelola kelas di luar Lab IR; melihat rekap **semua asisten**; menjalankan pemeriksaan; menetapkan Final; membuka kembali (revisi); mengekspor Excel kampus |
| **Admin**                            | Ya                                           | Semua hak di atas, ditambah: kalender akademik, nomor SK per semester, data dan tanda tangan pengesah, mengganti file template                                                                                                |
| **Kak Naomi** (pemeriksa kampus)     | Tidak                                        | Menerima file Excel dan memberi revisi lewat grup. Revisi ditindaklanjuti Sekretaris/Bendahara lewat **Buka Kembali** (FR-RJN-09)                                                                                             |
| **Bu Dine** (Kepala Lab) & **Dekan** | Tidak                                        | Tanda tangan masing-masing ditempel sebagai **dua gambar terpisah** beserta nama di sheet 1 saat ekspor Final                                                                                                                 |

| Resource                                 | Asisten Umum        | Sekretaris/Bendahara        | Admin                               |
| ---------------------------------------- | ------------------- | --------------------------- | ----------------------------------- |
| Status pelaksanaan pada laporan          | CRU (Own)           | CRU (Own), verifikasi semua | R, verifikasi, buka kunci           |
| Tanda tangan diri sendiri                | CRUD (Own)          | CRUD (Own)                  | R semua (tanpa unduh massal), hapus |
| Kalender akademik                        | R                   | R                           | CRUD                                |
| Kelas di luar Lab IR                     | R (yang ditugaskan) | CRUD                        | CRUD                                |
| Pratinjau rekap bulanan                  | R (baris sendiri)   | R semua                     | R semua                             |
| Final, Buka Kembali, Ekspor Excel kampus | —                   | Ya                          | Ya                                  |
| Nomor SK, pengesah, template             | R                   | R                           | CRUD                                |

---

## 3. Istilah & Aturan Bisnis

### 3.1 Istilah

- **Rekap Ngawas:** dokumen Excel bulanan format kampus, berisi 2 sheet.
- **Baris rekap:** satu kombinasi _asisten × kelas_ (misalnya "RAMA DINANTIAR × SIMULASI DAN PEMODELAN (A)").
- **Sel kehadiran:** perpotongan baris rekap dengan satu tanggal.
- **KR:** kode kelas **Karyawan**, misalnya "DATA WAREHOUSE (KR)". Ini hanya nama kelas dan tidak ada aturan khusus.
- **Kelas di luar Lab IR:** kelas yang diawas asisten IR tetapi bukan kelas Lab IR (mata kuliah lab lain), **atau** kelas Lab IR yang sementara dilaksanakan di luar ruang lab. Lihat 3.6.

### 3.2 Rumus jam (keputusan 20 Nov 2026)

- **Jam per sesi = SKS mata kuliah × 50 menit ÷ 60.** Nilainya 1,6667 jam untuk 2 SKS dan 2,5 jam untuk 3 SKS. Jam mulai/selesai di laporan **tidak** dipakai untuk rekap ini; jam itu tetap dipakai untuk dokumen Word.
- SKS diambil dari `courses.credits`. Untuk kelas di luar Lab IR, SKS diambil dari data kelas tersebut. SKS **tidak pernah diketik per sel**.
- Di Excel, sel kehadiran tetap ditulis sebagai rumus `=<SKS>*50/60`, persis seperti sekarang, supaya pemeriksa melihat format yang sama.
- **Total hari** per baris memakai rumus `=COUNT(<kolom tgl 1>:<kolom tgl terakhir>)`.
- **Total jam** per asisten memakai rumus `=ROUND(SUM(<kolom tgl 1><baris pertama>:<kolom tgl terakhir><baris terakhir>),0)`. Rentangnya **selalu** dimulai dari kolom tanggal 1, sehingga M6 tidak terulang.
- **Baris total** memakai `=SUM(...)` untuk hari dan `=ROUND(SUM(...),0)` untuk jam.
- **Pembulatan (keputusan P1): ikuti template.** Total jam per asisten dibulatkan ke bilangan bulat terdekat lewat `ROUND(…,0)` (setengah ke atas). Pratinjau web menampilkan angka yang sama dengan Excel, dengan jam sebenarnya (2 desimal) di tooltip. Konsekuensinya, jumlah total semua asisten bisa berbeda ±1 jam dari jumlah sebenarnya (contoh Mei 2026: 251 vs 250,00). Itu sesuai template.

### 3.3 Laporan yang dihitung

- Hanya laporan berstatus **Diverifikasi** (FR-AR-05) yang masuk rekap.
- Setiap anggota tim jaga pada laporan mendapat sel kehadiran dan jamnya sendiri. Satu kelas di hari yang sama boleh diawas lebih dari satu asisten (keputusan 20 Nov 2026).
- Satu asisten hanya dihitung **sekali** per kelas per tanggal. Duplikat ditandai pemeriksaan (FR-RJN-07).

### 3.4 Status pelaksanaan dan warna sel

Warna diambil dari **legenda template** (sheet 1, sel `A80:A85`), bukan warna buatan sistem.

| Status pelaksanaan (baru)      | Kapan dipakai                                                               | Jam dihitung?                                  | Warna sel (sesuai legenda)                                                     |
| ------------------------------ | --------------------------------------------------------------------------- | ---------------------------------------------- | ------------------------------------------------------------------------------ |
| **Terlaksana**                 | Kelas berjalan sesuai jadwal                                                | Ya                                             | Tanpa warna khusus                                                             |
| **Ditiadakan**                 | Kelas batal (dosen berhalangan, dll.); laporan tetap dibuat seperti di Word | **Tidak** (sel kosong, tidak dihitung `COUNT`) | "Kelas ditiadakan" (`A84`)                                                     |
| **Pengganti**                  | Kelas dipindah ke tanggal/jam lain; dicatat di **tanggal pelaksanaan**      | Ya                                             | "SKS Pengganti dan SKS Ngawas" (`A83`)                                         |
| **Tambahan**                   | Kelas ekstra di minggu yang sama (jarang terjadi)                           | Ya                                             | "Tambahan" (`A82`)                                                             |
| **Ujian (UTS/UAS)**            | UTS/UAS yang dilaksanakan **di lab**                                        | Ya (rumus SKS yang sama)                       | "UTS/UAS" (`A85`)                                                              |
| _(penanda)_ **Di luar Lab IR** | Kelas lab lain, atau kelas Lab IR yang dilaksanakan di luar ruang lab (3.6) | Ya                                             | **Oranye** "Tambahan" (`A82`), sesuai cara Bendahara selama ini (keputusan P2) |

| Penanda kalender | Sumber                    | Warna                                  |
| ---------------- | ------------------------- | -------------------------------------- |
| Hari Minggu      | Otomatis dari tanggal     | "Minggu" (`A80`) seluruh kolom         |
| Libur nasional   | Kalender akademik (Admin) | "Libur Nasional" (`A81`) seluruh kolom |
| Periode UTS/UAS  | Kalender akademik (Admin) | "UTS/UAS" (`A85`) seluruh kolom        |

Aturan tambahan:

- Kelas **Pengganti** di bulan berbeda dari kelas aslinya masuk ke **bulan pelaksanaan** (keputusan 20 Nov 2026). Contohnya pengganti tanggal 30 April untuk kelas 27 April masuk rekap April.
- Laporan berstatus Terlaksana di hari Minggu atau libur nasional **diblokir** saat disimpan. Pengguna diminta memilih status Pengganti/Tambahan.
- Laporan Terlaksana yang tanggalnya tidak cocok dengan hari jadwal kelas (`classes.day_of_week`) **diblokir**, dengan pesan: "Kelas ini terjadwal hari Senin. Jika ini kelas pengganti atau tambahan, ubah status pelaksanaan." Ini mencegah M2.

### 3.5 Tabel catatan di bawah sheet 1

- **Kelas Tambahan** (kolom Tanggal | Kelas | Jam | Keterangan) diisi otomatis dari laporan berstatus Tambahan.
- **Kelas Pengganti** (kolom Tanggal | Kelas | Jam | Keterangan) diisi otomatis dari laporan berstatus Pengganti.
    - Keterangan otomatis: `Pengganti dari Kelas <Hari>, <tgl> <Bulan> <Tahun>`, misalnya "Pengganti dari Kelas Senin, 27 April 2026".
    - Keterangan dapat diubah atau ditambah oleh Sekretaris/Bendahara sebelum Final, misalnya "…, Menjadi dimulai jam 15.30".
- Format **Jam** mengikuti contoh: `07.10 - 09.50`, diambil dari jam mulai–selesai laporan.
- Format **Kelas** mengikuti contoh: `Teknologi Blockchain C` (nama mata kuliah + nama kelas, huruf kapital di awal kata).

### 3.6 Kelas di luar Lab IR

Penjelasan Bendahara: _"Itu gw nambahin nama matkul nya di setiap orang tapi ditandain warna. Soalnya itu matkul lab lain, bukan punya IR. Dan gw harus laporan ke kak Naomi dan konfirmasi sama lab yang bersangkutan."_

- Sekretaris/Bendahara mendaftarkan **Kelas di Luar Lab IR** per semester dengan isian:
    - nama mata kuliah, nama kelas, SKS, nama lab/ruang asal, hari dan jam, dan asisten yang ditugaskan;
    - status konfirmasi ke lab terkait: Belum / Sudah dikonfirmasi, plus catatan.
- Data ini **tidak** dicampur dengan tabel `courses`/`classes`, karena tabel itu dipakai untuk enrollment, tugas, dan nilai praktikan.
- Asisten membuat laporan ngawas untuk kelas tersebut seperti biasa, dengan memilih "Kelas di luar Lab IR" di form.
- Di Excel, baris kelas ini muncul di bawah baris asisten yang bersangkutan, dan sel kehadirannya diberi warna **oranye** (gaya sel legenda "Tambahan", `A82`). Jamnya dihitung dengan rumus SKS biasa (keputusan P2).
- **Mata kuliah Lab IR yang sementara dilaksanakan di luar ruang lab** (misalnya karena perbaikan) **ditandai sama seperti kelas lab lain** (keputusan P3):
    - Asisten mencentang **"Dilaksanakan di luar ruang Lab IR"** di form laporan dan mengisi lokasi.
    - Barisnya tetap baris kelas IR yang biasa (tidak dibuat baris baru), tetapi sel tanggal itu diwarnai **oranye**.
    - Laporan ini ikut muncul di daftar ringkas untuk Kak Naomi (US-RJN-06 butir 3).
- Jika satu sel memenuhi dua penanda (misalnya kelas pengganti yang dilaksanakan di luar lab), prioritas warna: Ditiadakan > Ujian > Di luar Lab IR (oranye) > Pengganti > Tambahan.

---

## 4. User Stories & Acceptance Criteria

**US-RJN-01 — Mengisi status pelaksanaan di laporan ngawas [Wajib]**
Sebagai Asisten, saya ingin memilih status pelaksanaan kelas saat membuat laporan, agar rekap Excel otomatis sesuai dengan laporan harian.

1. Form laporan (FR-AR-01) memiliki field **Status pelaksanaan** dengan default **Terlaksana**. Pilihannya Terlaksana, Ditiadakan, Pengganti, Tambahan, dan Ujian (UTS/UAS).
2. When status **Pengganti**, Then muncul field wajib **Tanggal kelas asli**. Jam asli bersifat opsional, dan default-nya jam jadwal kelas.
3. When status **Ujian**, Then muncul pilihan **UTS / UAS**.
4. When status **Ditiadakan**, Then foto dokumentasi menjadi **opsional** dan alasan wajib diisi di deskripsi (minimal 10 karakter).
5. Validasi pada 3.4 berjalan saat disimpan, dengan pesan dalam Bahasa Indonesia.
6. Daily Accordion (FR-AR-02) menampilkan badge status pelaksanaan dengan warna yang sama dengan legenda.
7. Dokumen Word harian menampilkan status pelaksanaan di kolom deskripsi, misalnya diawali "[Kelas ditiadakan]". Template Word tidak diubah.

**US-RJN-02 — Mengunggah tanda tangan [Wajib]**
Sebagai Asisten, saya ingin menyimpan tanda tangan saya sekali, agar sistem memasangnya otomatis di sheet absensi.

1. Di **Pengaturan › Profil** (khusus peran Asisten) ada kartu **Tanda Tangan** dengan dua cara isi: **Unggah gambar** (PNG/JPG, ≤ 1 MB, latar putih atau transparan) atau **Gambar langsung** di kanvas (mouse/sentuh). Hasilnya disimpan sebagai PNG transparan.
2. Sistem memangkas ruang kosong dan menyesuaikan ukuran (lebar maksimal 600 px). Pratinjau tampil sebelum disimpan.
3. Tanda tangan dapat diganti atau dihapus. Rekap yang sudah **Final** tetap memakai tanda tangan saat Final (snapshot).
4. File disimpan di disk privat dengan `purpose = signature`. Hanya pemilik, Sekretaris/Bendahara, dan Admin yang bisa melihatnya, lewat signed URL.
5. When asisten belum punya tanda tangan, Then dashboard asisten menampilkan pengingat, dan pemeriksaan rekap (FR-RJN-07) memberi **peringatan** (bukan blokir). Sel sheet 2 asisten tersebut dibiarkan kosong agar Bendahara tetap bisa merekap.
6. Tanda tangan dipasang **otomatis** di sheet 2 untuk setiap sel hadir, **tanpa perlu konfirmasi asisten** (keputusan P5). Alasannya, Bendahara sering harus merekap mendesak saat asisten lain tidak bisa dihubungi.

**US-RJN-03 — Melihat rekap bulanan saya [Wajib]**
Sebagai Asisten, saya ingin melihat rekap ngawas saya sebelum dikirim ke kampus, agar kesalahan ketahuan lebih awal.

1. Di **Generate › Rekap Jam Ngawas**, asisten memilih bulan dan melihat grid **baris miliknya**: kelas × tanggal, warna, jam per sel, total hari, dan total jam.
2. Klik sel menampilkan laporan sumbernya (tanggal, kelas, status, tim jaga) dengan tautan ke laporan.
3. When rekap ditetapkan Final, Then asisten mendapat notifikasi dan melihat jam ngawas miliknya di halaman rekap (hanya-baca). File Excel Final hanya diunduh Sekretaris/Bendahara dan Admin, lalu dikirim ke kampus oleh Bendahara; asisten tidak mengunduhnya karena memuat data seluruh asisten.
4. When ada yang salah, Then asisten memperbaiki laporannya lewat alur yang sudah ada (laporan dikembalikan atau minta buka kunci). Tidak ada edit sel langsung di grid.

**US-RJN-04 — Menyusun, memeriksa, dan memfinalkan rekap [Wajib]**
Sebagai Sekretaris/Bendahara, saya ingin sistem menyusun rekap seluruh asisten dan menunjukkan masalahnya, agar saya hanya perlu meninjau lalu mengunduh.

1. Grid menampilkan semua asisten, dengan urutan yang bisa diatur (FR-RJN-06).
2. Panel **Pemeriksaan** menampilkan daftar temuan (FR-RJN-07). Tombol **Tetapkan Final** nonaktif selama ada temuan berlevel **Blokir**.
3. When saya klik **Tetapkan Final**, Then data rekap dibekukan (snapshot), status menjadi **Final**, dan ekspor Excel kampus diproses di antrean. Saya mendapat notifikasi beserta tautan unduh.
4. When Kak Naomi memberi revisi, Then saya klik **Buka Kembali (Revisi)** dengan catatan wajib. Status kembali Draft, nomor revisi bertambah, dan riwayat versi file lama tetap tersimpan.

**US-RJN-05 — Mengelola kalender, SK, dan pengesah [Wajib]**
Sebagai Admin, saya ingin mengisi data yang dibutuhkan kop dan warna, agar rekap tidak perlu diedit manual.

1. **Admin › Semester:** field **Nomor SK** (misalnya `03/SK/Genap/IRLab/2026`) dan **Label semester** (misalnya `SEMESTER GENAP TAHUN 2025/2026`), yang muncul di kop sheet 1 dan 2.
2. **Admin › Kalender Akademik:** CRUD tanggal atau rentang tanggal bertipe **Libur Nasional**, **UTS**, atau **UAS**, dengan keterangan. **[Sebaiknya]** impor libur nasional dari daftar.
3. **Admin › Pengaturan Sistem › Pengesah Rekap Ngawas:** dua pengesah terpisah (keputusan P4). Untuk masing-masing diisi: **jabatan** (misalnya "Kepala Laboratorium" dan "Dekan"), **nama**, dan **gambar tanda tangan** (PNG transparan). NIP tidak dipakai karena tidak ada di template. Jika pengesah berganti di tengah semester, rekap Final lama tetap memakai data lama (snapshot).
4. **Admin › Pengaturan Sistem › Template:** mengganti file template `.xlsx` bila kampus merilis versi baru, dengan validasi struktur minimal (FR-RJN-12).

**US-RJN-06 — Mengelola kelas di luar Lab IR [Wajib]**
Sebagai Sekretaris/Bendahara, saya ingin mencatat kelas lab lain yang diawas asisten IR, agar tetap masuk rekap dan bisa saya laporkan.

1. Ada CRUD sesuai 3.6, dengan filter semester.
2. Kelas ini muncul di form laporan ngawas untuk asisten yang ditugaskan.
3. Halaman ini punya daftar ringkas per bulan (tanggal, asisten, kelas, lab asal, status konfirmasi) yang bisa disalin atau diunduh untuk dilaporkan ke Kak Naomi dan lab terkait.

---

## 5. Functional Requirements (FR-RJN)

| ID        | Kebutuhan                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    | Prioritas |
| --------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------- |
| FR-RJN-01 | Laporan ngawas memiliki `execution_status` (`held`, `cancelled`, `replacement`, `additional`, `exam`), `exam_type` (`uts`/`uas`, untuk `exam`), `original_date` dan `original_start_time` (untuk `replacement`), `replacement_note` (keterangan tambahan untuk tabel pengganti), serta `held_outside_lab` (boolean) dan `outside_location` (teks) untuk kelas IR yang dilaksanakan di luar ruang lab. Laporan lama dianggap `held` dan `held_outside_lab = false`.                                                                                                                                                                                                                           | Wajib     |
| FR-RJN-02 | Validasi simpan laporan: (a) `held` harus jatuh di hari jadwal kelas dan bukan Minggu/libur; (b) `replacement` wajib `original_date` yang ≠ tanggal laporan; (c) satu asisten tidak boleh punya dua laporan non-`cancelled` untuk kelas dan tanggal yang sama; (d) foto opsional untuk `cancelled`.                                                                                                                                                                                                                                                                                                                                                                                          | Wajib     |
| FR-RJN-03 | Laporan dapat merujuk **kelas Lab IR** (`class_id`) **atau** **kelas di luar Lab IR** (`outside_class_id`), tepat salah satu.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                | Wajib     |
| FR-RJN-04 | Kalender akademik (`academic_calendar_events`) per semester: `date_from`, `date_to`, `type` (`national_holiday`, `uts`, `uas`), `label`. Minggu dideteksi otomatis, tidak disimpan.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          | Wajib     |
| FR-RJN-05 | **Mesin rekap** `NgawasRecap::build(semester, month)` menghasilkan struktur netral yang dipakai pratinjau web **dan** ekspor Excel: daftar asisten (urut), baris per kelas, sel per tanggal `{status, credits, hours, reportIds}`, penanda kolom (Minggu/libur/ujian), total, tabel tambahan, tabel pengganti, dan temuan pemeriksaan.                                                                                                                                                                                                                                                                                                                                                       | Wajib     |
| FR-RJN-06 | **Komposisi baris:** setiap Asisten aktif pada semester tersebut mendapat baris untuk (1) setiap kelas Lab IR yang diampunya (`class_assistants`), (2) setiap kelas di luar Lab IR yang ditugaskan, dan (3) kelas lain yang muncul di laporan terverifikasinya. Urutan asisten mengikuti pengaturan **Urutan Rekap** (drag & drop, disimpan per semester; default urut nama). Urutan kelas default hari lalu jam mulai, dan bisa diatur. **Semua asisten aktif semester itu ditampilkan** walau tidak mengawas sama sekali bulan itu (keputusan P8): baris kelasnya tetap muncul dengan sel kosong. Asisten tanpa kelas sama sekali mendapat satu baris dengan kolom MATA KULIAH berisi `-`. | Wajib     |
| FR-RJN-07 | **Pemeriksaan otomatis** sebelum Final. **Blokir:** laporan bulan itu masih Draft/Diajukan/Dikembalikan; SKS kelas kosong; nomor SK semester kosong; duplikat asisten × kelas × tanggal. **Peringatan:** asisten belum punya tanda tangan (sel sheet 2 dibiarkan kosong); baris kelas tanpa kehadiran sebulan penuh (seperti "DW (KR)"); kelas di luar Lab IR belum dikonfirmasi ke lab terkait; kelas terjadwal di suatu tanggal tanpa laporan apa pun (kemungkinan lupa lapor).                                                                                                                                                                                                            | Wajib     |
| FR-RJN-08 | **Status rekap bulanan:** `draft` → `final` → (Buka Kembali) `draft` dengan `revision + 1`. Tidak ada tahap konfirmasi asisten (keputusan P5). Saat `final`, data rekap dan tanda tangan di-_snapshot_ (`snapshot_json` + salinan file tanda tangan), lalu file Excel dibuat dan disimpan permanen (tidak ikut dihapus `exports:prune`).                                                                                                                                                                                                                                                                                                                                                     | Wajib     |
| FR-RJN-09 | **Buka Kembali** wajib menyertakan catatan revisi (≥ 5 karakter), misalnya "Revisi Kak Naomi: SKS DW (B) tanggal 6". Riwayat revisi beserta file versi lama dapat diunduh.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                   | Wajib     |
| FR-RJN-10 | **Tanda tangan otomatis:** saat ekspor, sistem memasang tanda tangan setiap asisten di semua sel hadirnya pada sheet 2, berdasarkan `users.signature_file_id`, tanpa konfirmasi (keputusan P5). Asisten yang belum punya tanda tangan: sel dibiarkan kosong + peringatan.                                                                                                                                                                                                                                                                                                                                                                                                                    | Wajib     |
| FR-RJN-11 | **Ekspor Excel kampus** lewat job antrean `GenerateNgawasRecapWorkbook` (batas 300 detik) dengan PhpSpreadsheet: buka template, isi sesuai Bagian 7, simpan dengan nama `Rekap_Ngawas_LAB_IR_<BULAN>_<TAHUN>.xlsx` (misalnya `Rekap_Ngawas_LAB_IR_APRIL_2026.xlsx`). Ekspor **Draft** (untuk dicek) diberi watermark/header "DRAFT – belum final" di luar area cetak, dan tanpa tanda tangan pengesah.                                                                                                                                                                                                                                                                                       | Wajib     |
| FR-RJN-12 | Template disimpan di `resources/templates/rekap-ngawas.xlsx`. Admin dapat mengganti template; sistem memvalidasi keberadaan 2 sheet bernama sama, header di baris 13, dan legenda di `A79:B85`. Bila validasi gagal, template lama tetap dipakai.                                                                                                                                                                                                                                                                                                                                                                                                                                            | Wajib     |
| FR-RJN-13 | Pratinjau web: grid sticky (kolom nama dan kelas tetap saat digulir), legenda, total, dan tooltip sel. Di ponsel, tampil sebagai daftar per asisten/kelas dengan jumlah hari dan jam.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        | Wajib     |
| FR-RJN-14 | Ekspor PDF (DomPDF atau konversi LibreOffice) dengan tata letak sama, untuk arsip.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                           | Sebaiknya |
| FR-RJN-15 | Notifikasi: (a) ke Sekretaris/Bendahara saat ekspor selesai; (b) ke semua asisten pada rekap saat rekap ditetapkan Final atau direvisi; (c) pengingat ke asisten yang belum mengunggah tanda tangan (sekali seminggu sampai diunggah). Saluran in-app dan email (mengikuti pengaturan notifikasi yang ada).                                                                                                                                                                                                                                                                                                                                                                                  | Wajib     |
| FR-RJN-16 | Rekap jam yang sudah ada (`HoursRecap`, sheet "Per Mata Kuliah" FR-AGN-03, kartu rekap di halaman Generate) **memakai rumus SKS yang sama** (3.2), agar tidak ada dua angka jam yang berbeda di sistem. Jam dari waktu mulai–selesai tetap ditampilkan hanya sebagai "durasi sesi" di laporan.                                                                                                                                                                                                                                                                                                                                                                                               | Wajib     |

---

## 6. Data Model

Perubahan ini mengikuti konvensi PRD utama 6.1 (MySQL 8, `id` BIGINT, timestamps).

### 6.1 Tabel baru

| Tabel                      | Kolom                                                                                                                                                                                                | Catatan                        |
| -------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------ |
| `academic_calendar_events` | semester_id, date_from, date_to, type ENUM(national_holiday, uts, uas), label, created_by                                                                                                            | Index (semester_id, date_from) |
| `outside_classes`          | semester_id, course_name, class_name, credits (1–6), origin_lab, day_of_week (nullable), start_time, end_time (nullable), lab_confirmed_at (nullable), lab_confirmation_note, created_by, deleted_at | Kelas di luar Lab IR           |
| `outside_class_assistants` | outside_class_id, user_id                                                                                                                                                                            | PK gabungan                    |
| `ngawas_recaps`            | semester_id, month CHAR(7), status ENUM(draft, final), revision (default 0), finalized_by, finalized_at, reopened_note, snapshot_json (nullable), file_id (nullable)                                 | UNIQUE(semester_id, month)     |
| `ngawas_recap_revisions`   | ngawas_recap_id, revision, file_id, note, created_by                                                                                                                                                 | Arsip file setiap Final        |
| `ngawas_recap_orders`      | semester_id, user_id, position, class_order_json (nullable)                                                                                                                                          | Urutan asisten/kelas di rekap  |

### 6.2 Kolom tambahan

| Tabel                 | Kolom baru                                                                                                                                                                                                                                                                                       | Catatan                                                          |
| --------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ---------------------------------------------------------------- |
| `supervision_reports` | execution_status VARCHAR(20) default `held`, exam_type VARCHAR(3) nullable, original_date DATE nullable, original_start_time TIME nullable, replacement_note VARCHAR(255) nullable, held_outside_lab BOOLEAN default false, outside_location VARCHAR(150) nullable, outside_class_id FK nullable | `class_id` menjadi nullable; aturan "tepat salah satu" di action |
| `semesters`           | sk_number VARCHAR(80) nullable, term_label VARCHAR(80) nullable                                                                                                                                                                                                                                  | Kop rekap                                                        |
| `users`               | signature_file_id FK `files` nullable                                                                                                                                                                                                                                                            | Tanda tangan asisten                                             |
| `settings` (key baru) | `ngawas_recap.approvers` (JSON: daftar 2 pengesah `{position, name, signature_file_id}`), `ngawas_recap.template_file_id`                                                                                                                                                                        | Pengesah & template                                              |

Indeks: `supervision_reports(status, date)` untuk query rekap bulanan, dan `supervision_reports(class_id, date)` untuk deteksi duplikat.

---

## 7. Spesifikasi Template Excel (tidak boleh diubah)

Kampus menetapkan bahwa **hanya** bagian berikut yang boleh berubah: kelas, nama, NIM, mata kuliah, keterangan kop (nomor SK, semester/tahun, periode), dan isi tabel Kelas Tambahan/Pengganti. Selebihnya (font, warna, lebar kolom, merge, logo, legenda, posisi, ukuran kertas) wajib identik dengan template.

### 7.1 Sheet 1 — "Format Aslab " (nama sheet persis, termasuk spasi di akhir)

| Bagian                | Sel                                                                                 | Isi                                                                                                                                                                | Sumber                                                     |
| --------------------- | ----------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ---------------------------------------------------------- |
| Kop                   | A1:A5 (merge selebar tabel) + 2 logo                                                | Teks lab & alamat                                                                                                                                                  | **Tetap dari template**                                    |
| Judul                 | A8                                                                                  | `REKAPITULASI KEHADIRAN ASISTEN LABORATORIUM INFORMATION RETRIEVAL`                                                                                                | Tetap                                                      |
| Semester              | A9                                                                                  | `SEMESTER GENAP TAHUN 2025/2026`                                                                                                                                   | `semesters.term_label`                                     |
| SK                    | A10                                                                                 | `SESUAI SK NOMOR: <sk_number>`                                                                                                                                     | `semesters.sk_number`                                      |
| Periode               | A11                                                                                 | `PERIODE BULAN: <BULAN KAPITAL> <TAHUN>`                                                                                                                           | Bulan rekap                                                |
| Header                | Baris 13–15                                                                         | NO · NAMA MAHASISWA · NIM · MATA KULIAH · TANGGAL, BULAN (E14 = tanggal 1, baris 15 = 1…n) · TOTAL JUMLAH HARI KEHADIRAN · TOTAL JAM HADIR                         | Header tetap; jumlah kolom tanggal = jumlah hari bulan itu |
| Data                  | Mulai baris 16                                                                      | Per asisten: NO, NAMA, NIM (merge vertikal sebanyak barisnya), lalu satu baris per kelas                                                                           | Bagian 3 & FR-RJN-06                                       |
| Sel kehadiran         | Kolom E…                                                                            | `=<SKS>*50/60` atau kosong; warna sesuai 3.4                                                                                                                       | Mesin rekap                                                |
| Total hari            | Kolom setelah tanggal terakhir                                                      | `=COUNT(E<r>:<kolom akhir><r>)` per baris                                                                                                                          | Rumus                                                      |
| Total jam             | Kolom terakhir (merge vertikal per asisten)                                         | `=ROUND(SUM(E<r1>:<kolom akhir><r2>),0)`                                                                                                                           | Rumus                                                      |
| Baris total           | Setelah data                                                                        | `=SUM(...)` dan `=ROUND(SUM(...),0)`                                                                                                                               | Rumus                                                      |
| Legenda               | "Keterangan" + 6 baris                                                              | Tetap                                                                                                                                                              | **Tetap dari template**                                    |
| Kelas Tambahan        | Judul + header + baris data                                                         | Tanggal (format tanggal) · Kelas · Jam · Keterangan                                                                                                                | 3.5                                                        |
| Kelas Pengganti       | Judul + header + baris data                                                         | sda                                                                                                                                                                | 3.5                                                        |
| Tanda tangan pengesah | Area tanda tangan di file Mei (gambar di sel `AF80` (hasil UAT; semula `AF77` menimpa jabatan), ±175 × 120 px, di bawah tabel) | **Dua gambar terpisah** berdampingan (Kepala Lab dan Dekan), masing-masing dengan jabatan dan nama di bawah gambar, memakai font dan gaya teks template. Tanpa NIP | Pengaturan Admin (keputusan P4), hanya saat Final          |

### 7.2 Sheet 2 — "Absensi Kehadiran Aslab (TTD)"

- Kop, judul (`ABSENSI KEHADIRAN ASISTEN LABORATORIUM INFORMATION RETRIEVAL`), semester, SK, dan periode sama dengan sheet 1.
- Baris asisten × kelas **sama persis urutannya** dengan sheet 1.
- **Sel kehadiran berisi gambar tanda tangan asisten** (disesuaikan agar muat di dalam sel, dengan margin 2 px) di setiap sel yang di sheet 1 bernilai jam. Warna sel mengikuti sheet 1.
- Kolom **TOTAL JUMLAH HARI KEHADIRAN** dihitung oleh sistem dan ditulis sebagai angka. Rumus `COUNT` tidak menghitung gambar, jadi nilai angka yang dipakai.
- Baris **TOTAL** di akhir tabel.

### 7.3 Aturan teknis pengisian

1. Template dasar `resources/templates/rekap-ngawas.xlsx` dibuat dari **file Mei 2026** (keputusan P7). Isi data, tabel catatan, dan gambar tanda tangan dikosongkan; kop, logo, legenda, gaya, lebar kolom, dan pengaturan cetak dipertahankan. File Mei dipakai karena sudah versi **31 hari**. Untuk bulan 28/29/30 hari, kolom tanggal berlebih dihapus lewat `removeColumn`, agar merge, lebar, dan legenda bergeser rapi.
2. Baris data dibuat dengan menyalin gaya baris contoh (font Calibri 12, border, tinggi baris) lalu menyisipkan baris (`insertNewRowBefore`). Merge vertikal NO/NAMA/NIM/TOTAL JAM dibuat ulang per asisten.
3. Warna diambil dengan **menyalin gaya sel legenda** (`A80:A85`) supaya warna tema identik.
4. Nomor urut NO selalu 1…n tanpa duplikat (mencegah M8).
5. Format NIM diseragamkan menjadi **`2023 – 31 – 044`** (tahun – kode prodi – nomor, dipisah _en dash_ dengan spasi), karena format ini yang paling banyak dipakai di file contoh (keputusan P6). NIM disimpan apa adanya di `users.nim`; formatter hanya dipakai saat ekspor.
6. Setelah dibuat, sistem membuka ulang file dan memverifikasi bahwa nilai hasil hitung PhpSpreadsheet untuk total jam dan hari sama dengan hasil mesin rekap. Bila beda, ekspor gagal dengan pesan jelas.
7. Pengaturan cetak (landscape, A4/Folio sesuai template, _fit to page_) tidak diubah.

---

## 8. Alur

```
[Asisten] isi laporan ngawas + status pelaksanaan  ──►  [Sek/Bend] verifikasi (sudah ada)
                                                                  │
                    (bulan selesai)                               ▼
[Sek/Bend] Generate › Rekap Jam Ngawas › pilih bulan ─► Mesin rekap + Pemeriksaan
          │  temuan Blokir? ── ya ─► perbaiki laporan / data master ─┐
          │                                                         │
          └─ tidak ◄────────────────────────────────────────────────┘
                         │
[Sek/Bend] Tetapkan Final ─► snapshot + Excel kampus (tanda tangan asisten & pengesah otomatis)
                         │
                         └─► unduh ─► Bendahara kirim ke Kak Naomi ─► asisten dapat notifikasi (lihat jam sendiri)
                         │
          revisi dari Kak Naomi? ── ya ─► Buka Kembali (catatan) ─► revisi +1 ─► ulangi
```

---

## 9. UI / Halaman

| Halaman                     | Lokasi                                                                 | Isi utama                                                                                                                                                                                                                                                       |
| --------------------------- | ---------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Rekap Jam Ngawas** (baru) | `Asisten › Generate`, tab kedua di samping "Rekap Laporan Bulanan"     | Pemilih bulan; badge status (Draft/Final rev. n); kartu ringkasan (jumlah asisten, total hari, total jam, tanda tangan terunggah x/y); grid; panel Pemeriksaan; tombol sesuai peran: Tetapkan Final / Buka Kembali / Unduh Excel (Draft/Final) / Riwayat revisi |
| Pengaturan urutan           | Dialog dari halaman di atas                                            | Drag & drop asisten dan kelas                                                                                                                                                                                                                                   |
| Form laporan ngawas         | `Asisten › Report` (modal yang sudah ada)                              | Field Status pelaksanaan, field kondisional, pilihan kelas IR / di luar Lab IR                                                                                                                                                                                  |
| Tanda Tangan                | `Pengaturan › Profil`                                                  | Unggah / gambar / pratinjau / hapus                                                                                                                                                                                                                             |
| Kelas di Luar Lab IR        | `Asisten › Generate › Rekap Jam Ngawas › Kelas di Luar Lab` (Sek/Bend) | Tabel CRUD + daftar bulanan                                                                                                                                                                                                                                     |
| Kalender Akademik           | `Admin › Semester › Kalender`                                          | Kalender bulanan + daftar; tambah rentang                                                                                                                                                                                                                       |
| Semester                    | `Admin › Semester` (yang ada)                                          | Field Nomor SK & Label semester                                                                                                                                                                                                                                 |
| Pengaturan Sistem           | `Admin › Pengaturan Sistem` (yang ada)                                 | Bagian "Rekap Ngawas": 2 pengesah (jabatan, nama, gambar tanda tangan), template                                                                                                                                                                                |

Warna di web mengikuti legenda template. Setiap warna tetap disertai label atau ikon, agar tidak bergantung pada warna saja (aksesibilitas, PRD utama 8.5).

---

## 10. Non-Functional Requirements

| Kategori       | Kebutuhan                                                                                                                                                                              |
| -------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Kinerja        | Pratinjau satu bulan (± 15 asisten × 7 kelas × 31 hari) dimuat ≤ 1,5 detik; ekspor Excel ≤ 30 detik                                                                                    |
| Ketepatan      | Total jam dari mesin rekap = nilai rumus Excel (FR-RJN-11 & 7.3.6); ditutup test otomatis                                                                                              |
| Keamanan       | File tanda tangan di disk privat, akses lewat signed URL ≤ 15 menit, tidak muncul di pencarian; audit log untuk unggah/hapus tanda tangan, perubahan pengesah, Final, dan Buka Kembali |
| Integritas     | Rekap Final tidak berubah walau laporan atau tanda tangan diubah kemudian (snapshot)                                                                                                   |
| Kompatibilitas | File dibuka tanpa peringatan "repair" di Microsoft Excel 2016+ dan Google Sheets                                                                                                       |
| Kualitas       | PHPStan level 7 tanpa error baru; test feature untuk setiap FR Wajib                                                                                                                   |

---

## 11. Rencana Pengujian (ringkas)

1. **Unit test mesin rekap:** 2 SKS → 1,6667; 3 SKS → 2,5; `cancelled` tidak dihitung; pengganti lintas bulan masuk bulan pelaksanaan; tim jaga 2 orang → keduanya dapat jam; duplikat terdeteksi.
2. **Golden-file test:** data seed yang meniru **April 2026** menghasilkan total per asisten sesuai perhitungan benar. Rentang total wajib dimulai dari kolom tanggal 1, termasuk 4 asisten yang di file manual kurang jam.
3. **Validasi form laporan:** Terlaksana di hari yang salah, di hari Minggu, dan di hari libur ditolak; Pengganti tanpa tanggal asli ditolak.
4. **Alur status:** draft → final → reopen → final rev. 1; hak akses per peran; tanda tangan otomatis terpasang dan sel kosong untuk asisten tanpa tanda tangan; asisten tanpa laporan tetap muncul sebagai baris kosong; sel oranye untuk kelas di luar Lab IR.
5. **Ekspor:** jumlah kolom tanggal 28/29/30/31; sheet 2 berisi gambar di sel yang tepat; file lolos dibuka ulang oleh PhpSpreadsheet tanpa error.
6. **UAT:** Bendahara membandingkan hasil sistem dengan rekap manual untuk 1 bulan berjalan, lalu mengirim ke Kak Naomi untuk uji terima.

---

## 12. Keputusan Klarifikasi (20 Nov 2026)

| ID  | Pertanyaan                                         | Keputusan                                                                                                       | Bagian terdampak           |
| --- | -------------------------------------------------- | --------------------------------------------------------------------------------------------------------------- | -------------------------- |
| P1  | Pembulatan total jam                               | **Ikuti template:** `ROUND(…,0)` per asisten                                                                    | 3.2, 7.1                   |
| P2  | Warna sel kelas di luar Lab IR                     | **Oranye**, seperti di Excel selama ini (gaya legenda "Tambahan")                                               | 3.4, 3.6                   |
| P3  | Mata kuliah IR yang dilaksanakan di luar ruang lab | **Ditandai seperti kelas lab lain** (oranye), lewat centang "Dilaksanakan di luar ruang Lab IR"                 | 3.6, FR-RJN-01             |
| P4  | Tanda tangan pengesah                              | **Dua gambar terpisah** (Kepala Lab, Dekan) **dengan nama**, tanpa NIP. Hasil tidak boleh berbeda dari template | US-RJN-05, 7.1             |
| P5  | Tanda tangan asisten                               | **Langsung otomatis**, tanpa konfirmasi asisten                                                                 | US-RJN-02/03, FR-RJN-08/10 |
| P6  | Format NIM                                         | **`2023 – 31 – 044`** (en dash dengan spasi)                                                                    | 7.3                        |
| P7  | Template kosong                                    | **Dibuat dari file Mei 2026** yang dikosongkan; kampus tidak menyediakan versi kosong                           | 7.3, Catatan Perubahan B2  |
| P8  | Asisten yang tidak mengawas sebulan penuh          | **Tetap ditampilkan** sebagai baris kosong                                                                      | FR-RJN-06                  |

**Satu hal teknis untuk dicek saat UAT:** file contoh hanya memuat satu gambar di area tanda tangan sheet 1 dan belum memperlihatkan posisi tulisan nama. Saat implementasi, posisi dua gambar dan nama dibuat sebagai konstanta yang mudah digeser, lalu dicocokkan dengan satu rekap Final yang sudah ditandatangani (bisa minta ke Bendahara) sebelum dipakai resmi.

---

## 13. Rilis & Migrasi

1. Migrasi menambah kolom dengan default aman. Laporan lama otomatis `execution_status = held`, sehingga tidak ada data rusak.
2. Admin mengisi Nomor SK dan Label semester aktif, kalender libur dan UTS/UAS, serta 2 pengesah (jabatan, nama, gambar).
3. Asisten diminta mengunggah tanda tangan (pengingat di dashboard).
4. **Uji paralel 1 bulan:** rekap manual tetap dibuat dan dibandingkan dengan hasil sistem, lalu sistem dipakai penuh mulai bulan berikutnya.
5. Dokumentasi yang perlu diperbarui setelah rilis: `docs/06-features/reports.md`, `docs/04-database.md`, `docs/05-roles-permissions.md`, dan panduan pengguna di `docs/09-user-guide`.
