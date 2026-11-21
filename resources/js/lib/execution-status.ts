import type { ExecutionStatus } from '@/types';

/** Label & varian badge status pelaksanaan kelas (FR-RJN-01). */
export const EXECUTION: Record<
    ExecutionStatus,
    {
        label: string;
        variant: 'success' | 'warning' | 'destructive' | 'muted' | 'secondary';
    }
> = {
    held: { label: 'Terlaksana', variant: 'success' },
    cancelled: { label: 'Ditiadakan', variant: 'destructive' },
    replacement: { label: 'Pengganti', variant: 'secondary' },
    additional: { label: 'Tambahan', variant: 'warning' },
    exam: { label: 'Ujian (UTS/UAS)', variant: 'muted' },
};
