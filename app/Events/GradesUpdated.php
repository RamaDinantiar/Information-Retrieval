<?php

namespace App\Events;

use App\Models\TaskTarget;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Nilai yang sudah dipublikasikan diubah asisten (nilai langsung terlihat) → notifikasi "Nilai diperbarui".
 */
class GradesUpdated
{
    use Dispatchable;

    /**
     * @param  array<int, int>  $userIds
     */
    public function __construct(public readonly TaskTarget $target, public readonly array $userIds) {}
}
