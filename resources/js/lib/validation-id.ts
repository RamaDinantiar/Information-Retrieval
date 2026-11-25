/**
 * Menerjemahkan pesan validasi bawaan browser (atribut `required`, `type=email`,
 * `minlength`, dst.) ke bahasa Indonesia, agar seragam dengan pesan dari server.
 * Perilaku validasi tidak berubah — hanya teks balon yang diganti.
 */
type Field = HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement;

const isField = (el: EventTarget | null): el is Field =>
    el instanceof HTMLInputElement ||
    el instanceof HTMLSelectElement ||
    el instanceof HTMLTextAreaElement;

function messageFor(el: Field): string {
    const v = el.validity;
    const type = el instanceof HTMLInputElement ? el.type : '';

    if (v.valueMissing) {
        if (type === 'checkbox' || type === 'radio') {
            return 'Centang pilihan ini untuk melanjutkan.';
        }

        if (type === 'file') {
            return 'Pilih berkas terlebih dahulu.';
        }

        return el instanceof HTMLSelectElement
            ? 'Pilih salah satu opsi.'
            : 'Kolom ini wajib diisi.';
    }

    if (v.typeMismatch) {
        return type === 'url'
            ? 'Masukkan URL yang valid.'
            : 'Masukkan alamat email yang valid.';
    }

    if (v.patternMismatch) {
        return el.title || 'Format isian tidak sesuai.';
    }

    if (!(el instanceof HTMLSelectElement)) {
        if (v.tooShort) {
            return `Minimal ${el.minLength} karakter.`;
        }

        if (v.tooLong) {
            return `Maksimal ${el.maxLength} karakter.`;
        }
    }

    if (el instanceof HTMLInputElement) {
        if (v.rangeUnderflow) {
            return `Nilai minimal ${el.min}.`;
        }

        if (v.rangeOverflow) {
            return `Nilai maksimal ${el.max}.`;
        }

        if (v.stepMismatch) {
            return 'Nilai tidak sesuai kelipatan yang diizinkan.';
        }
    }

    return v.badInput ? 'Nilai tidak valid.' : 'Isian tidak valid.';
}

if (typeof document !== 'undefined') {
    // Fase capture: `invalid` tidak menggelembung.
    document.addEventListener(
        'invalid',
        (event) => {
            if (!isField(event.target)) {
                return;
            }

            event.target.setCustomValidity('');
            event.target.setCustomValidity(messageFor(event.target));
        },
        true,
    );

    const clear = (event: Event) => {
        if (isField(event.target)) {
            event.target.setCustomValidity('');
        }
    };

    document.addEventListener('input', clear, true);
    document.addEventListener('change', clear, true);
}

if (typeof document !== 'undefined') {
    // Audit 09 T2: pesan kustom lama bisa tertinggal pada kolom yang kemudian
    // diisi lewat kode (tanpa event `input`) sehingga tombol kirim terkunci.
    // Hapus semua pesan kustom di form sebelum browser memvalidasi ulang.
    document.addEventListener(
        'click',
        (event) => {
            const button = (event.target as Element | null)?.closest?.(
                'button, input[type="submit"]',
            );

            if (
                !(button instanceof HTMLButtonElement) &&
                !(button instanceof HTMLInputElement)
            ) {
                return;
            }

            if (button.type !== 'submit' || !button.form) {
                return;
            }

            for (const el of Array.from(button.form.elements)) {
                if (isField(el)) {
                    el.setCustomValidity('');
                }
            }
        },
        true,
    );
}
