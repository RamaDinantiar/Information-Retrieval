<?php

namespace App\Events;

use App\Models\NgawasRecap;
use Illuminate\Foundation\Events\Dispatchable;

/** FR-RJN-15(b): rekap ditetapkan Final → semua asisten pada rekap diberi tahu. */
class NgawasRecapFinalized
{
    use Dispatchable;

    /** @param  list<int>  $userIds  asisten yang tampil di rekap */
    public function __construct(public readonly NgawasRecap $recap, public readonly array $userIds) {}
}
