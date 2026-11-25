import { Link } from '@inertiajs/react';
import { ExternalLink } from 'lucide-react';
import { useState } from 'react';
import ReportController from '@/actions/App/Http/Controllers/Asisten/ReportController';
import { Badge } from '@/components/ui/badge';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { EXECUTION } from '@/lib/execution-status';
import { cn } from '@/lib/utils';
import type {
    NgawasRecapData,
    RecapAssistant,
    RecapCell,
    RecapColumn,
    RecapNote,
    RecapReport,
    RecapRow,
} from '@/types';
import { KIND_MARK } from './recap-legend';

/** Lebar kolom tetap (px) agar kolom nama & kelas bisa sticky. */
const W = { no: 40, name: 176, course: 232, day: 44 } as const;

const fmt = (hours: number) =>
    hours.toLocaleString('id-ID', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
const longDate = (iso: string) =>
    new Date(`${iso}T00:00:00`).toLocaleDateString('id-ID', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    });
const shortDate = (iso: string) =>
    new Date(`${iso}T00:00:00`).toLocaleDateString('id-ID', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });

const cellAt = (row: RecapRow, day: number): RecapCell | undefined =>
    (row.cells as Record<string, RecapCell>)[String(day)];
const reportsOf = (recap: NgawasRecapData) =>
    recap.reports as Record<string, RecapReport>;
const cellsOf = (row: RecapRow) => Object.values(row.cells) as RecapCell[];

type Picked = { cell: RecapCell; row: RecapRow; assistant: RecapAssistant };

function cellLabel({ cell, row, assistant }: Picked) {
    const parts = [
        assistant.name,
        row.label,
        shortDate(cell.date),
        EXECUTION[cell.status].label,
    ];

    if (cell.outsideLab) parts.push('di luar Lab IR');
    parts.push(cell.counts ? `${fmt(cell.hours)} jam` : 'tanpa jam');

    return parts.join(', ');
}

/** Isi sel: jam (dua desimal) + penanda huruf, atau ✕ bila ditiadakan. */
function CellFace({ cell }: { cell: RecapCell }) {
    return (
        <>
            {cell.counts ? fmt(cell.hours) : ''}
            {cell.kind && (
                <sup className="ml-px text-[9px] font-bold">
                    {KIND_MARK[cell.kind]}
                </sup>
            )}
        </>
    );
}

function columnClass(column: RecapColumn) {
    return column.kind ? `ngawas-${column.kind}` : '';
}

function NotesTable({ title, rows }: { title: string; rows: RecapNote[] }) {
    return (
        <section aria-label={title} className="border bg-card p-4">
            <h3 className="mb-2 text-sm font-bold">{title}</h3>
            {rows.length === 0 ? (
                <p className="text-xs text-muted-foreground">
                    Tidak ada pada bulan ini.
                </p>
            ) : (
                <div className="overflow-x-auto">
                    <table className="w-full min-w-[32rem] text-xs">
                        <thead>
                            <tr className="border-b text-left text-muted-foreground">
                                <th className="py-1 pr-3 font-medium">
                                    Tanggal
                                </th>
                                <th className="py-1 pr-3 font-medium">Kelas</th>
                                <th className="py-1 pr-3 font-medium">Jam</th>
                                <th className="py-1 font-medium">Keterangan</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((r, i) => (
                                <tr
                                    key={`${r.date}-${r.class}-${i}`}
                                    className="border-b last:border-b-0"
                                >
                                    <td className="py-1.5 pr-3 whitespace-nowrap">
                                        {shortDate(r.date)}
                                    </td>
                                    <td className="py-1.5 pr-3">{r.class}</td>
                                    <td className="py-1.5 pr-3 font-mono whitespace-nowrap">
                                        {r.time}
                                    </td>
                                    <td className="py-1.5">{r.note || '—'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </section>
    );
}

/** Dialog sumber sel: tanggal, kelas, status, tim jaga, dan tautan ke laporan. */
function CellDialog({
    picked,
    recap,
    manager,
    onClose,
}: {
    picked: Picked | null;
    recap: NgawasRecapData;
    manager: boolean;
    onClose: () => void;
}) {
    const reports = reportsOf(recap);

    return (
        <Dialog open={picked !== null} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="sm:max-w-lg">
                {picked && (
                    <>
                        <DialogHeader>
                            <DialogTitle>{picked.row.label}</DialogTitle>
                            <DialogDescription>
                                {picked.assistant.name} ·{' '}
                                {longDate(picked.cell.date)}
                            </DialogDescription>
                        </DialogHeader>
                        <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm">
                            <dt className="text-muted-foreground">Status</dt>
                            <dd>
                                <Badge
                                    variant={
                                        EXECUTION[picked.cell.status].variant
                                    }
                                >
                                    {EXECUTION[picked.cell.status].label}
                                </Badge>
                                {picked.cell.outsideLab && (
                                    <Badge variant="warning" className="ml-1">
                                        Di luar Lab IR
                                    </Badge>
                                )}
                            </dd>
                            <dt className="text-muted-foreground">Jam rekap</dt>
                            <dd>
                                {picked.cell.counts
                                    ? `${fmt(picked.cell.hours)} jam (${picked.cell.credits} SKS × 50 ÷ 60)`
                                    : 'Tidak dihitung'}
                            </dd>
                        </dl>
                        <h3 className="text-sm font-bold">Laporan sumber</h3>
                        <ul className="grid gap-2">
                            {picked.cell.reportIds.map((id) => {
                                const r = reports[String(id)];

                                if (!r) return null;

                                return (
                                    <li
                                        key={id}
                                        className="grid gap-1 border bg-muted/40 p-3 text-xs"
                                    >
                                        <span className="font-semibold">
                                            {r.class} · {r.start}–{r.end}
                                        </span>
                                        <span>
                                            Tim jaga: {r.team.join(', ')}
                                        </span>
                                        <Link
                                            href={ReportController.index.url({
                                                query: {
                                                    date: r.date,
                                                    tab: manager
                                                        ? 'all'
                                                        : 'mine',
                                                },
                                            })}
                                            className="inline-flex w-fit items-center gap-1 text-primary-700 underline"
                                        >
                                            Buka laporan
                                            <ExternalLink
                                                className="size-3"
                                                aria-hidden
                                            />
                                        </Link>
                                    </li>
                                );
                            })}
                        </ul>
                    </>
                )}
            </DialogContent>
        </Dialog>
    );
}

/** Grid bulanan (layar ≥ md) + daftar ringkas (ponsel) + tabel catatan (FR-RJN-13). */
export function RecapGrid({
    recap,
    manager,
}: {
    recap: NgawasRecapData;
    manager: boolean;
}) {
    const [picked, setPicked] = useState<Picked | null>(null);
    const stickyBase = 'sticky bg-card';

    return (
        <div className="grid gap-4">
            {/* Desktop / tablet */}
            <div className="hidden max-h-[70vh] overflow-auto border bg-card md:block">
                <table className="border-separate border-spacing-0 text-xs">
                    <caption className="sr-only">
                        Rekapitulasi kehadiran asisten, {recap.monthLabel}
                    </caption>
                    <thead>
                        <tr>
                            <th
                                rowSpan={2}
                                scope="col"
                                style={{ left: 0, width: W.no, minWidth: W.no }}
                                className="sticky top-0 z-30 border-r border-b bg-primary-100 px-1 py-2"
                            >
                                NO
                            </th>
                            <th
                                rowSpan={2}
                                scope="col"
                                style={{
                                    left: W.no,
                                    width: W.name,
                                    minWidth: W.name,
                                }}
                                className="sticky top-0 z-30 border-r border-b bg-primary-100 px-2 py-2 text-left"
                            >
                                NAMA MAHASISWA / NIM
                            </th>
                            <th
                                rowSpan={2}
                                scope="col"
                                style={{
                                    left: W.no + W.name,
                                    width: W.course,
                                    minWidth: W.course,
                                }}
                                className="sticky top-0 z-30 border-r border-b bg-primary-100 px-2 py-2 text-left"
                            >
                                MATA KULIAH
                            </th>
                            <th
                                colSpan={recap.columns.length}
                                scope="colgroup"
                                className="sticky top-0 z-20 border-r border-b bg-primary-50 px-2 py-1 text-center"
                            >
                                TANGGAL, {recap.monthLabel}
                            </th>
                            <th
                                rowSpan={2}
                                scope="col"
                                className="sticky top-0 z-20 w-20 min-w-20 border-r border-b bg-primary-100 px-1 py-2"
                            >
                                TOTAL HARI
                            </th>
                            <th
                                rowSpan={2}
                                scope="col"
                                className="sticky top-0 z-20 w-20 min-w-20 border-b bg-primary-100 px-1 py-2"
                            >
                                TOTAL JAM
                            </th>
                        </tr>
                        <tr>
                            {recap.columns.map((c) => (
                                <th
                                    key={c.day}
                                    scope="col"
                                    title={
                                        c.label
                                            ? `${shortDate(c.date)} · ${c.label}`
                                            : shortDate(c.date)
                                    }
                                    style={{ width: W.day, minWidth: W.day }}
                                    className={cn(
                                        'sticky top-[27px] z-20 border-r border-b px-0.5 py-1 text-center',
                                        columnClass(c) || 'bg-primary-50',
                                    )}
                                >
                                    <span className="block text-[9px] font-normal">
                                        {c.weekday}
                                    </span>
                                    {c.day}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {recap.assistants.map((assistant, ai) =>
                            assistant.rows.map((row, ri) => (
                                <tr key={`${assistant.userId}-${row.key}`}>
                                    {ri === 0 && (
                                        <>
                                            <td
                                                rowSpan={assistant.rows.length}
                                                style={{ left: 0 }}
                                                className={cn(
                                                    stickyBase,
                                                    'z-10 border-r border-b px-1 text-center align-top',
                                                )}
                                            >
                                                {ai + 1}
                                            </td>
                                            <td
                                                rowSpan={assistant.rows.length}
                                                style={{ left: W.no }}
                                                className={cn(
                                                    stickyBase,
                                                    'z-10 border-r border-b px-2 py-1 align-top',
                                                )}
                                            >
                                                <span className="block font-semibold">
                                                    {assistant.name}
                                                </span>
                                                <span className="font-mono text-[10px] text-muted-foreground">
                                                    {assistant.nim}
                                                </span>
                                                {!assistant.hasSignature && (
                                                    <span className="mt-1 block text-[10px] text-warning">
                                                        Tanda tangan belum ada
                                                    </span>
                                                )}
                                            </td>
                                        </>
                                    )}
                                    <td
                                        style={{ left: W.no + W.name }}
                                        className={cn(
                                            stickyBase,
                                            'z-10 border-r border-b px-2 py-1',
                                            row.outside && 'italic',
                                        )}
                                    >
                                        {row.label}
                                        {row.outside && (
                                            <span className="ml-1 text-[10px] text-muted-foreground">
                                                (lab lain)
                                            </span>
                                        )}
                                    </td>
                                    {recap.columns.map((c) => {
                                        const cell = cellAt(row, c.day);

                                        if (!cell) {
                                            return (
                                                <td
                                                    key={c.day}
                                                    className={cn(
                                                        'border-r border-b',
                                                        columnClass(c),
                                                    )}
                                                />
                                            );
                                        }

                                        const pick = {
                                            cell,
                                            row,
                                            assistant,
                                        };

                                        return (
                                            <td
                                                key={c.day}
                                                title={`${cellLabel(pick)}`}
                                                className={cn(
                                                    'border-r border-b p-0 text-center',
                                                    cell.kind
                                                        ? `ngawas-${cell.kind}`
                                                        : columnClass(c),
                                                )}
                                            >
                                                <button
                                                    type="button"
                                                    aria-label={cellLabel(pick)}
                                                    onClick={() =>
                                                        setPicked(pick)
                                                    }
                                                    className="h-full w-full px-0.5 py-1.5 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none focus-visible:ring-inset"
                                                >
                                                    <CellFace cell={cell} />
                                                </button>
                                            </td>
                                        );
                                    })}
                                    <td className="border-r border-b px-1 text-center font-mono">
                                        {row.placeholder ? '' : row.totalDays}
                                    </td>
                                    {ri === 0 && (
                                        <td
                                            rowSpan={assistant.rows.length}
                                            title={`Jam sebenarnya ${fmt(assistant.hoursRaw)}`}
                                            className="border-b px-1 text-center align-middle font-mono font-bold"
                                        >
                                            {assistant.hours}
                                        </td>
                                    )}
                                </tr>
                            )),
                        )}
                    </tbody>
                    <tfoot>
                        <tr className="font-bold">
                            <th
                                colSpan={3 + recap.columns.length}
                                scope="row"
                                className="sticky left-0 bg-primary-100 px-3 py-2 text-right"
                            >
                                TOTAL
                            </th>
                            <td className="border-l bg-primary-100 px-1 text-center font-mono">
                                {recap.totals.days}
                            </td>
                            <td
                                title={`Jam sebenarnya ${fmt(recap.totals.hoursRaw)}`}
                                className="bg-primary-100 px-1 text-center font-mono"
                            >
                                {recap.totals.hours}
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            {/* Ponsel: daftar per asisten dan kelas */}
            <div className="grid gap-3 md:hidden">
                {recap.assistants.map((assistant) => (
                    <article
                        key={assistant.userId}
                        className="grid gap-3 border bg-card p-4"
                    >
                        <header className="flex items-start justify-between gap-3">
                            <div>
                                <h3 className="text-sm font-bold">
                                    {assistant.name}
                                </h3>
                                <p className="font-mono text-[11px] text-muted-foreground">
                                    {assistant.nim}
                                </p>
                            </div>
                            <p className="text-right text-xs">
                                <span className="block font-bold">
                                    {assistant.hours} jam
                                </span>
                                {assistant.totalDays} hari
                            </p>
                        </header>
                        <ul className="grid gap-2">
                            {assistant.rows.map((row) => (
                                <li
                                    key={row.key}
                                    className="grid gap-1.5 border-t pt-2"
                                >
                                    <p className="flex justify-between gap-2 text-xs font-semibold">
                                        <span>{row.label}</span>
                                        {!row.placeholder && (
                                            <span className="font-normal text-muted-foreground">
                                                {row.totalDays} hari ·{' '}
                                                {fmt(row.hours)} jam
                                            </span>
                                        )}
                                    </p>
                                    <div className="flex flex-wrap gap-1">
                                        {cellsOf(row).map((cell) => {
                                            const pick = {
                                                cell,
                                                row,
                                                assistant,
                                            };

                                            return (
                                                <button
                                                    key={cell.day}
                                                    type="button"
                                                    aria-label={cellLabel(pick)}
                                                    onClick={() =>
                                                        setPicked(pick)
                                                    }
                                                    className={cn(
                                                        'min-w-9 border px-1.5 py-1 text-xs',
                                                        cell.kind
                                                            ? `ngawas-${cell.kind}`
                                                            : 'bg-muted',
                                                    )}
                                                >
                                                    {cell.day}
                                                    {cell.kind && (
                                                        <sup className="ml-px text-[9px] font-bold">
                                                            {
                                                                KIND_MARK[
                                                                    cell.kind
                                                                ]
                                                            }
                                                        </sup>
                                                    )}
                                                </button>
                                            );
                                        })}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </article>
                ))}
                <p className="text-right text-sm font-bold">
                    Total: {recap.totals.days} hari · {recap.totals.hours} jam
                </p>
            </div>

            <div className="grid gap-4 lg:grid-cols-2">
                <NotesTable title="Kelas Tambahan" rows={recap.additional} />
                <NotesTable title="Kelas Pengganti" rows={recap.replacements} />
            </div>

            <CellDialog
                picked={picked}
                recap={recap}
                manager={manager}
                onClose={() => setPicked(null)}
            />
        </div>
    );
}
