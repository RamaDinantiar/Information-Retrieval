import { router, useForm } from '@inertiajs/react';
import { RotateCcw, Upload } from 'lucide-react';
import { useRef } from 'react';
import NgawasTemplateController from '@/actions/App/Http/Controllers/Admin/NgawasTemplateController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatRelative } from '@/lib/datetime';

export type NgawasTemplateInfo = {
    name: string;
    custom: boolean;
    uploadedAt: string | null;
};

/** Template Excel kampus untuk ekspor Rekap Jam Ngawas (FR-RJN-12). */
export function TemplateCard({ template }: { template: NgawasTemplateInfo }) {
    const fileInput = useRef<HTMLInputElement>(null);
    const form = useForm<{ template: File | null }>({ template: null });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(NgawasTemplateController.store.url(), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                form.reset();

                if (fileInput.current) {
                    fileInput.current.value = '';
                }
            },
        });
    };

    return (
        <form
            onSubmit={submit}
            aria-label="Template rekap ngawas"
            className="grid gap-3 border p-4"
        >
            <div>
                <p className="text-sm font-bold">{template.name}</p>
                <p className="text-xs text-muted-foreground">
                    {template.custom && template.uploadedAt
                        ? `Template unggahan Admin, ${formatRelative(template.uploadedAt)}.`
                        : 'Template yang dibawa aplikasi.'}
                </p>
            </div>

            <div className="grid gap-2">
                <Label htmlFor="ngawas-template">Ganti dengan file .xlsx</Label>
                <Input
                    id="ngawas-template"
                    ref={fileInput}
                    type="file"
                    accept=".xlsx"
                    onChange={(e) =>
                        form.setData('template', e.target.files?.[0] ?? null)
                    }
                />
                <InputError message={form.errors.template} />
                <p className="text-xs text-muted-foreground">
                    Maks. 2 MB. Harus punya 2 sheet bernama sama, header di
                    baris 13, dan legenda di A79:B85. Bila ditolak, template
                    lama tetap dipakai. Posisi TextBox, logo, dan tanda tangan
                    mengikuti konstanta di aplikasi; template dengan tata letak
                    yang jauh berbeda perlu penyesuaian oleh developer.
                </p>
            </div>

            <div className="flex flex-wrap gap-2">
                <Button
                    type="submit"
                    disabled={!form.data.template || form.processing}
                >
                    {form.processing ? <Spinner /> : <Upload />} Unggah template
                </Button>
                {template.custom && (
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => {
                            if (
                                window.confirm(
                                    'Pulihkan template bawaan? Template unggahan dihapus.',
                                )
                            ) {
                                router.delete(
                                    NgawasTemplateController.destroy.url(),
                                    { preserveScroll: true },
                                );
                            }
                        }}
                    >
                        <RotateCcw /> Pulihkan bawaan
                    </Button>
                )}
            </div>
        </form>
    );
}
