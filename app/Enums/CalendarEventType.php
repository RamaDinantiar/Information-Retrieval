<?php

namespace App\Enums;

/** Jenis penanda kalender akademik (FR-RJN-04). Minggu dideteksi otomatis. */
enum CalendarEventType: string
{
    case NationalHoliday = 'national_holiday';
    case Uts = 'uts';
    case Uas = 'uas';

    public function label(): string
    {
        return match ($this) {
            self::NationalHoliday => 'Libur Nasional',
            self::Uts => 'UTS',
            self::Uas => 'UAS',
        };
    }
}
