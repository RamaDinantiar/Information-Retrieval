<?php

namespace App\Enums;

/** FR-RJN-08: Draft → Final → (Buka Kembali) Draft dengan revisi + 1. */
enum NgawasRecapStatus: string
{
    case Draft = 'draft';
    case Final = 'final';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Final => 'Final',
        };
    }
}
