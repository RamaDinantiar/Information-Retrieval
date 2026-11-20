<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FR-RJN-01/03. Laporan lama otomatis `held` dan bukan di luar ruang lab.
 * `class_id` sudah nullable sejak tabel dibuat; aturan "tepat salah satu
 * class_id / outside_class_id" ditegakkan di SaveSupervisionReport.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supervision_reports', function (Blueprint $table) {
            $table->string('execution_status', 20)->default('held')->after('status');
            $table->string('exam_type', 3)->nullable()->after('execution_status'); // uts | uas
            $table->date('original_date')->nullable()->after('exam_type');
            $table->time('original_start_time')->nullable()->after('original_date');
            $table->string('replacement_note', 255)->nullable()->after('original_start_time');
            $table->boolean('held_outside_lab')->default(false)->after('replacement_note');
            $table->string('outside_location', 150)->nullable()->after('held_outside_lab');
            $table->foreignId('outside_class_id')->nullable()->after('class_id')->constrained('outside_classes')->nullOnDelete();

            $table->index(['status', 'date']);
            $table->index(['class_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::table('supervision_reports', function (Blueprint $table) {
            $table->dropIndex(['status', 'date']);
            $table->dropIndex(['class_id', 'date']);
            $table->dropConstrainedForeignId('outside_class_id');
            $table->dropColumn(['execution_status', 'exam_type', 'original_date', 'original_start_time', 'replacement_note', 'held_outside_lab', 'outside_location']);
        });
    }
};
