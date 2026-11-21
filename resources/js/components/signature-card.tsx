import { router, useForm } from '@inertiajs/react';
import { PenLine, Trash2, Upload } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import SignatureController from '@/actions/App/Http/Controllers/Settings/SignatureController';
import InputError from '@/components/input-error';
import { SignaturePad } from '@/components/signature-pad';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';

type Mode = 'upload' | 'draw';

/** Papan kotak-kotak agar PNG transparan terlihat jelas. */
const CHECKER =
    'bg-[length:16px_16px] bg-[linear-gradient(45deg,#e5e7eb_25%,transparent_25%,transparent_75%,#e5e7eb_75%),linear-gradient(45deg,#e5e7eb_25%,#fff_25%,#fff_75%,#e5e7eb_75%)] bg-[position:0_0,8px_8px]';

/**
 * Pengaturan › Profil › Tanda Tangan (US-RJN-02), khusus Asisten: unggah
 * gambar atau gambar langsung di kanvas. Dipasang otomatis di sheet absensi
 * rekap ngawas tanpa konfirmasi.
 */
export function SignatureCard({
    signatureUrl,
}: {
    signatureUrl: string | null;
}) {
    const [mode, setMode] = useState<Mode>('draw');
    const [preview, setPreview] = useState<string | null>(null);
    // Naik setiap kali tersimpan agar kanvas kembali kosong.
    const [padKey, setPadKey] = useState(0);
    const fileInput = useRef<HTMLInputElement>(null);
    const form = useForm<{ signature: File | null; signature_data: string }>({
        signature: null,
        signature_data: '',
    });

    useEffect(() => {
        if (!form.data.signature) {
            setPreview(null);

            return;
        }

        const url = URL.createObjectURL(form.data.signature);
        setPreview(url);

        return () => URL.revokeObjectURL(url);
    }, [form.data.signature]);

    const ready =
        mode === 'upload' ? !!form.data.signature : !!form.data.signature_data;

    const save = () => {
        form.transform((data) =>
            mode === 'upload'
                ? { signature: data.signature }
                : { signature_data: data.signature_data },
        );
        form.post(SignatureController.update.url(), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setPadKey((k) => k + 1);
            },
        });
    };

    const tabs: { key: Mode; label: string; icon: typeof Upload }[] = [
        { key: 'draw', label: 'Gambar langsung', icon: PenLine },
        { key: 'upload', label: 'Unggah gambar', icon: Upload },
    ];

    return (
        <section
            aria-labelledby="signature-title"
            className="grid gap-4 border p-4"
        >
            <div>
                <h2 id="signature-title" className="text-sm font-bold">
                    Tanda tangan
                </h2>
                <p className="text-xs text-muted-foreground">
                    Dipasang otomatis di lembar absensi Rekap Jam Ngawas pada
                    setiap hari Anda hadir. Cukup disimpan sekali; dapat diganti
                    atau dihapus kapan saja.
                </p>
            </div>

            <div className="flex flex-wrap items-start gap-4">
                <div className="grid gap-1">
                    <span className="text-xs text-muted-foreground">
                        Tanda tangan saat ini
                    </span>
                    <div
                        className={cn(
                            'flex h-28 w-56 items-center justify-center border',
                            CHECKER,
                        )}
                    >
                        {signatureUrl ? (
                            <img
                                src={signatureUrl}
                                alt="Tanda tangan Anda"
                                className="max-h-full max-w-full object-contain"
                            />
                        ) : (
                            <span className="bg-white/90 px-2 text-xs text-muted-foreground">
                                Belum ada
                            </span>
                        )}
                    </div>
                    {signatureUrl && (
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            className="w-fit text-danger hover:bg-danger-soft hover:text-danger"
                            onClick={() =>
                                router.delete(
                                    SignatureController.destroy.url(),
                                    {
                                        preserveScroll: true,
                                    },
                                )
                            }
                        >
                            <Trash2 /> Hapus
                        </Button>
                    )}
                </div>

                <div className="grid min-w-64 flex-1 gap-3">
                    <div
                        role="tablist"
                        aria-label="Cara mengisi"
                        className="flex gap-1"
                    >
                        {tabs.map(({ key, label, icon: Icon }) => (
                            <button
                                key={key}
                                type="button"
                                role="tab"
                                aria-selected={mode === key}
                                onClick={() => setMode(key)}
                                className={cn(
                                    'inline-flex items-center gap-1.5 border px-3 py-1.5 text-xs font-medium focus-visible:ring-[3px] focus-visible:ring-ring/40 focus-visible:outline-none',
                                    mode === key
                                        ? 'border-primary-700 bg-primary-700 text-white'
                                        : 'bg-white text-muted-foreground hover:text-foreground',
                                )}
                            >
                                <Icon className="size-3.5" aria-hidden />{' '}
                                {label}
                            </button>
                        ))}
                    </div>

                    {mode === 'draw' ? (
                        <SignaturePad
                            key={padKey}
                            onChange={(dataUrl) =>
                                form.setData('signature_data', dataUrl ?? '')
                            }
                        />
                    ) : (
                        <div className="grid gap-2">
                            <input
                                ref={fileInput}
                                type="file"
                                accept="image/png,image/jpeg"
                                className="sr-only"
                                aria-label="Gambar tanda tangan"
                                onChange={(e) =>
                                    form.setData(
                                        'signature',
                                        e.target.files?.[0] ?? null,
                                    )
                                }
                            />
                            <div>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => fileInput.current?.click()}
                                >
                                    <Upload />{' '}
                                    {form.data.signature
                                        ? form.data.signature.name
                                        : 'Pilih gambar'}
                                </Button>
                            </div>
                            {preview && (
                                <div className="flex h-28 w-56 items-center justify-center border bg-white">
                                    <img
                                        src={preview}
                                        alt="Pratinjau tanda tangan"
                                        className="max-h-full max-w-full object-contain"
                                    />
                                </div>
                            )}
                            <p className="text-xs text-muted-foreground">
                                PNG atau JPG, maks. 1 MB, latar putih atau
                                transparan. Ruang kosong dipangkas otomatis.
                            </p>
                        </div>
                    )}

                    <InputError
                        message={
                            form.errors.signature ?? form.errors.signature_data
                        }
                    />
                    <div>
                        <Button
                            type="button"
                            size="sm"
                            disabled={!ready || form.processing}
                            onClick={save}
                        >
                            {form.processing && <Spinner />} Simpan tanda tangan
                        </Button>
                    </div>
                </div>
            </div>
        </section>
    );
}
