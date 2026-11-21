<?php

namespace App\Listeners;

use App\Enums\Position;
use App\Events\ExportFinished;
use App\Events\GradesPublished;
use App\Events\NgawasRecapFinalized;
use App\Events\NgawasRecapReopened;
use App\Events\SeatContractChanged;
use App\Events\ShiftSwapChanged;
use App\Events\SupervisionReportReviewed;
use App\Events\SupervisionReportSubmitted;
use App\Models\User;
use App\Support\Notifications\Notify;
use Carbon\CarbonImmutable;

/**
 * Event domain → notifikasi lonceng (method handle* ditemukan otomatis oleh
 * event discovery Laravel berdasarkan type-hint) (PRD: tugas, nilai, tukar shift,
 * verifikasi laporan, kontrak kursi, ekspor).
 */
class SendLabNotifications
{
    public function handleGradesPublished(GradesPublished $event): void
    {
        $target = $event->target->loadMissing(['task', 'labClass.course']);

        Notify::send($event->userIds, 'grade', 'Nilai dipublikasikan', "Nilai {$target->task->title} ({$target->labClass->course->name}) sudah dapat dilihat.", '/praktikan/grade?class='.$target->class_id);
    }

    public function handleSeatContractChanged(SeatContractChanged $event): void
    {
        $contract = $event->contract->loadMissing(['labClass.course', 'seat']);
        $class = "{$contract->labClass->course->name} · {$contract->labClass->name}";
        $body = $event->change === 'moved'
            ? "Kursi Anda di {$class} dipindahkan Admin ke {$contract->seat->label}. Alasan: {$event->reason}"
            : "Kontrak kursi Anda di {$class} dibatalkan Admin. Alasan: {$event->reason}";

        Notify::send([$contract->user_id], 'seat', 'Perubahan kontrak kursi', $body, '/praktikan/seats?class='.$contract->class_id);
    }

    public function handleShiftSwapChanged(ShiftSwapChanged $event): void
    {
        $swap = $event->swap->loadMissing(['shift.labClass.course', 'fromUser', 'toUser']);
        $when = $swap->shift->date->translatedFormat('l, d M').' '.$swap->shift->startClock();

        match ($swap->status) {
            'pending' => Notify::send([$swap->to_user_id], 'shift', 'Permintaan tukar shift', "{$swap->fromUser->name} meminta Anda menggantikan shift {$swap->shift->displayTitle()} ({$when}).", '/asisten/schedule'),
            'approved' => (function () use ($swap, $when) {
                Notify::send([$swap->from_user_id], 'shift', 'Tukar shift disetujui', "{$swap->toUser->name} menyetujui tukar shift {$when}.", '/asisten/schedule');
                // A5: Admin hanya diberi tahu.
                Notify::admins('shift', 'Tukar shift disetujui', "{$swap->fromUser->name} → {$swap->toUser->name}: {$swap->shift->displayTitle()} ({$when}).", '/asisten/schedule');
            })(),
            'rejected' => Notify::send([$swap->from_user_id], 'shift', 'Tukar shift ditolak', "{$swap->toUser->name} menolak tukar shift {$when}.", '/asisten/schedule'),
            default => null,
        };
    }

    public function handleReportSubmitted(SupervisionReportSubmitted $event): void
    {
        $report = $event->report->loadMissing('user');
        $reviewers = User::where('position', Position::SekretarisBendahara)->where('is_active', true)->whereKeyNot($report->user_id)->get();

        Notify::send($reviewers, 'report', 'Laporan ngawas menunggu verifikasi', "{$report->user->name} mengirim laporan {$report->date->translatedFormat('d M Y')} · {$report->activity}.", '/asisten/report?tab=verify');
    }

    public function handleReportReviewed(SupervisionReportReviewed $event): void
    {
        $report = $event->report;
        $verified = $report->status === 'verified';

        Notify::send([$report->user_id], 'report', $verified ? 'Laporan ngawas diverifikasi' : 'Laporan ngawas dikembalikan',
            $verified ? "Laporan {$report->date->translatedFormat('d M Y')} masuk rekap." : "Perbaiki laporan {$report->date->translatedFormat('d M Y')}: {$report->return_note}",
            '/asisten/report?month='.$report->date->format('Y-m'));
    }

    public function handleExportFinished(ExportFinished $event): void
    {
        $export = $event->export;
        $ngawas = $export->type === 'ngawas';
        $body = match ($export->status) {
            'done' => ($ngawas ? 'Rekap Jam Ngawas (Excel, Draft) ' : 'Rekap ').$export->params_json['month'].' siap diunduh (berlaku 24 jam).',
            'empty' => 'Tidak ada laporan pada periode '.$export->params_json['month'].'.',
            default => $ngawas && $export->error ? 'Rekap Excel gagal dibuat: '.$export->error : 'Rekap gagal dibuat. Coba lagi.',
        };
        $link = ($ngawas ? '/asisten/ngawas-recap?month=' : '/asisten/generate?month=').$export->params_json['month'];

        Notify::send([$export->user_id], 'export', $ngawas ? 'Rekap Jam Ngawas selesai' : 'Generate rekap selesai', $body, $link);
    }

    public function handleNgawasRecapFinalized(NgawasRecapFinalized $event): void
    {
        $label = $this->monthLabel($event->recap->month);
        $rev = $event->recap->revision > 0 ? " (revisi {$event->recap->revision})" : '';

        Notify::send($event->userIds, 'export', 'Rekap Jam Ngawas Final', "Rekap Jam Ngawas {$label}{$rev} sudah ditetapkan Final. Lihat jam ngawas Anda di halaman rekap.", '/asisten/ngawas-recap?month='.$event->recap->month);
    }

    public function handleNgawasRecapReopened(NgawasRecapReopened $event): void
    {
        $label = $this->monthLabel($event->recap->month);

        Notify::send($event->userIds, 'export', 'Rekap Jam Ngawas direvisi', "Rekap {$label} dibuka kembali (revisi {$event->recap->revision}). Catatan: {$event->note}", '/asisten/ngawas-recap?month='.$event->recap->month);
    }

    private function monthLabel(string $month): string
    {
        return CarbonImmutable::createFromFormat('!Y-m', $month)->startOfMonth()->translatedFormat('F Y');
    }
}
