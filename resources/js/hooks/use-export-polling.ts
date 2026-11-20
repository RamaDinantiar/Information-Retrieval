import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import GenerateController from '@/actions/App/Http/Controllers/Asisten/GenerateController';
import type { ExportRow } from '@/types';

/** US-A04-1: tampilkan progres ekspor yang sedang berjalan (dipakai Generate dan Rekap Jam Ngawas). */
export function useExportPolling(exports: ExportRow[]) {
    const [rows, setRows] = useState(exports);
    useEffect(() => setRows(exports), [exports]);

    const running = rows
        .filter((e) => e.status === 'queued' || e.status === 'processing')
        .map((e) => e.id);
    const key = running.join(',');

    useEffect(() => {
        if (!key) return;

        const timer = window.setInterval(async () => {
            const updated = await Promise.all(
                key.split(',').map((id) =>
                    fetch(GenerateController.show.url(Number(id)), {
                        headers: { Accept: 'application/json' },
                    }).then((r) => r.json() as Promise<ExportRow>),
                ),
            );
            setRows((current) =>
                current.map(
                    (row) => updated.find((u) => u.id === row.id) ?? row,
                ),
            );
            if (
                updated.every(
                    (u) => u.status !== 'queued' && u.status !== 'processing',
                )
            ) {
                router.reload({ only: ['exports'] });
            }
        }, 2000);

        return () => window.clearInterval(timer);
    }, [key]);

    return rows;
}
