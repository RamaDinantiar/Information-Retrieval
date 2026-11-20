import { AlertTriangle, CheckCircle2, OctagonAlert } from 'lucide-react';
import { cn } from '@/lib/utils';
import type { RecapChecks } from '@/types';

/** Panel Pemeriksaan (FR-RJN-07): Blokir menahan Final, Peringatan tidak. */
export function RecapChecksPanel({ checks }: { checks: RecapChecks }) {
    const blocks = checks.findings.filter((f) => f.level === 'block');
    const warnings = checks.findings.filter((f) => f.level === 'warning');

    return (
        <section
            aria-labelledby="recap-checks"
            className="grid gap-4 border bg-card p-5 shadow-sm"
        >
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 id="recap-checks" className="text-h2 font-bold">
                    Pemeriksaan
                </h2>
                {checks.blocking ? (
                    <span className="inline-flex items-center gap-1 bg-danger-soft px-2 py-1 text-xs font-semibold text-danger">
                        <OctagonAlert className="size-4" aria-hidden />
                        {blocks.length} temuan menahan Final
                    </span>
                ) : (
                    <span className="inline-flex items-center gap-1 bg-success-soft px-2 py-1 text-xs font-semibold text-success">
                        <CheckCircle2 className="size-4" aria-hidden />
                        Tidak ada temuan yang menahan Final
                    </span>
                )}
            </div>

            {checks.findings.length === 0 && (
                <p className="text-sm text-muted-foreground">
                    Semua pemeriksaan lolos untuk bulan ini.
                </p>
            )}

            {[...blocks, ...warnings].map((finding) => {
                const block = finding.level === 'block';
                const Icon = block ? OctagonAlert : AlertTriangle;

                return (
                    <details
                        key={finding.code}
                        open={block}
                        className={cn(
                            'border-l-4 bg-muted/40 p-3',
                            block ? 'border-danger' : 'border-warning',
                        )}
                    >
                        <summary className="flex cursor-pointer items-center gap-2 text-sm font-semibold">
                            <Icon
                                className={cn(
                                    'size-4 shrink-0',
                                    block ? 'text-danger' : 'text-warning',
                                )}
                                aria-hidden
                            />
                            <span className="sr-only">
                                {block ? 'Blokir: ' : 'Peringatan: '}
                            </span>
                            {finding.title}
                            <span className="ml-auto bg-white px-2 text-xs font-bold">
                                {finding.items.length + finding.more}
                            </span>
                        </summary>
                        <ul className="mt-2 list-disc space-y-0.5 pl-9 text-xs">
                            {finding.items.map((item) => (
                                <li key={item}>{item}</li>
                            ))}
                            {finding.more > 0 && (
                                <li className="list-none text-muted-foreground">
                                    …dan {finding.more} lainnya
                                </li>
                            )}
                        </ul>
                    </details>
                );
            })}
        </section>
    );
}
