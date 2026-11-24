<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rekap Nilai Laporan Praktikum: nilai laporan terdiri dari Teori, Praktikum, dan (bila
     * mata kuliah memakainya) Video. `grades.score` tetap menyimpan TOTAL (rata-rata komponen).
     */
    public function up(): void
    {
        Schema::table('grades', function (Blueprint $table) {
            $table->decimal('score_theory', 6, 2)->nullable()->after('score');
            $table->decimal('score_practice', 6, 2)->nullable()->after('score_theory');
            $table->decimal('score_video', 6, 2)->nullable()->after('score_practice');
        });

        Schema::table('courses', function (Blueprint $table) {
            $table->boolean('report_has_video')->default(false)->after('weight_report');
        });
    }

    public function down(): void
    {
        Schema::table('grades', fn (Blueprint $table) => $table->dropColumn(['score_theory', 'score_practice', 'score_video']));
        Schema::table('courses', fn (Blueprint $table) => $table->dropColumn('report_has_video'));
    }
};
