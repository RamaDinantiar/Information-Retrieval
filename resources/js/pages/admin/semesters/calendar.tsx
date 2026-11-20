import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, CalendarDays, Pencil, Plus } from 'lucide-react';
import AcademicCalendarController from '@/actions/App/Http/Controllers/Admin/AcademicCalendarController';
import SemesterController from '@/actions/App/Http/Controllers/Admin/SemesterController';
import { ConfirmDeleteDialog } from '@/components/confirm-delete-dialog';
import { CalendarEventDialog } from '@/components/calendar/calendar-event-dialog';
import type { CalendarEventRow } from '@/components/calendar/calendar-event-dialog';
import { EmptyState } from '@/components/empty-state';
import { PageHeader } from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatDate, formatDateRange } from '@/lib/datetime';
import { cn } from '@/lib/utils';

type Props = {
    semester: {
        id: number;
        name: string;
        startDate: string;
        endDate: string;
        editable: boolean;
    };
    events: CalendarEventRow[];
    types: { value: string; label: string }[];
};

const WEEKDAYS = ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'];
const pad = (n: number) => String(n).padStart(2, '0');
const monthName = new Intl.DateTimeFormat('id-ID', {
    month: 'long',
    year: 'numeric',
});

const TYPE_LABEL: Record<CalendarEventRow['type'], string> = {
    national_holiday: 'Libur Nasional',
    uts: 'UTS',
    uas: 'UAS',
};

/** Bulan-bulan (YYYY-MM) yang disentuh rentang semester. */
function monthsBetween(start: string, end: string): string[] {
    const months: string[] = [];
    let [y, m] = start.split('-').map(Number);
    const [ey, em] = end.split('-').map(Number);

    while (y < ey || (y === ey && m <= em)) {
        months.push(`${y}-${pad(m)}`);
        m += 1;

        if (m > 12) {
            m = 1;
            y += 1;
        }
    }

    return months;
}

function MonthGrid({
    month,
    events,
    semester,
}: {
    month: string;
    events: CalendarEventRow[];
    semester: Props['semester'];
}) {
    const [y, m] = month.split('-').map(Number);
    const offset = (new Date(y, m - 1, 1).getDay() + 6) % 7;
    const total = new Date(y, m, 0).getDate();
    const cells = [
        ...Array.from({ length: offset }, () => null),
        ...Array.from({ length: total }, (_, i) => i + 1),
    ];

    return (
        <section
            aria-label={monthName.format(new Date(y, m - 1, 1))}
            className="border bg-card p-3"
        >
            <h3 className="mb-2 text-sm font-bold capitalize">
                {monthName.format(new Date(y, m - 1, 1))}
            </h3>
            <div className="grid grid-cols-7 gap-px text-center text-[11px]">
                {WEEKDAYS.map((d) => (
                    <span key={d} className="py-1 text-muted-foreground">
                        {d}
                    </span>
                ))}
                {cells.map((day, i) => {
                    if (day === null) {
                        return <span key={`b${i}`} />;
                    }

                    const iso = `${month}-${pad(day)}`;
                    const outside =
                        iso < semester.startDate || iso > semester.endDate;
                    const sunday = (offset + day - 1) % 7 === 6;
                    const covering = events.filter(
                        (e) => e.dateFrom <= iso && iso <= e.dateTo,
                    );
                    const holiday = covering.find(
                        (e) => e.type === 'national_holiday',
                    );
                    const exam = covering.find(
                        (e) => e.type !== 'national_holiday',
                    );
                    const hit = holiday ?? (sunday ? undefined : exam);
                    const kind = holiday
                        ? 'ngawas-holiday'
                        : sunday
                          ? 'ngawas-sunday'
                          : exam
                            ? 'ngawas-exam'
                            : '';

                    return (
                        <span
                            key={iso}
                            title={
                                hit
                                    ? `${TYPE_LABEL[hit.type]} · ${hit.label}`
                                    : sunday
                                      ? 'Minggu'
                                      : undefined
                            }
                            className={cn(
                                'flex h-7 items-center justify-center',
                                kind,
                                outside && 'opacity-30',
                            )}
                        >
                            {day}
                            {hit && (
                                <span className="sr-only">
                                    , {TYPE_LABEL[hit.type]}: {hit.label}
                                </span>
                            )}
                        </span>
                    );
                })}
            </div>
        </section>
    );
}

/** Admin › Semester › Kalender Akademik (FR-RJN-04, US-RJN-05). */
export default function SemesterCalendar({ semester, events, types }: Props) {
    const months = monthsBetween(semester.startDate, semester.endDate);

    return (
        <>
            <Head title={`Kalender ${semester.name}`} />
            <div className="flex flex-col gap-7 p-4 md:p-6 lg:p-8">
                <PageHeader
                    title="Kalender Akademik"
                    description={`Semester ${semester.name} · ${formatDateRange(semester.startDate, semester.endDate)}. Penanda ini mewarnai kolom tanggal di Rekap Jam Ngawas; hari Minggu terdeteksi otomatis.`}
                    actions={
                        <>
                            <Button variant="outline" asChild>
                                <Link href={SemesterController.index.url()}>
                                    <ArrowLeft /> Semester
                                </Link>
                            </Button>
                            {semester.editable && (
                                <CalendarEventDialog
                                    semester={semester}
                                    types={types}
                                    trigger={
                                        <Button>
                                            <Plus /> Tambah Penanda
                                        </Button>
                                    }
                                />
                            )}
                        </>
                    }
                />

                {!semester.editable && (
                    <p
                        role="status"
                        className="border border-warning/30 bg-warning-soft px-4 py-3 text-sm text-warning"
                    >
                        Semester arsip bersifat read-only.
                    </p>
                )}

                <ul
                    aria-label="Keterangan warna"
                    className="flex flex-wrap gap-x-5 gap-y-2 text-xs"
                >
                    {[
                        ['ngawas-sunday', 'Minggu (otomatis)'],
                        ['ngawas-holiday', 'Libur Nasional'],
                        ['ngawas-exam', 'UTS / UAS'],
                    ].map(([cls, label]) => (
                        <li key={label} className="flex items-center gap-2">
                            <span
                                aria-hidden
                                className={cn(
                                    'size-4 border border-black/20',
                                    cls,
                                )}
                            />
                            {label}
                        </li>
                    ))}
                </ul>

                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    {months.map((month) => (
                        <MonthGrid
                            key={month}
                            month={month}
                            events={events}
                            semester={semester}
                        />
                    ))}
                </div>

                {events.length === 0 ? (
                    <EmptyState
                        icon={CalendarDays}
                        title="Belum ada penanda kalender"
                        description="Tambahkan libur nasional serta periode UTS dan UAS agar kolom tanggal rekap ngawas terwarnai otomatis."
                    />
                ) : (
                    <div className="overflow-x-auto border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Tanggal</TableHead>
                                    <TableHead>Jenis</TableHead>
                                    <TableHead>Keterangan</TableHead>
                                    {semester.editable && (
                                        <TableHead className="text-right">
                                            Aksi
                                        </TableHead>
                                    )}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {events.map((event) => (
                                    <TableRow key={event.id}>
                                        <TableCell className="font-mono text-sm whitespace-nowrap">
                                            {event.dateFrom === event.dateTo
                                                ? formatDate(event.dateFrom)
                                                : formatDateRange(
                                                      event.dateFrom,
                                                      event.dateTo,
                                                  )}
                                        </TableCell>
                                        <TableCell>
                                            <Badge
                                                variant={
                                                    event.type ===
                                                    'national_holiday'
                                                        ? 'destructive'
                                                        : 'muted'
                                                }
                                            >
                                                {TYPE_LABEL[event.type]}
                                            </Badge>
                                        </TableCell>
                                        <TableCell>{event.label}</TableCell>
                                        {semester.editable && (
                                            <TableCell>
                                                <div className="flex justify-end gap-2">
                                                    <CalendarEventDialog
                                                        semester={semester}
                                                        event={event}
                                                        types={types}
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
                                                        action={AcademicCalendarController.destroy.form(
                                                            event.id,
                                                        )}
                                                        title="Hapus penanda kalender?"
                                                        description={`“${event.label}” akan dihapus dari kalender. Rekap yang sudah Final tidak berubah.`}
                                                    />
                                                </div>
                                            </TableCell>
                                        )}
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                )}
            </div>
        </>
    );
}
