import { useForm } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import AcademicCalendarController from '@/actions/App/Http/Controllers/Admin/AcademicCalendarController';
import InputError from '@/components/input-error';
import { DateField } from '@/components/pickers/date-field';
import { SelectField } from '@/components/select-field';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

export type CalendarEventRow = {
    id: number;
    dateFrom: string;
    dateTo: string;
    type: 'national_holiday' | 'uts' | 'uas';
    label: string;
};

/** Tambah atau ubah satu rentang tanggal: Libur Nasional, UTS, atau UAS (FR-RJN-04). */
export function CalendarEventDialog({
    semester,
    event,
    types,
    trigger,
}: {
    semester: { id: number; name: string; startDate: string; endDate: string };
    /** Kosong = tambah baru. */
    event?: CalendarEventRow;
    types: { value: string; label: string }[];
    trigger: ReactNode;
}) {
    const [open, setOpen] = useState(false);
    const form = useForm({
        date_from: event?.dateFrom ?? '',
        date_to: event?.dateTo ?? '',
        type: event?.type ?? 'national_holiday',
        label: event?.label ?? '',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        const options = {
            preserveScroll: true,
            onSuccess: () => {
                setOpen(false);

                if (!event) {
                    form.reset();
                }
            },
        };

        if (event) {
            form.put(AcademicCalendarController.update.url(event.id), options);
        } else {
            form.post(
                AcademicCalendarController.store.url(semester.id),
                options,
            );
        }
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        {event
                            ? 'Ubah Penanda Kalender'
                            : 'Tambah Penanda Kalender'}
                    </DialogTitle>
                    <DialogDescription>
                        Tanggal harus berada dalam semester {semester.name}.
                        Hari Minggu terdeteksi otomatis, tidak perlu diisi.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="grid gap-5">
                    <div className="grid gap-2">
                        <Label htmlFor="cal-type">Jenis</Label>
                        <SelectField
                            id="cal-type"
                            value={form.data.type}
                            onValueChange={(v) =>
                                form.setData(
                                    'type',
                                    v as CalendarEventRow['type'],
                                )
                            }
                            options={types.map((t) => ({
                                value: t.value,
                                label: t.label,
                            }))}
                        />
                        <InputError message={form.errors.type} />
                    </div>
                    <div className="grid gap-5 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="cal-from">Tanggal mulai</Label>
                            <DateField
                                id="cal-from"
                                min={semester.startDate}
                                max={semester.endDate}
                                value={form.data.date_from}
                                onChange={(v) => {
                                    form.setData((d) => ({
                                        ...d,
                                        date_from: v,
                                        date_to:
                                            d.date_to && d.date_to >= v
                                                ? d.date_to
                                                : v,
                                    }));
                                }}
                                invalid={!!form.errors.date_from}
                            />
                            <InputError message={form.errors.date_from} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="cal-to">Tanggal selesai</Label>
                            <DateField
                                id="cal-to"
                                min={form.data.date_from || semester.startDate}
                                max={semester.endDate}
                                value={form.data.date_to}
                                onChange={(v) => form.setData('date_to', v)}
                                invalid={!!form.errors.date_to}
                            />
                            <InputError message={form.errors.date_to} />
                        </div>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="cal-label">Keterangan</Label>
                        <Input
                            id="cal-label"
                            required
                            maxLength={150}
                            value={form.data.label}
                            onChange={(e) =>
                                form.setData('label', e.target.value)
                            }
                            placeholder="Mis. Wafat Isa Almasih / UTS Genap"
                            aria-invalid={!!form.errors.label}
                        />
                        <InputError message={form.errors.label} />
                    </div>
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button type="button" variant="outline">
                                Batal
                            </Button>
                        </DialogClose>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <Spinner />}
                            {event ? 'Simpan Perubahan' : 'Tambah'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
