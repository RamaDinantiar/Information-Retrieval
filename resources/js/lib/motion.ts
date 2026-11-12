/**
 * Satu-satunya tempat mengatur "rasa" gerak seluruh aplikasi.
 * Detik untuk durasi/jeda; piksel untuk jarak. Ubah di sini, semua halaman ikut.
 */
export const motion = {
    /** Kartu & panel masuk. */
    reveal: { duration: 0.85, y: 16, stagger: 0.08, ease: 'power4.out' },
    /** Bar progres tergambar. */
    bar: { duration: 1.1, delay: 0.25, stagger: 0.09, ease: 'power4.out' },
    /** Angka KPI menghitung naik. */
    count: { duration: 1.4, delay: 0.2, ease: 'power3.out' },
    /** Halaman auth: kartu, lalu isi kiri/kanan. */
    auth: {
        card: { duration: 0.95, y: 20 },
        item: { duration: 0.85, y: 14, stagger: 0.08 },
        ease: 'power4.out',
    },
    /** Ganti tampilan di dalam satu halaman (mis. tab). */
    view: {
        duration: 0.6,
        y: 12,
        stagger: 0.06,
        maxTotal: 0.4,
        ease: 'power3.out',
    },
} as const;
