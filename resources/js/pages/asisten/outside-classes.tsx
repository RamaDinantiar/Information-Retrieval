import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowLeft,
    Check,
    Copy,
    Download,
    Pencil,
    Plus,
    School,
} from 'lucide-react';
import { useState } from 'react';
import NgawasRecapController from '@/actions/App/Http/Controllers/Asisten/NgawasRecapController';
import OutsideClassController from '@/actions/App/Http/Controllers/Asisten/OutsideClassController';
import { ConfirmDeleteDialog } from '@/components/confirm-delete-dialog';
import { EmptyState } from '@/components/empty-state';
import {
    DAYS,
    OutsideClassDialog,
} from '@/components/ngawas-recap/outside-class-dialog';
import type { OutsideClassRow } from '@/components/ngawas-recap/outside-class-dialog';
import { PageHeader } from '@/components/page-header';
import { SelectField } from '@/components/select-field';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { EXECUTION } from '@/lib/execution-status';
import type { ExecutionStatus } from '@/types';

type Monthly = {
    id: number;
    date: string;
    class: string;
    originLab: string;
    assistants: string[];
    confirmed: boolean;
    reportStatus: string;
    executionStatus: ExecutionStatus;
};

type Props = {
    filters: { semester: number | null; month: string };
    semesters: { id: number; name: string; active: boolean }[];
    canManage: boolean;
    assistantOptions: { id: number; name: string }[];
    classes: OutsideClassRow[];
    monthly: Monthly[];
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
const REPORT_STATUS: Record<string, string> = {
    draft: 'Draft',
    submitted: 'Diajukan',
    verified: 'Diverifikasi',
    returned: 'Dikembalikan',
};
const shortDate = (iso: string) =>
    new Date(`${iso}T00:00:00`).toLocaleDateString('id-ID', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
const schedule = (c: OutsideClassRow) =>
    c.dayOfWeek
        ? `${DAYS[c.dayOfWeek - 1]}${c.startTime ? ` ${c.startTime}–${c.endTime}` : ''}`
        : '—';

/** Daftar ringkas untuk dilaporkan ke kampus dan lab terkait (US-RJN-06.3). */
function monthlyTable(rows: Monthly[]): string[][] {
    return [
        ['Tanggal', 'Asisten', 'Kelas', 'Lab asal', 'Konfirmasi'],
        ...rows.map((r) => [
            shortDate(r.date),
            r.assistants.join(', '),
            r.class,
            r.originLab,
            r.confirmed ? 'Sudah dikonfirmasi' : 'Belum dikonfirmasi',
        ]),
    ];
}

const csvCell = (v: string) => `"${v.replaceAll('"', '""')}"`;

/** Asisten › Rekap Jam Ngawas › Kelas di Luar Lab IR (US-RJN-06). */
export default function OutsideClasses({
    filters,
    semesters,
    canManage,
    assistantOptions,
    classes,
    monthly,
}: Props) {
    const [year, month] = filters.month.split('-').map(Number);
    const [copied, setCopied] = useState(false);
    const semester = semesters.find((s) => s.id === filters.semester);

    const go = (next: Partial<{ semester: number | null; month: string }>) =>
        router.get(
            OutsideClassController.index.url({
                query: { ...filters, ...next },
            }),
            {},
            { preserveScroll: true },
        );
    const setMonth = (y: number, m: number) =>
        go({ month: `${y}-${String(m).padStart(2, '0')}` });

    const copy = async () => {
        await navigator.clipboard.writeText(
            monthlyTable(monthly)
                .map((r) => r.join('\t'))
                .join('\n'),
        );
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
    };
    const download = () => {
        const csv = monthlyTable(monthly)
            .map((r) => r.map(csvCell).join(','))
            .join('\r\n');
        const url = URL.createObjectURL(
            new Blob([`﻿${csv}`], { type: 'text/csv;charset=utf-8' }),
        );
        const a = document.createElement('a');
        a.href = url;
        a.download = `kelas-di-luar-lab-ir-${filters.month}.csv`;
        a.click();
        URL.revokeObjectURL(url);
    };

    return (
        <>
            <Head title="Kelas di Luar Lab IR" />
            <div className="flex flex-col gap-7 p-4 md:p-6 lg:p-8">
                <PageHeader
                    title="Kelas di Luar Lab IR"
                    description="Kelas lab lain yang diawas asisten IR. Tercatat terpisah dari mata kuliah Lab IR, tetap masuk Rekap Jam Ngawas dengan warna oranye."
                    actions={
                        <>
                            <Button variant="outline" asChild>
                                <Link href={NgawasRecapController.index.url()}>
                                    <ArrowLeft /> Rekap Jam Ngawas
                                </Link>
                            </Button>
                            {canManage && semester && (
                                <OutsideClassDialog
                                    semesterId={semester.id}
                                    assistantOptions={assistantOptions}
                                    trigger={
                                        <Button>
                                            <Plus /> Tambah Kelas
                                        </Button>
                                    }
                                />
                            )}
                        </>
                    }
                />

                <section
                    aria-label="Semester"
                    className="flex flex-wrap items-end gap-4 border bg-card p-4"
                >
                    <label className="grid gap-2 text-sm font-medium">
                        Semester
                        <SelectField
                            value={filters.semester ?? ''}
                            onValueChange={(v) => go({ semester: Number(v) })}
                            options={semesters.map((s) => ({
                                value: s.id,
                                label: s.active ? `${s.name} (aktif)` : s.name,
                            }))}
                        />
                    </label>
                    {!canManage && (
                        <p className="text-xs text-muted-foreground">
                            Anda hanya melihat kelas yang ditugaskan kepada
                            Anda. Pengelolaan oleh Sekretaris/Bendahara dan
                            Admin.
                        </p>
                    )}
                </section>

                {classes.length === 0 ? (
                    <EmptyState
                        icon={School}
                        title="Belum ada kelas di luar Lab IR"
                        description={
                            canManage
                                ? 'Tambahkan kelas lab lain yang diawas asisten IR agar bisa dipilih di laporan ngawas.'
                                : 'Belum ada kelas di luar Lab IR yang ditugaskan kepada Anda pada semester ini.'
                        }
                    />
                ) : (
                    <>
                        <div className="hidden overflow-x-auto border md:block">
                            <table className="w-full min-w-[56rem] text-sm">
                                <thead className="bg-primary-50 text-left text-xs">
                                    <tr>
                                        <th className="px-3 py-2">
                                            Mata kuliah
                                        </th>
                                        <th className="px-3 py-2">Kelas</th>
                                        <th className="px-3 py-2">SKS</th>
                                        <th className="px-3 py-2">Lab asal</th>
                                        <th className="px-3 py-2">Jadwal</th>
                                        <th className="px-3 py-2">Asisten</th>
                                        <th className="px-3 py-2">
                                            Konfirmasi
                                        </th>
                                        {canManage && (
                                            <th className="px-3 py-2 text-right">
                                                Aksi
                                            </th>
                                        )}
                                    </tr>
                                </thead>
                                <tbody>
                                    {classes.map((c) => (
                                        <tr
                                            key={c.id}
                                            className="border-t align-top"
                                        >
                                            <td className="px-3 py-2 font-medium">
                                                {c.courseName}
                                            </td>
                                            <td className="px-3 py-2">
                                                {c.className}
                                            </td>
                                            <td className="px-3 py-2">
                                                {c.credits}
                                            </td>
                                            <td className="px-3 py-2">
                                                {c.originLab}
                                            </td>
                                            <td className="px-3 py-2 whitespace-nowrap">
                                                {schedule(c)}
                                            </td>
                                            <td className="px-3 py-2">
                                                {c.assistants
                                                    .map((a) => a.name)
                                                    .join(', ')}
                                            </td>
                                            <td className="px-3 py-2">
                                                <Badge
                                                    variant={
                                                        c.confirmed
                                                            ? 'success'
                                                            : 'warning'
                                                    }
                                                >
                                                    {c.confirmed
                                                        ? 'Sudah dikonfirmasi'
                                                        : 'Belum dikonfirmasi'}
                                                </Badge>
                                                {c.confirmationNote && (
                                                    <span className="mt-1 block text-xs text-muted-foreground">
                                                        {c.confirmationNote}
                                                    </span>
                                                )}
                                            </td>
                                            {canManage && (
                                                <td className="px-3 py-2">
                                                    <div className="flex justify-end gap-2">
                                                        <OutsideClassDialog
                                                            semesterId={
                                                                filters.semester ??
                                                                0
                                                            }
                                                            row={c}
                                                            assistantOptions={
                                                                assistantOptions
                                                            }
                                                            trigger={
                                                                <Button
                                                                    size="sm"
                                                                    variant="outline"
                                                                >
                                                                    <Pencil />{' '}
                                                                    Ubah
                                                                </Button>
                                                            }
                                                        />
                                                        <ConfirmDeleteDialog
                                                            action={OutsideClassController.destroy.form(
                                                                c.id,
                                                            )}
                                                            title="Hapus kelas di luar Lab IR?"
                                                            description={`${c.courseName} (${c.className}) akan dihapus. Kelas yang sudah dipakai di laporan ngawas tidak dapat dihapus.`}
                                                        />
                                                    </div>
                                                </td>
                                            )}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        <ul className="grid gap-3 md:hidden">
                            {classes.map((c) => (
                                <li
                                    key={c.id}
                                    className="grid gap-2 border bg-card p-4 text-sm"
                                >
                                    <div className="flex items-start justify-between gap-2">
                                        <p className="font-bold">
                                            {c.courseName} ({c.className})
                                        </p>
                                        <Badge
                                            variant={
                                                c.confirmed
                                                    ? 'success'
                                                    : 'warning'
                                            }
                                        >
                                            {c.confirmed
                                                ? 'Terkonfirmasi'
                                                : 'Belum'}
                                        </Badge>
                                    </div>
                                    <p className="text-xs text-muted-foreground">
                                        {c.originLab} · {c.credits} SKS ·{' '}
                                        {schedule(c)}
                                    </p>
                                    <p className="text-xs">
                                        {c.assistants
                                            .map((a) => a.name)
                                            .join(', ')}
                                    </p>
                                    {canManage && (
                                        <div className="flex gap-2">
                                            <OutsideClassDialog
                                                semesterId={
                                                    filters.semester ?? 0
                                                }
                                                row={c}
                                                assistantOptions={
                                                    assistantOptions
                                                }
                                                trigger={
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                    >
                                                        <Pencil /> Ubah
                                                    </Button>
                                                }
                                            />
                                            <ConfirmDeleteDialog
                                                action={OutsideClassController.destroy.form(
                                                    c.id,
                                                )}
                                                title="Hapus kelas di luar Lab IR?"
                                                description={`${c.courseName} (${c.className}) akan dihapus.`}
                                            />
                                        </div>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </>
                )}

                <section
                    aria-labelledby="monthly-title"
                    className="grid gap-4 border bg-card p-5"
                >
                    <div className="flex flex-wrap items-end justify-between gap-4">
                        <div>
                            <h2
                                id="monthly-title"
                                className="text-h2 font-bold"
                            >
                                Daftar Ringkas Bulanan
                            </h2>
                            <p className="text-xs text-muted-foreground">
                                Untuk dilaporkan ke kampus dan dikonfirmasi ke
                                lab terkait.
                            </p>
                        </div>
                        <div className="flex flex-wrap items-end gap-3">
                            <label className="grid gap-1 text-xs font-medium">
                                Bulan
                                <SelectField
                                    value={month}
                                    onValueChange={(v) =>
                                        setMonth(year, Number(v))
                                    }
                                    options={MONTHS.map((name, i) => ({
                                        value: i + 1,
                                        label: name,
                                    }))}
                                />
                            </label>
                            <label className="grid gap-1 text-xs font-medium">
                                Tahun
                                <SelectField
                                    value={year}
                                    onValueChange={(v) =>
                                        setMonth(Number(v), month)
                                    }
                                    options={[year - 1, year, year + 1].map(
                                        (y) => ({ value: y, label: y }),
                                    )}
                                />
                            </label>
                            <Button
                                variant="outline"
                                size="sm"
                                disabled={monthly.length === 0}
                                onClick={copy}
                            >
                                {copied ? <Check /> : <Copy />}{' '}
                                {copied ? 'Tersalin' : 'Salin'}
                            </Button>
                            <Button
                                variant="outline"
                                size="sm"
                                disabled={monthly.length === 0}
                                onClick={download}
                            >
                                <Download /> Unduh CSV
                            </Button>
                        </div>
                    </div>

                    {monthly.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            Tidak ada laporan kelas di luar Lab IR pada bulan
                            ini.
                        </p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[40rem] text-sm">
                                <thead className="text-left text-xs text-muted-foreground">
                                    <tr className="border-b">
                                        <th className="py-2 pr-3">Tanggal</th>
                                        <th className="py-2 pr-3">Asisten</th>
                                        <th className="py-2 pr-3">Kelas</th>
                                        <th className="py-2 pr-3">Lab asal</th>
                                        <th className="py-2 pr-3">Laporan</th>
                                        <th className="py-2">Konfirmasi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {monthly.map((r) => (
                                        <tr
                                            key={r.id}
                                            className="border-b last:border-b-0"
                                        >
                                            <td className="py-2 pr-3 whitespace-nowrap">
                                                {shortDate(r.date)}
                                            </td>
                                            <td className="py-2 pr-3">
                                                {r.assistants.join(', ')}
                                            </td>
                                            <td className="py-2 pr-3">
                                                {r.class}
                                            </td>
                                            <td className="py-2 pr-3">
                                                {r.originLab}
                                            </td>
                                            <td className="py-2 pr-3">
                                                {REPORT_STATUS[
                                                    r.reportStatus
                                                ] ?? r.reportStatus}
                                                {r.executionStatus !==
                                                    'held' && (
                                                    <Badge
                                                        className="ml-1"
                                                        variant={
                                                            EXECUTION[
                                                                r
                                                                    .executionStatus
                                                            ].variant
                                                        }
                                                    >
                                                        {
                                                            EXECUTION[
                                                                r
                                                                    .executionStatus
                                                            ].label
                                                        }
                                                    </Badge>
                                                )}
                                            </td>
                                            <td className="py-2">
                                                <Badge
                                                    variant={
                                                        r.confirmed
                                                            ? 'success'
                                                            : 'warning'
                                                    }
                                                >
                                                    {r.confirmed
                                                        ? 'Sudah dikonfirmasi'
                                                        : 'Belum dikonfirmasi'}
                                                </Badge>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>
            </div>
        </>
    );
}
