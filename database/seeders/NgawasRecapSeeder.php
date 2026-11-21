<?php

namespace Database\Seeders;

use App\Enums\CalendarEventType;
use App\Models\AcademicCalendarEvent;
use App\Models\Course;
use App\Models\OutsideClass;
use App\Models\Semester;
use App\Models\User;

/**
 * Data contoh Rekap Jam Ngawas: SKS bervariasi (2/3), nomor SK & label semester,
 * kalender akademik, dan satu kelas di luar Lab IR. Aman dijalankan berulang
 * (idempotent) agar tidak perlu migrate:fresh.
 */
class NgawasRecapSeeder extends DemoSeeder
{
    /** Mata kuliah 2 SKS; sisanya tetap 3 SKS. */
    private const TWO_CREDIT_COURSES = ['IF-2104', 'IF-3205'];

    public function run(): void
    {
        $semester = Semester::current();

        if ($semester === null) {
            return;
        }

        $semester->forceFill([
            'sk_number' => $semester->sk_number ?? '01/SK/Ganjil/IRLab/2026',
            'term_label' => $semester->term_label ?? 'SEMESTER GANJIL TAHUN 2026/2027',
        ])->save();

        foreach (self::TWO_CREDIT_COURSES as $code) {
            Course::where('semester_id', $semester->id)->where('code', $code)->update(['credits' => 2]);
        }

        $events = [
            ['2026-08-25', '2026-08-25', CalendarEventType::NationalHoliday, 'Maulid Nabi Muhammad SAW (contoh)'],
            ['2026-10-12', '2026-10-23', CalendarEventType::Uts, 'Ujian Tengah Semester (contoh)'],
            ['2026-12-07', '2026-12-18', CalendarEventType::Uas, 'Ujian Akhir Semester (contoh)'],
        ];

        foreach ($events as [$from, $to, $type, $label]) {
            $event = $semester->calendarEvents()->where('type', $type)->whereDate('date_from', $from)->first()
                ?? new AcademicCalendarEvent(['semester_id' => $semester->id]);

            $event->forceFill(['date_from' => $from, 'date_to' => $to, 'type' => $type, 'label' => $label])->save();
        }

        $outside = OutsideClass::firstOrCreate(
            ['semester_id' => $semester->id, 'course_name' => 'Data Warehouse', 'class_name' => 'KR'],
            ['credits' => 2, 'origin_lab' => 'Lab Sistem Informasi', 'day_of_week' => 1, 'start_time' => '18:30', 'end_time' => '20:10'],
        );

        $michael = User::where('email', 'michael@irlab.test')->first();

        if ($michael !== null) {
            $outside->assistants()->syncWithoutDetaching([$michael->id]);
        }
    }
}
