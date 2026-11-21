/** Kontrak data Schedule / Report / Generate (Asisten). */

import type { ScheduleColor } from './dashboard';

export type SwapStatus =
    | 'pending'
    | 'approved'
    | 'rejected'
    | 'expired'
    | 'cancelled';

export type ShiftSwapRow = {
    id: number;
    status: SwapStatus;
    reason: string;
    responseNote: string | null;
    from: string;
    to: string;
    shift: {
        title: string;
        date: string;
        start: string;
        end: string;
        room: string | null;
    };
    createdAt: string;
    respondedAt: string | null;
};

export type MyShift = {
    id: number;
    title: string;
    date: string;
    start: string;
    end: string;
    room: string | null;
    swappable: boolean;
    pendingSwap: boolean;
};

export type AllClassRow = {
    id: number;
    course: string;
    code: string;
    credits: number;
    name: string;
    dayOfWeek: number;
    startTime: string;
    endTime: string;
    room: string | null;
    assistants: string[];
    color: ScheduleColor;
    mine: boolean;
};

export type ReportStatus = 'draft' | 'submitted' | 'verified' | 'returned';

/** Status pelaksanaan kelas (FR-RJN-01). */
export type ExecutionStatus =
    | 'held'
    | 'cancelled'
    | 'replacement'
    | 'additional'
    | 'exam';

export type ReportRow = {
    id: number;
    shiftNumber: number;
    shiftId: number | null;
    date: string;
    start: string;
    end: string;
    classId: number | null;
    outsideClassId: number | null;
    code: string | null;
    course: string | null;
    className: string | null;
    executionStatus: ExecutionStatus;
    examType: 'uts' | 'uas' | null;
    originalDate: string | null;
    originalStart: string | null;
    replacementNote: string | null;
    heldOutsideLab: boolean;
    outsideLocation: string | null;
    room: string | null;
    activity: string;
    material: string | null;
    notes: string;
    assistants: { id: number; name: string; lead: boolean }[];
    author: string;
    status: ReportStatus;
    returnNote: string | null;
    verifier: string | null;
    photo: { name: string; url: string } | null;
    locked: boolean;
    editableUntil: string;
    canEdit: boolean;
    canReview: boolean;
    canUnlock: boolean;
};

export type PendingShift = {
    id: number;
    date: string;
    start: string;
    end: string;
    classId: number | null;
    title: string;
    code: string | null;
    room: string | null;
    teamIds: number[];
};

export type ExportRow = {
    id: number;
    /** 'pdf' hanya untuk riwayat ekspor lama (sebelum rekap berformat Word). */
    type: 'zip' | 'docx' | 'excel' | 'pdf' | 'ngawas';
    month: string;
    /** Tanggal laporan untuk Single DOC (YYYY-MM-DD). */
    date: string | null;
    scope: 'mine' | 'all';
    /** Rekap ngawas: draft | final. */
    mode: 'draft' | 'final' | null;
    status: 'queued' | 'processing' | 'done' | 'empty' | 'failed';
    progress: number;
    error: string | null;
    createdAt: string;
    expiresAt: string | null;
    file: { name: string; size: number; url: string } | null;
};

export type HoursRecapRow = {
    userId: number;
    name: string;
    nim: string | null;
    shifts: number;
    hours: number;
    courses: {
        name: string;
        code: string | null;
        credits: number | null;
        shifts: number;
        hours: number;
    }[];
};

/** Berkas hasil rekap Final/revisi (unduh lewat signed URL). */
export type RecapFile = { name: string; size: number; url: string };

/** Rekap Jam Ngawas berstatus Final (FR-RJN-08). */
export type RecapFinalInfo = {
    finalizedAt: string | null;
    finalizedBy: string | null;
    file: RecapFile | null;
    /** Data laporan berubah sejak Final (hanya pengelola). */
    changedSinceFinal: boolean;
};

/** Riwayat revisi: berkas Final lama beserta catatan Buka Kembali (FR-RJN-09). */
export type RecapRevision = {
    revision: number;
    note: string | null;
    by: string | null;
    at: string;
    file: RecapFile | null;
};
