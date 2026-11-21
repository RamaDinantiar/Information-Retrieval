# Notifikasi, Pengumuman & Pencarian

[← Daftar fitur](README.md)

## Notifikasi lonceng

Notifikasi muncul di ikon lonceng (jumlah belum dibaca) dan di halaman **Notifikasi**. **Setiap notifikasi di bawah ini juga
dikirim ke email akun** (semua peran), lihat bagian [Email](#email).

| Kejadian                                      | Penerima                                          |
| --------------------------------------------- | ------------------------------------------------- |
| Nilai dipublikasikan                          | Praktikan terkait                                 |
| Tugas dibuka, waktu ditambah, atau dihentikan | Praktikan di kelas tersebut                       |
| Pengingat tenggat H-1 (belum mengumpulkan)    | Praktikan                                         |
| Kontrak kursi dikoreksi Admin                 | Praktikan terkait                                 |
| Kelas penuh & terkunci otomatis               | Asisten pengampu                                  |
| Perubahan jadwal kelas/pertemuan              | Praktikan & asisten terkait                       |
| Pengajuan / jawaban tukar shift               | Asisten terkait; Admin saat tukar shift disetujui |
| Laporan mengawas diajukan                     | Sekretaris/Bendahara & Admin                      |
| Laporan diverifikasi / dikembalikan           | Asisten penulis                                   |
| Rekap selesai dibuat                          | Peminta rekap                                     |
| Persetujuan mata kuliah / kelas               | Asisten pengusul                                  |
| Pengumuman terbit                             | Audiens pengumuman                                |
| Rekap Jam Ngawas Final / dibuka kembali       | Asisten pada rekap                                |
| Pengingat tanda tangan belum diunggah         | Asisten (mingguan, Senin 08:00)                   |
| **Pengingat harian** (lihat di bawah)         | Asisten, Sekretaris/Bendahara                     |

### Pengingat harian (`reminders:send`, 08:00 WIB)

| Pengingat                   | Penerima             | Aturan                                                                                             |
| --------------------------- | -------------------- | -------------------------------------------------------------------------------------------------- |
| Laporan ngawas belum diisi  | Asisten              | Shift 3 hari terakhir (sampai kemarin) tanpa laporan Diajukan/Diverifikasi; maks. 3 kali per shift |
| Pengumpulan belum dinilai   | Asisten pengampu     | Ringkasan harian pengumpulan terbaru yang belum bernilai lebih dari 1 hari                         |
| Laporan menunggu verifikasi | Sekretaris/Bendahara | Ringkasan harian laporan Diajukan lebih dari 1 hari (laporan sendiri tidak dihitung)               |

Pengingat dicatat di tabel `reminder_logs`, sehingga tidak terkirim dua kali pada hari yang sama meski perintah dijalankan ulang.

## Email

- **Semua notifikasi lonceng** (tabel di atas) dikirim juga ke email akun, untuk Praktikan, Asisten, dan Admin. Notifikasi lonceng
  tetap tersimpan langsung; salinan email masuk **antrean** (`LabMailNotification`, dicoba ulang 3 kali bila SMTP gagal) sehingga halaman
  tidak menunggu SMTP.
- Audiens besar (mis. pengumuman global) dikirim bertahap: 20 penerima per 30 detik, agar tidak melewati batas kirim SMTP.
- Akun dengan alamat email tidak valid hanya mendapat notifikasi lonceng.
- **Admin › Pengaturan Sistem › Notifikasi Email** adalah saklar global; matikan sementara bila SMTP bermasalah.
- Email lain di luar notifikasi: **kode OTP reset password**, **password sementara** akun baru/reset Admin, dan **verifikasi email** registrasi.
- Email hanya terkirim bila `MAIL_MAILER=smtp` terisi dan **worker antrean berjalan**. Di lokal (`MAIL_MAILER=log`) email ditulis
  ke `storage/logs/laravel.log`.

## Pengumuman

- **Admin**: pengumuman global, dengan target peran tertentu bila perlu.
- **Asisten**: pengumuman untuk kelas yang diampu.
- Dapat **disematkan**, **dijadwalkan** terbit, dan diberi waktu kedaluwarsa. Pengumuman terjadwal
  dinotifikasikan saat terbit (`announcements:notify`, tiap 5 menit).

## Pencarian global

Tekan **Ctrl K** (⌘K di Mac) dari halaman mana pun. Hasil disesuaikan dengan peran:

| Peran     | Yang dicari                                  |
| --------- | -------------------------------------------- |
| Praktikan | Tugas, topik pertemuan                       |
| Asisten   | Praktikan di kelas yang diampu, tugas, kelas |
| Admin     | Pengguna, mata kuliah, kelas, tugas, ruang   |

## Kode terkait

`app/Support/Notifications/Notify.php`, `app/Notifications/LabNotification.php`, `LabMailNotification.php`,
`app/Actions/Reminder/`, `app/Listeners/SendLabNotifications.php`,
`app/Actions/Announcement/NotifyAnnouncements.php`, `app/Http/Controllers/SearchController.php`.
