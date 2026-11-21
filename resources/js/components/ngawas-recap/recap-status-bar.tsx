import { router, useForm, usePage } from '@inertiajs/react';
import { Download, Lock, LockOpen } from 'lucide-react';
import { useState } from 'react';
import NgawasRecapController from '@/actions/App/Http/Controllers/Asisten/NgawasRecapController';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { formatRelative } from '@/lib/datetime';
import type { RecapChecks, RecapFinalInfo } from '@/types';

/**
 * Tetapkan Final / Buka Kembali (FR-RJN-08/09). Pengelola melihat tombol aksi;
 * asisten hanya melihat berkas Final (hanya-baca).
 */
export function RecapStatusBar({
    month,
    isFinal,
    final,
    checks,
    canManage,
}: {
    month: string;
    isFinal: boolean;
    final: RecapFinalInfo | null;
    checks: RecapChecks | null;
    canManage: boolean;
}) {
    const [confirming, setConfirming] = useState(false);
    const [reopening, setReopening] = useState(false);
    const [busy, setBusy] = useState(false);
    const form = useForm({ month, note: '' });
    const blocked = checks?.blocking ?? false;
    const statusError = usePage().props.errors?.status;

    const finalize = () =>
        router.post(
            NgawasRecapController.finalize.url(),
            { month },
            {
                preserveScroll: true,
                onStart: () => setBusy(true),
                onFinish: () => setBusy(false),
                onSuccess: () => setConfirming(false),
            },
        );

    return (
        <section
            aria-labelledby="ngawas-status"
            className="grid gap-3 border bg-card p-5 shadow-sm"
        >
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 id="ngawas-status" className="text-h2 font-bold">
                        {isFinal ? 'Rekap sudah Final' : 'Status rekap: Draft'}
                    </h2>
                    <p className="text-xs text-muted-foreground">
                        {isFinal
                            ? `Ditetapkan ${final?.finalizedBy ? `oleh ${final.finalizedBy} ` : ''}${final?.finalizedAt ? formatRelative(final.finalizedAt) : ''}. Data dan tanda tangan dibekukan (hanya-baca).`
                            : 'Tetapkan Final setelah semua laporan terverifikasi dan temuan Blokir selesai. Berkas Excel Final dibuat saat itu juga.'}
                    </p>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    {isFinal && final?.file && (
                        <Button variant="outline" asChild>
                            <a href={final.file.url}>
                                <Download /> Unduh Final
                            </a>
                        </Button>
                    )}
                    {canManage &&
                        (isFinal ? (
                            <Button
                                variant="outline"
                                onClick={() => setReopening(true)}
                            >
                                <LockOpen /> Buka Kembali
                            </Button>
                        ) : (
                            <Button
                                disabled={blocked}
                                onClick={() => setConfirming(true)}
                            >
                                <Lock /> Tetapkan Final
                            </Button>
                        ))}
                </div>
            </div>

            {statusError && !reopening && (
                <p role="alert" className="text-xs text-destructive">
                    {statusError}
                </p>
            )}

            {canManage && !isFinal && blocked && (
                <p className="text-xs text-destructive">
                    Tombol Final ditahan: selesaikan temuan berlevel Blokir di
                    bawah.
                </p>
            )}

            {isFinal && final?.changedSinceFinal && (
                <Alert>
                    <AlertTitle>Data berubah sejak Final</AlertTitle>
                    <AlertDescription>
                        Laporan atau jadwal berubah setelah rekap ditetapkan.
                        Berkas Final tidak ikut berubah. Gunakan Buka Kembali
                        bila perlu direvisi.
                    </AlertDescription>
                </Alert>
            )}

            <Dialog open={confirming} onOpenChange={setConfirming}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Tetapkan rekap {month} Final?</DialogTitle>
                        <DialogDescription>
                            Data rekap dan tanda tangan dibekukan, berkas Excel
                            Final dibuat, dan semua asisten pada rekap mendapat
                            notifikasi.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setConfirming(false)}
                        >
                            Batal
                        </Button>
                        <Button disabled={busy} onClick={finalize}>
                            {busy ? 'Memproses…' : 'Tetapkan Final'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog open={reopening} onOpenChange={setReopening}>
                <DialogContent>
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post(NgawasRecapController.reopen.url(), {
                                preserveScroll: true,
                                onSuccess: () => {
                                    setReopening(false);
                                    form.reset('note');
                                },
                            });
                        }}
                        className="grid gap-4"
                    >
                        <DialogHeader>
                            <DialogTitle>
                                Buka Kembali rekap {month}
                            </DialogTitle>
                            <DialogDescription>
                                Berkas Final saat ini disimpan di riwayat
                                revisi. Rekap kembali ke Draft sebagai revisi
                                berikutnya.
                            </DialogDescription>
                        </DialogHeader>
                        <div className="grid gap-2">
                            <Label htmlFor="reopen-note">Catatan revisi</Label>
                            <Textarea
                                id="reopen-note"
                                value={form.data.note}
                                onChange={(e) =>
                                    form.setData('note', e.target.value)
                                }
                                placeholder="Revisi Kak Naomi: SKS DW (B) tanggal 6"
                                maxLength={500}
                                required
                            />
                            {(form.errors.note || statusError) && (
                                <p className="text-xs text-destructive">
                                    {form.errors.note ?? statusError}
                                </p>
                            )}
                        </div>
                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setReopening(false)}
                            >
                                Batal
                            </Button>
                            <Button
                                type="submit"
                                disabled={
                                    form.processing ||
                                    form.data.note.trim().length < 5
                                }
                            >
                                Buka Kembali
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </section>
    );
}
