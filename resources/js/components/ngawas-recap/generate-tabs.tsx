import { Link } from '@inertiajs/react';
import GenerateController from '@/actions/App/Http/Controllers/Asisten/GenerateController';
import NgawasRecapController from '@/actions/App/Http/Controllers/Asisten/NgawasRecapController';
import { cn } from '@/lib/utils';

/** Tab di halaman Generate: Rekap Laporan Bulanan | Rekap Jam Ngawas (PRD Bagian 9). */
export function GenerateTabs({ current }: { current: 'reports' | 'ngawas' }) {
    const tabs = [
        {
            key: 'reports',
            label: 'Rekap Laporan Bulanan',
            href: GenerateController.index.url(),
        },
        {
            key: 'ngawas',
            label: 'Rekap Jam Ngawas',
            href: NgawasRecapController.index.url(),
        },
    ] as const;

    return (
        <nav aria-label="Jenis rekap" className="flex flex-wrap gap-1 border-b">
            {tabs.map((tab) => (
                <Link
                    key={tab.key}
                    href={tab.href}
                    aria-current={current === tab.key ? 'page' : undefined}
                    className={cn(
                        '-mb-px border-b-2 px-4 py-2 text-sm font-medium focus-visible:ring-[3px] focus-visible:ring-ring/40 focus-visible:outline-none',
                        current === tab.key
                            ? 'border-primary-700 font-bold text-primary-900'
                            : 'border-transparent text-muted-foreground hover:text-foreground',
                    )}
                >
                    {tab.label}
                </Link>
            ))}
        </nav>
    );
}
