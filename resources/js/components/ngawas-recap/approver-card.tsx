import { useForm } from '@inertiajs/react';
import { Trash2, Upload } from 'lucide-react';
import { useRef } from 'react';
import NgawasApproverController from '@/actions/App/Http/Controllers/Admin/NgawasApproverController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

export type Approver = {
    index: number;
    position: string;
    name: string;
    signatureUrl: string | null;
};

/** Satu pengesah rekap ngawas: jabatan, nama, gambar tanda tangan (tanpa NIP). */
export function ApproverCard({ approver }: { approver: Approver }) {
    const fileInput = useRef<HTMLInputElement>(null);
    const form = useForm<{
        position: string;
        name: string;
        signature: File | null;
        remove_signature: boolean;
    }>({
        position: approver.position,
        name: approver.name,
        signature: null,
        remove_signature: false,
    });
    const id = `approver-${approver.index}`;

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.put(NgawasApproverController.update.url(approver.index), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () =>
                form.setData({
                    ...form.data,
                    signature: null,
                    remove_signature: false,
                }),
        });
    };

    return (
        <form
            onSubmit={submit}
            aria-label={`Pengesah ${approver.index + 1}`}
            className="grid gap-3 border p-4"
        >
            <h3 className="text-sm font-bold">
                Pengesah {approver.index + 1}{' '}
                <span className="font-normal text-muted-foreground">
                    (tercetak di {approver.index === 0 ? 'kiri' : 'kanan'})
                </span>
            </h3>
            <div className="grid gap-2">
                <Label htmlFor={`${id}-position`}>Jabatan</Label>
                <Input
                    id={`${id}-position`}
                    required
                    maxLength={80}
                    value={form.data.position}
                    onChange={(e) => form.setData('position', e.target.value)}
                    aria-invalid={!!form.errors.position}
                />
                <InputError message={form.errors.position} />
            </div>
            <div className="grid gap-2">
                <Label htmlFor={`${id}-name`}>Nama (beserta gelar)</Label>
                <Input
                    id={`${id}-name`}
                    required
                    maxLength={120}
                    value={form.data.name}
                    onChange={(e) => form.setData('name', e.target.value)}
                    aria-invalid={!!form.errors.name}
                />
                <InputError message={form.errors.name} />
            </div>
            <div className="grid gap-2">
                <span className="text-sm font-medium">Tanda tangan</span>
                <div className="flex h-24 items-center justify-center border bg-white">
                    {approver.signatureUrl && !form.data.remove_signature ? (
                        <img
                            src={approver.signatureUrl}
                            alt={`Tanda tangan ${approver.position}`}
                            className="max-h-full max-w-full object-contain"
                        />
                    ) : (
                        <span className="text-xs text-muted-foreground">
                            Belum ada
                        </span>
                    )}
                </div>
                <input
                    ref={fileInput}
                    type="file"
                    accept="image/png,image/jpeg"
                    className="sr-only"
                    aria-label={`Gambar tanda tangan pengesah ${approver.index + 1}`}
                    onChange={(e) =>
                        form.setData((d) => ({
                            ...d,
                            signature: e.target.files?.[0] ?? null,
                            remove_signature: false,
                        }))
                    }
                />
                <div className="flex flex-wrap gap-2">
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        onClick={() => fileInput.current?.click()}
                    >
                        <Upload />{' '}
                        {form.data.signature
                            ? form.data.signature.name
                            : approver.signatureUrl
                              ? 'Ganti gambar'
                              : 'Pilih gambar'}
                    </Button>
                    {approver.signatureUrl && (
                        <Button
                            type="button"
                            size="sm"
                            variant={
                                form.data.remove_signature
                                    ? 'secondary'
                                    : 'ghost'
                            }
                            className="text-danger"
                            onClick={() =>
                                form.setData((d) => ({
                                    ...d,
                                    signature: null,
                                    remove_signature: !d.remove_signature,
                                }))
                            }
                        >
                            <Trash2 />{' '}
                            {form.data.remove_signature
                                ? 'Batal hapus'
                                : 'Hapus tanda tangan'}
                        </Button>
                    )}
                </div>
                <p className="text-xs text-muted-foreground">
                    PNG atau JPG, maks. 1 MB. Latar putih dijadikan transparan
                    dan ruang kosong dipangkas otomatis.
                </p>
                <InputError message={form.errors.signature} />
            </div>
            <div>
                <Button type="submit" size="sm" disabled={form.processing}>
                    {form.processing && <Spinner />} Simpan Pengesah{' '}
                    {approver.index + 1}
                </Button>
            </div>
        </form>
    );
}
