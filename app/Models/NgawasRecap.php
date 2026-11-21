<?php

namespace App\Models;

use App\Contracts\HasFiles;
use App\Enums\NgawasRecapStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Rekap ngawas bulanan format kampus (FR-RJN-08). Saat Final, data dan tanda
 * tangan dibekukan di `snapshot_json`.
 *
 * @property int $id
 * @property int $semester_id
 * @property string $month YYYY-MM
 * @property NgawasRecapStatus $status
 * @property int $revision
 * @property int|null $finalized_by
 * @property CarbonImmutable|null $finalized_at
 * @property string|null $reopened_note
 * @property array<string, mixed>|null $snapshot_json
 * @property int|null $file_id
 * @property-read Semester $semester
 */
class NgawasRecap extends Model implements HasFiles
{
    protected $guarded = ['id'];

    protected $attributes = [
        'status' => 'draft',
        'revision' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => NgawasRecapStatus::class,
            'revision' => 'integer',
            'finalized_at' => 'datetime',
            'snapshot_json' => 'array',
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
     * @return BelongsTo<StoredFile, $this>
     */
    public function file(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'file_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function finalizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }

    /**
     * @return HasMany<NgawasRecapRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(NgawasRecapRevision::class)->orderBy('revision');
    }

    public function isFinal(): bool
    {
        return $this->status === NgawasRecapStatus::Final;
    }

    /** Berkas Final/revisi: pengelola rekap, atau asisten yang tampil di rekap itu (hanya-baca). */
    public function fileVisibleTo(User $user): bool
    {
        if ($user->canManageNgawasRecap()) {
            return true;
        }

        $ids = array_column($this->snapshot_json['recap']['assistants'] ?? [], 'userId');

        return in_array($user->id, $ids, true);
    }
}
