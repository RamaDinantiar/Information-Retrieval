<?php

namespace App\Models;

use App\Enums\CalendarEventType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Libur nasional atau periode UTS/UAS per semester (FR-RJN-04).
 *
 * @property int $id
 * @property int $semester_id
 * @property CarbonImmutable $date_from
 * @property CarbonImmutable $date_to
 * @property CalendarEventType $type
 * @property string $label
 * @property int|null $created_by
 * @property-read Semester $semester
 */
#[Fillable(['date_from', 'date_to', 'type', 'label'])]
class AcademicCalendarEvent extends Model
{
    protected function casts(): array
    {
        return [
            'date_from' => 'date',
            'date_to' => 'date',
            'type' => CalendarEventType::class,
        ];
    }

    /**
     * @return BelongsTo<Semester, $this>
     */
    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class);
    }
}
