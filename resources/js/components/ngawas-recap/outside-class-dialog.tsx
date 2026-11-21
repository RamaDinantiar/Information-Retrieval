import { useForm } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import OutsideClassController from '@/actions/App/Http/Controllers/Asisten/OutsideClassController';
import InputError from '@/components/input-error';
import { TimeField } from '@/components/pickers/time-field';
import { SelectField } from '@/components/select-field';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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

export const DAYS = [
    'Senin',
    'Selasa',
    'Rabu',
    'Kamis',
    'Jumat',
    'Sabtu',
    'Minggu',
];

export type OutsideClassRow = {
    id: number;
    courseName: string;
    className: string;
    credits: number;
    originLab: string;
    dayOfWeek: number | null;
    startTime: string | null;
    endTime: string | null;
    assistants: { id: number; name: string }[];
    confirmed: boolean;
    confirmedAt: string | null;
    confirmationNote: string | null;
};

/** Tambah atau ubah Kelas di Luar Lab IR (US-RJN-06, PRD 3.6). */
export function OutsideClassDialog({
    semesterId,
    row,
    assistantOptions,
    trigger,
}: {
    semesterId: number;
    /** Kosong = tambah baru. */
    row?: OutsideClassRow;
    assistantOptions: { id: number; name: string }[];
    trigger: ReactNode;
}) {
    const [open, setOpen] = useState(false);
    const form = useForm({
        course_name: row?.courseName ?? '',
        class_name: row?.className ?? '',
        credits: String(row?.credits ?? 2),
        origin_lab: row?.originLab ?? '',
        day_of_week: row?.dayOfWeek ? String(row.dayOfWeek) : '',
        start_time: row?.startTime ?? '',
        end_time: row?.endTime ?? '',
        assistant_ids: row?.assistants.map((a) => a.id) ?? ([] as number[]),
        lab_confirmed: row?.confirmed ?? false,
        lab_confirmation_note: row?.confirmationNote ?? '',
    });

    const toggle = (id: number, on: boolean) =>
        form.setData(
            'assistant_ids',
            on
                ? [...form.data.assistant_ids, id]
                : form.data.assistant_ids.filter((x) => x !== id),
        );

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        const options = {
            preserveScroll: true,
            onSuccess: () => {
                setOpen(false);

                if (!row) {
                    form.reset();
                }
            },
        };

        if (row) {
            form.put(OutsideClassController.update.url(row.id), options);
        } else {
            form.transform((data) => ({ ...data, semester_id: semesterId }));
            form.post(OutsideClassController.store.url(), options);
        }
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent className="max-h-[92svh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>
                        {row
                            ? 'Ubah Kelas di Luar Lab IR'
                            : 'Tambah Kelas di Luar Lab IR'}
                    </DialogTitle>
                    <DialogDescription>
                        Kelas lab lain yang diawas asisten IR. Barisnya muncul
                        di Rekap Jam Ngawas dengan sel berwarna oranye.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="grid gap-4">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="oc-course">Mata kuliah</Label>
                            <Input
                                id="oc-course"
                                required
                                maxLength={150}
                                value={form.data.course_name}
                                onChange={(e) =>
                                    form.setData('course_name', e.target.value)
                                }
                                aria-invalid={!!form.errors.course_name}
                            />
                            <InputError message={form.errors.course_name} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="oc-class">Nama kelas</Label>
                            <Input
                                id="oc-class"
                                required
                                maxLength={50}
                                value={form.data.class_name}
                                onChange={(e) =>
                                    form.setData('class_name', e.target.value)
                                }
                                placeholder="Mis. A atau KR"
                                aria-invalid={!!form.errors.class_name}
                            />
                            <InputError message={form.errors.class_name} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="oc-credits">SKS</Label>
                            <SelectField
                                id="oc-credits"
                                value={form.data.credits}
                                onValueChange={(v) =>
                                    form.setData('credits', v)
                                }
                                options={[1, 2, 3, 4, 5, 6].map((n) => ({
                                    value: String(n),
                                    label: `${n} SKS`,
                                }))}
                            />
                            <InputError message={form.errors.credits} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="oc-lab">Lab / ruang asal</Label>
                            <Input
                                id="oc-lab"
                                required
                                maxLength={100}
                                value={form.data.origin_lab}
                                onChange={(e) =>
                                    form.setData('origin_lab', e.target.value)
                                }
                                aria-invalid={!!form.errors.origin_lab}
                            />
                            <InputError message={form.errors.origin_lab} />
                        </div>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-3">
                        <div className="grid gap-2">
                            <Label htmlFor="oc-day">Hari (opsional)</Label>
                            <SelectField
                                id="oc-day"
                                value={form.data.day_of_week}
                                onValueChange={(v) =>
                                    form.setData('day_of_week', v)
                                }
                                options={[
                                    { value: '', label: '—' },
                                    ...DAYS.map((d, i) => ({
                                        value: String(i + 1),
                                        label: d,
                                    })),
                                ]}
                            />
                            <InputError message={form.errors.day_of_week} />
                        </div>
                        <div className="grid gap-2">
                            <span className="text-sm font-medium">
                                Jam mulai
                            </span>
                            <TimeField
                                label="Jam mulai"
                                value={form.data.start_time}
                                onChange={(v) => form.setData('start_time', v)}
                                invalid={!!form.errors.start_time}
                            />
                            <InputError message={form.errors.start_time} />
                        </div>
                        <div className="grid gap-2">
                            <span className="text-sm font-medium">
                                Jam selesai
                            </span>
                            <TimeField
                                label="Jam selesai"
                                value={form.data.end_time}
                                onChange={(v) => form.setData('end_time', v)}
                                invalid={!!form.errors.end_time}
                            />
                            <InputError message={form.errors.end_time} />
                        </div>
                    </div>

                    <fieldset className="grid gap-2 border p-3">
                        <legend className="px-1 text-sm font-medium">
                            Asisten yang ditugaskan
                        </legend>
                        <div className="grid max-h-40 gap-1.5 overflow-y-auto sm:grid-cols-2">
                            {assistantOptions.map((a) => (
                                <label
                                    key={a.id}
                                    className="flex items-center gap-2 text-sm"
                                >
                                    <Checkbox
                                        checked={form.data.assistant_ids.includes(
                                            a.id,
                                        )}
                                        onCheckedChange={(v) =>
                                            toggle(a.id, v === true)
                                        }
                                    />
                                    {a.name}
                                </label>
                            ))}
                        </div>
                        <InputError
                            message={
                                form.errors.assistant_ids ??
                                Object.entries(form.errors).find(([k]) =>
                                    k.startsWith('assistant_ids.'),
                                )?.[1]
                            }
                        />
                    </fieldset>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="oc-confirmed">
                                Konfirmasi ke lab terkait
                            </Label>
                            <SelectField
                                id="oc-confirmed"
                                value={form.data.lab_confirmed ? '1' : '0'}
                                onValueChange={(v) =>
                                    form.setData('lab_confirmed', v === '1')
                                }
                                options={[
                                    { value: '0', label: 'Belum dikonfirmasi' },
                                    { value: '1', label: 'Sudah dikonfirmasi' },
                                ]}
                            />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="oc-note">Catatan (opsional)</Label>
                            <Input
                                id="oc-note"
                                maxLength={255}
                                value={form.data.lab_confirmation_note}
                                onChange={(e) =>
                                    form.setData(
                                        'lab_confirmation_note',
                                        e.target.value,
                                    )
                                }
                                placeholder="Mis. via WA Kalab SI, 12 Apr"
                            />
                            <InputError
                                message={form.errors.lab_confirmation_note}
                            />
                        </div>
                    </div>
                    <InputError
                        message={
                            (form.errors as Record<string, string>).semester_id
                        }
                    />

                    <DialogFooter>
                        <DialogClose asChild>
                            <Button type="button" variant="outline">
                                Batal
                            </Button>
                        </DialogClose>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <Spinner />}
                            {row ? 'Simpan Perubahan' : 'Tambah'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
