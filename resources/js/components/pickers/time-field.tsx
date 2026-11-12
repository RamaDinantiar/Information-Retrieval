import { Clock } from 'lucide-react';
import { useState } from 'react';
import { cn } from '@/lib/utils';

/** "0830" → "08.30"; "08" → "08"; "8" → "8" (tampilan saat mengetik). */
const display = (digits: string) =>
    digits.length > 2 ? `${digits.slice(0, 2)}.${digits.slice(2)}` : digits;

/** Ubah ketikan ("8", "830", "08.30", "08:30") jadi "HH:MM", atau '' bila tidak valid. */
function parse(raw: string): string {
    const d = raw.replace(/\D/g, '').slice(0, 4);
    if (d.length === 0) return '';
    let hh: string;
    let mm: string;
    if (d.length <= 2) {
        hh = d.padStart(2, '0');
        mm = '00';
    } else if (d.length === 3) {
        hh = `0${d[0]}`;
        mm = d.slice(1);
    } else {
        hh = d.slice(0, 2);
        mm = d.slice(2);
    }
    return Number(hh) < 24 && Number(mm) < 60 ? `${hh}:${mm}` : '';
}

const fromValue = (value: string) => value.slice(0, 5).replace(':', '.');

/**
 * Input jam yang diketik langsung (pengganti `<input type="time">` bawaan
 * browser). Ketik "0830" atau "8.30" dan titik muncul otomatis; "8" menjadi
 * 08.00 saat selesai. Nilai "HH:MM" (24 jam, WIB). Terkendali lewat
 * `value`/`onChange`, atau mandiri lewat `defaultValue` + `name`.
 */
export function TimeField({
    id,
    name,
    value: controlled,
    defaultValue = '',
    onChange,
    label,
    invalid,
    required,
}: {
    id?: string;
    /** Nama field form: nilai dikirim lewat input tersembunyi. */
    name?: string;
    value?: string;
    defaultValue?: string;
    onChange?: (value: string) => void;
    /** Nama aksesibel, mis. "Jam mulai". */
    label: string;
    invalid?: boolean;
    required?: boolean;
    /** Tidak dipakai lagi (jam diketik bebas); dibiarkan agar pemanggil lama tetap valid. */
    minuteStep?: 1 | 5;
}) {
    const [own, setOwn] = useState(defaultValue.slice(0, 5));
    const value = (controlled ?? own).slice(0, 5);
    const [draft, setDraft] = useState(fromValue(value));
    const [seen, setSeen] = useState(value);
    const [bad, setBad] = useState(false);

    // Nilai berubah dari luar (mis. form di-reset): ikuti, kecuali ketikan
    // saat ini sudah menghasilkan nilai yang sama.
    if (value !== seen) {
        setSeen(value);
        if (parse(draft) !== value) {
            setDraft(fromValue(value));
            setBad(false);
        }
    }

    const commit = (next: string) => {
        setSeen(next);
        setOwn(next);
        onChange?.(next);
    };

    return (
        <div className="relative">
            {name && (
                <input
                    type="hidden"
                    name={name}
                    value={value}
                    required={required}
                />
            )}
            <div className="relative">
                <Clock
                    className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-primary-700"
                    aria-hidden
                />
                <input
                    id={id}
                    type="text"
                    inputMode="numeric"
                    autoComplete="off"
                    maxLength={5}
                    placeholder="08.00"
                    aria-label={label}
                    aria-invalid={invalid || undefined}
                    value={draft}
                    onFocus={(e) => {
                        setBad(false);
                        e.currentTarget.select();
                    }}
                    // Cegah klik melepas pilihan, supaya ketikan langsung mengganti jam lama.
                    onMouseUp={(e) => e.preventDefault()}
                    onChange={(e) => {
                        const digits = e.target.value
                            .replace(/\D/g, '')
                            .slice(0, 4);
                        setDraft(display(digits));
                        setBad(false);
                        // Hanya jam penuh (4 angka) yang langsung dikirim ke form;
                        // ketikan sebagian diselesaikan saat kolom ditinggalkan.
                        if (digits.length === 4) {
                            const next = parse(digits);
                            if (next) commit(next);
                        }
                    }}
                    onBlur={() => {
                        if (draft === '') {
                            setBad(false);
                            commit('');
                            return;
                        }
                        const next = parse(draft);
                        if (next) {
                            setDraft(fromValue(next));
                            setBad(false);
                            commit(next);
                        } else {
                            // Tidak valid: kembali ke jam sebelumnya dan beri tahu.
                            setBad(true);
                            setDraft(fromValue(value));
                        }
                    }}
                    className={cn(
                        'h-10 w-full border border-input bg-white pr-3 pl-9 text-sm tabular-nums placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/25 focus-visible:outline-none aria-invalid:border-destructive',
                    )}
                />
            </div>
            {bad && (
                <p role="alert" className="mt-1 text-xs text-destructive">
                    Jam tidak valid. Contoh: 08.30
                </p>
            )}
        </div>
    );
}
