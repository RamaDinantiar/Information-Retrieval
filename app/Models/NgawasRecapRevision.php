<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Arsip file setiap Final (FR-RJN-09).
 *
 * @property int $id
 * @property int $ngawas_recap_id
 * @property int $revision
 * @property int|null $file_id
 * @property string|null $note
 * @property int|null $created_by
 * @property-read NgawasRecap $recap
 */
class NgawasRecapRevision extends Model
{
    protected $guarded = ['id'];

    /**
     * @return BelongsTo<NgawasRecap, $this>
     */
    public function recap(): BelongsTo
    {
        return $this->belongsTo(NgawasRecap::class, 'ngawas_recap_id');
    }

    /**
     * @return BelongsTo<StoredFile, $this>
     */
    public function file(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'file_id');
    }
}
