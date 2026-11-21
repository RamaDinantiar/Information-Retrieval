<?php

namespace App\Events;

use App\Models\NgawasRecap;
use Illuminate\Foundation\Events\Dispatchable;

/** FR-RJN-15(b): rekap dibuka kembali untuk revisi → asisten diberi tahu beserta catatannya. */
class NgawasRecapReopened
{
    use Dispatchable;

    /** @param  list<int>  $userIds  asisten yang tampil di rekap */
    public function __construct(public readonly NgawasRecap $recap, public readonly string $note, public readonly array $userIds) {}
}
