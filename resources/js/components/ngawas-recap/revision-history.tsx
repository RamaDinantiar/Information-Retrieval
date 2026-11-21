import { Download } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { formatRelative } from '@/lib/datetime';
import type { RecapRevision } from '@/types';

/** Riwayat revisi (FR-RJN-09): berkas Final lama dapat diunduh, lengkap dengan catatan Buka Kembali. */
export function RevisionHistory({ revisions }: { revisions: RecapRevision[] }) {
    if (revisions.length === 0) {
        return null;
    }

    return (
        <section
            aria-labelledby="ngawas-revisions"
            className="grid gap-3 border bg-card p-5 shadow-sm"
        >
            <h2 id="ngawas-revisions" className="text-h2 font-bold">
                Riwayat revisi
            </h2>
            <ul className="grid gap-2 text-sm">
                {revisions.map((r) => (
                    <li
                        key={r.revision}
                        className="flex flex-wrap items-start justify-between gap-2 border-t pt-2"
                    >
                        <div>
                            <p className="font-semibold">
                                Final rev. {r.revision}{' '}
                                <span className="text-xs font-normal text-muted-foreground">
                                    dibuka kembali {r.by ? `oleh ${r.by} ` : ''}
                                    {formatRelative(r.at)}
                                </span>
                            </p>
                            {r.note && (
                                <p className="text-muted-foreground">
                                    {r.note}
                                </p>
                            )}
                        </div>
                        {r.file && (
                            <Button variant="outline" size="sm" asChild>
                                <a href={r.file.url}>
                                    <Download /> Unduh
                                </a>
                            </Button>
                        )}
                    </li>
                ))}
            </ul>
        </section>
    );
}
