<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Urutan asisten (dan kelasnya) di rekap, per semester (FR-RJN-06).
 *
 * @property int $id
 * @property int $semester_id
 * @property int $user_id
 * @property int $position
 * @property list<string>|null $class_order_json
 * @property-read User $user
 */
class NgawasRecapOrder extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'class_order_json' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
