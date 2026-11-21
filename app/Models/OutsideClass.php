<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Kelas lab lain yang diawas asisten IR (PRD 3.6). Tidak dicampur dengan
 * `courses`/`classes`.
 *
 * @property int $id
 * @property int $semester_id
 * @property string $course_name
 * @property string $class_name
 * @property int $credits
 * @property string $origin_lab
 * @property int|null $day_of_week
 * @property string|null $start_time
 * @property string|null $end_time
 * @property CarbonImmutable|null $lab_confirmed_at
 * @property string|null $lab_confirmation_note
 * @property int|null $created_by
 * @property-read Semester $semester
 */
#[Fillable(['course_name', 'class_name', 'credits', 'origin_lab', 'day_of_week', 'start_time', 'end_time', 'lab_confirmed_at', 'lab_confirmation_note'])]
class OutsideClass extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'credits' => 'integer',
            'day_of_week' => 'integer',
            'lab_confirmed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Semester, $this>
     */
    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function assistants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'outside_class_assistants');
    }

    public function isConfirmed(): bool
    {
        return $this->lab_confirmed_at !== null;
    }
}
