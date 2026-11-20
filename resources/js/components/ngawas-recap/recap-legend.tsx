import { cn } from '@/lib/utils';

/** Penanda tiap warna: huruf/ikon kecil di sel, jadi tidak bergantung warna saja. */
export const KIND_MARK = {
    cancelled: '✕',
    exam: 'U',
    outside: 'L',
    replacement: 'P',
    additional: 'T',
} as const;

const ITEMS: { className: string; mark?: string; label: string }[] = [
    { className: 'ngawas-sunday', label: 'Minggu' },
    { className: 'ngawas-holiday', label: 'Libur Nasional' },
    { className: 'ngawas-additional', mark: 'T', label: 'Tambahan' },
    {
        className: 'ngawas-replacement',
        mark: 'P',
        label: 'SKS Pengganti dan SKS Ngawas',
    },
    { className: 'ngawas-cancelled', mark: '✕', label: 'Kelas ditiadakan' },
    { className: 'ngawas-exam', mark: 'U', label: 'UTS / UAS' },
    {
        className: 'ngawas-outside',
        mark: 'L',
        label: 'Di luar Lab IR (lab lain / di luar ruang lab)',
    },
];

/** Legenda warna rekap (mengikuti legenda template kampus, A80:B85). */
export function RecapLegend() {
    return (
        <section aria-label="Keterangan warna" className="border bg-card p-4">
            <h2 className="mb-3 text-sm font-bold">Keterangan</h2>
            <ul className="grid gap-2 text-xs sm:grid-cols-2 lg:grid-cols-3">
                {ITEMS.map((item) => (
                    <li key={item.label} className="flex items-center gap-2">
                        <span
                            aria-hidden
                            className={cn(
                                'flex size-6 shrink-0 items-center justify-center border border-black/20 text-[11px] font-bold',
                                item.className,
                            )}
                        >
                            {item.mark}
                        </span>
                        {item.label}
                    </li>
                ))}
            </ul>
        </section>
    );
}
