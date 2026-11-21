import { router } from '@inertiajs/react';
import { Download, FileSpreadsheet, Loader2 } from 'lucide-react';
import NgawasRecapController from '@/actions/App/Http/Controllers/Asisten/NgawasRecapController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useExportPolling } from '@/hooks/use-export-polling';
import { formatRelative } from '@/lib/datetime';
import type { ExportRow } from '@/types';

const STATUS: Record<ExportRow['status'], string> = {
    queued: 'Menunggu',
    processing: 'Diproses',
    done: 'Selesai',
    empty: 'Kosong',
    failed: 'Gagal',
};

/** Ekspor Excel format kampus (FR-RJN-11): Draft untuk pengecekan, diproses di antrean. */
export function ExportPanel({
    month,
    exports,
}: {
    month: string;
    exports: ExportRow[];
}) {
    const rows = useExportPolling(exports);
    const busy = rows.some(
        (r) => r.status === 'queued' || r.status === 'processing',
    );

    return (
        <section
            aria-labelledby="ngawas-export"
            className="grid gap-4 border bg-card p-5 shadow-sm"
        >
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 id="ngawas-export" className="text-h2 font-bold">
                        Ekspor Excel
                    </h2>
                    <p className="text-xs text-muted-foreground">
                        Draft untuk dicek: berformat template kampus, bertanda
                        DRAFT, tanpa tanda tangan pengesah. Berkas Final dibuat
                        saat rekap ditetapkan Final.
                    </p>
                </div>
                <Button
                    disabled={busy}
                    onClick={() =>
                        router.post(
                            NgawasRecapController.export.url(),
                            { month },
                            { preserveScroll: true },
                        )
                    }
                >
                    {busy ? (
                        <Loader2 className="animate-spin" />
                    ) : (
                        <FileSpreadsheet />
                    )}{' '}
                    Unduh Excel (Draft)
                </Button>
            </div>

            {rows.length > 0 && (
                <ul className="grid gap-2 text-sm">
                    {rows.map((r) => (
                        <li
                            key={r.id}
                            className="flex flex-wrap items-center justify-between gap-2 border-t pt-2"
                        >
                            <span>
                                {r.month} ·{' '}
                                {r.mode === 'final' ? 'Final' : 'Draft'}{' '}
                                <span className="text-xs text-muted-foreground">
                                    {formatRelative(r.createdAt)}
                                </span>
                            </span>
                            <span className="flex items-center gap-2">
                                <Badge
                                    variant={
                                        r.status === 'done'
                                            ? 'success'
                                            : r.status === 'failed'
                                              ? 'destructive'
                                              : 'warning'
                                    }
                                >
                                    {STATUS[r.status]}
                                    {(r.status === 'queued' ||
                                        r.status === 'processing') &&
                                        ` ${r.progress}%`}
                                </Badge>
                                {r.file && (
                                    <Button size="sm" variant="outline" asChild>
                                        <a href={r.file.url}>
                                            <Download /> Unduh
                                        </a>
                                    </Button>
                                )}
                            </span>
                            {r.status === 'failed' && r.error && (
                                <span className="basis-full text-xs text-danger">
                                    {r.error}
                                </span>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
