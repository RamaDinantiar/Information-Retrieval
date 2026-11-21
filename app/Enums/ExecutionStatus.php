<?php

namespace App\Enums;

/** Status pelaksanaan kelas pada laporan ngawas (FR-RJN-01, PRD 3.4). */
enum ExecutionStatus: string
{
    case Held = 'held';
    case Cancelled = 'cancelled';
    case Replacement = 'replacement';
    case Additional = 'additional';
    case Exam = 'exam';

    public function label(): string
    {
        return match ($this) {
            self::Held => 'Terlaksana',
            self::Cancelled => 'Ditiadakan',
            self::Replacement => 'Pengganti',
            self::Additional => 'Tambahan',
            self::Exam => 'Ujian (UTS/UAS)',
        };
    }

    /** Kelas yang ditiadakan tidak pernah menghasilkan jam rekap. */
    public function countsHours(): bool
    {
        return $this !== self::Cancelled;
    }
}
