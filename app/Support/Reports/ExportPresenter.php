<?php

namespace App\Support\Reports;

use App\Models\Export;

/** Bentuk data ekspor untuk halaman Generate dan Rekap Jam Ngawas (termasuk polling progres). */
class ExportPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function row(Export $export): array
    {
        $available = $export->status === 'done' && $export->file && $export->expires_at?->isFuture();

        return [
            'id' => $export->id,
            'type' => $export->type,
            'month' => $export->params_json['month'],
            'date' => $export->params_json['date'] ?? null, // Single DOC lama (sebelum unduh langsung)
            'scope' => $export->params_json['scope'] ?? 'mine',
            'mode' => $export->params_json['mode'] ?? null, // Rekap ngawas: draft | final
            'status' => $export->status,
            'progress' => $export->progress,
            'error' => $export->error,
            'createdAt' => $export->created_at->toIso8601String(),
            'expiresAt' => $export->expires_at?->toIso8601String(),
            'file' => $available ? ['name' => $export->file->original_name, 'size' => $export->file->size, 'url' => $export->file->url()] : null,
        ];
    }
}
