import { Head, router, usePage } from '@inertiajs/react';
import { CalendarRange, Clock, PenLine, Users } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import NgawasRecapController from '@/actions/App/Http/Controllers/Asisten/NgawasRecapController';
import { EmptyState } from '@/components/empty-state';
import { GenerateTabs } from '@/components/ngawas-recap/generate-tabs';
import { RecapChecksPanel } from '@/components/ngawas-recap/recap-checks';
import { RecapGrid } from '@/components/ngawas-recap/recap-grid';
import { RecapLegend } from '@/components/ngawas-recap/recap-legend';
import { SelectField } from '@/components/select-field';
import { Badge } from '@/components/ui/badge';
import type { NgawasRecapData, RecapChecks } from '@/types';

type Props = {
    filters: { month: string };
    canManage: boolean;
    recap: NgawasRecapData | null;
    checks: RecapChecks | null;
    status: { value: 'draft' | 'final'; revision: number } | null;
    signatures: { signed: number; total: number } | null;
};

const MONTHS = [
    'Januari',
    'Februari',
    'Maret',
    'April',
    'Mei',
    'Juni',
    'Juli',
    'Agustus',
    'September',
    'Oktober',
    'November',
    'Desember',
];

function Stat({
    icon: Icon,
    label,
    value,
    hint,
}: {
    icon: LucideIcon;
    label: string;
    value: string | number;
    hint?: string;
}) {
    return (
        <div className="flex items-center gap-3 border bg-card p-4">
            <span className="flex size-10 shrink-0 items-center justify-center bg-primary-100 text-primary-800">
                <Icon className="size-5" aria-hidden />
            </span>
            <div>
                <p className="text-xs text-muted-foreground">{label}</p>
                <p className="text-h2 leading-tight font-bold">{value}</p>
                {hint && (
                    <p className="text-[11px] text-muted-foreground">{hint}</p>
                )}
            </div>
        </div>
    );
}

/** Asisten › Generate › Rekap Jam Ngawas — pratinjau format kampus (FR-RJN-05/07/13). */
export default function AsistenNgawasRecap({
    filters,
    canManage,
    recap,
    checks,
    status,
    signatures,
}: Props) {
    const semester = usePage().props.activeSemester;
    const [year, month] = filters.month.split('-').map(Number);

    const setMonth = (y: number, m: number) =>
        router.get(
            NgawasRecapController.index.url({
                query: { month: `${y}-${String(m).padStart(2, '0')}` },
            }),
            {},
            { preserveScroll: true },
        );

    return (
        <>
            <Head title="Rekap Jam Ngawas" />
            <div className="flex flex-col gap-6 p-4 pb-28 md:p-6 md:pb-28 lg:p-8 lg:pb-28">
                <div className="space-y-1">
                    <h1 className="text-h1 font-bold text-primary-900">
                        Generate Rekap
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Rekap jam ngawas bulanan berformat kampus. Jam dihitung
                        dari SKS mata kuliah × 50 menit ÷ 60, dari laporan yang
                        sudah diverifikasi.
                    </p>
                </div>

                <GenerateTabs current="ngawas" />

                <section
                    aria-label="Periode"
                    className="flex flex-wrap items-end gap-4 border bg-card p-4"
                >
                    <label className="grid gap-2 text-sm font-medium">
                        Bulan
                        <SelectField
                            value={month}
                            onValueChange={(v) => setMonth(year, Number(v))}
                            options={MONTHS.map((name, i) => ({
                                value: i + 1,
                                label: name,
                            }))}
                        />
                    </label>
                    <label className="grid gap-2 text-sm font-medium">
                        Tahun
                        <SelectField
                            value={year}
                            onValueChange={(v) => setMonth(Number(v), month)}
                            options={[year - 1, year, year + 1].map((y) => ({
                                value: y,
                                label: y,
                            }))}
                        />
                    </label>
                    <div className="ml-auto flex flex-wrap items-center gap-2">
                        {recap && (
                            <span className="bg-primary-100 px-3 py-1 font-mono text-xs text-primary-800">
                                ● {recap.semester.name}
                            </span>
                        )}
                        {status && (
                            <Badge
                                variant={
                                    status.value === 'final'
                                        ? 'success'
                                        : 'warning'
                                }
                            >
                                {status.value === 'final'
                                    ? `Final rev. ${status.revision}`
                                    : status.revision > 0
                                      ? `Draft rev. ${status.revision}`
                                      : 'Draft'}
                            </Badge>
                        )}
                    </div>
                </section>

                {recap === null ? (
                    <EmptyState
                        icon={CalendarRange}
                        title="Bulan ini di luar semester mana pun"
                        description={
                            semester
                                ? `Pilih bulan yang termasuk Semester ${semester.name} atau semester lain yang terdaftar.`
                                : 'Belum ada semester aktif. Hubungi Admin.'
                        }
                    />
                ) : (
                    <>
                        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                            <Stat
                                icon={Users}
                                label={
                                    canManage ? 'Asisten' : 'Baris rekap saya'
                                }
                                value={recap.totals.assistants}
                            />
                            <Stat
                                icon={CalendarRange}
                                label="Total hari hadir"
                                value={recap.totals.days}
                            />
                            <Stat
                                icon={Clock}
                                label="Total jam"
                                value={recap.totals.hours}
                                hint="Dibulatkan per asisten, seperti template"
                            />
                            {signatures && (
                                <Stat
                                    icon={PenLine}
                                    label="Tanda tangan terunggah"
                                    value={`${signatures.signed} / ${signatures.total}`}
                                />
                            )}
                        </div>

                        {checks && <RecapChecksPanel checks={checks} />}

                        <RecapLegend />
                        <RecapGrid recap={recap} manager={canManage} />
                    </>
                )}
            </div>
        </>
    );
}
