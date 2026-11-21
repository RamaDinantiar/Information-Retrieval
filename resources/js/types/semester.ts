/** Baris tabel Admin › Semester — sama dengan SemesterController@index. */
export type SemesterRow = {
    id: number;
    name: string;
    /** "YYYY-MM-DD". */
    startDate: string;
    endDate: string;
    /** Kop rekap ngawas (US-RJN-05). */
    skNumber: string | null;
    termLabel: string | null;
    status: 'draft' | 'active' | 'archived';
    activatedAt: string | null;
    archivedAt: string | null;
    canEdit: boolean;
    canPurge: boolean;
};
