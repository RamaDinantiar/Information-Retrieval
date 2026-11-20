import type { ExecutionStatus } from './operations';

/** Kontrak data Rekap Jam Ngawas (NgawasRecap::build, FR-RJN-05). */

export type RecapKind =
    | 'cancelled'
    | 'exam'
    | 'outside'
    | 'replacement'
    | 'additional';

export type RecapCell = {
    day: number;
    date: string;
    status: ExecutionStatus;
    kind: RecapKind | null;
    credits: number | null;
    hours: number;
    counts: boolean;
    outsideLab: boolean;
    reportIds: number[];
};

export type RecapRow = {
    key: string;
    label: string;
    credits: number | null;
    outside: boolean;
    placeholder: boolean;
    /** Kunci = tanggal (1–31); `[]` bila kosong. */
    cells: Record<string, RecapCell> | never[];
    totalDays: number;
    hours: number;
};

export type RecapAssistant = {
    userId: number;
    name: string;
    nim: string;
    hasSignature: boolean;
    rows: RecapRow[];
    totalDays: number;
    hoursRaw: number;
    hours: number;
};

export type RecapColumn = {
    day: number;
    date: string;
    weekday: string;
    kind: 'sunday' | 'holiday' | 'exam' | null;
    label: string | null;
};

export type RecapNote = {
    date: string;
    class: string;
    time: string;
    note: string;
};

export type RecapReport = {
    id: number;
    date: string;
    start: string;
    end: string;
    class: string;
    status: string;
    executionStatus: ExecutionStatus;
    team: string[];
};

export type NgawasRecapData = {
    month: string;
    monthLabel: string;
    daysInMonth: number;
    semester: {
        id: number;
        name: string;
        termLabel: string | null;
        skNumber: string | null;
    };
    columns: RecapColumn[];
    assistants: RecapAssistant[];
    totals: {
        assistants: number;
        days: number;
        hoursRaw: number;
        hours: number;
    };
    additional: RecapNote[];
    replacements: RecapNote[];
    /** Kunci = id laporan; `[]` bila kosong. */
    reports: Record<string, RecapReport> | never[];
    duplicates: {
        name: string;
        class: string;
        date: string;
        reportIds: number[];
    }[];
    unplaced: { id: number; date: string; activity: string }[];
};

export type RecapFinding = {
    level: 'block' | 'warning';
    code: string;
    title: string;
    items: string[];
    more: number;
};

export type RecapChecks = { blocking: boolean; findings: RecapFinding[] };
