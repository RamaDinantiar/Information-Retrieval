<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Revisi Kepala Lab (12 Nov 2026): honor dihapus dari sistem, diganti rekap jam
 * dan SKS mengawas yang dihitung langsung dari laporan terverifikasi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('honor_recaps');
        Schema::dropIfExists('honor_rates');
    }

    public function down(): void
    {
        Schema::create('honor_rates', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('rate_per_hour');
            $table->date('effective_from');
            $table->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('honor_recaps', function (Blueprint $table) {
            $table->id();
            $table->char('month', 7);
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('total_shifts');
            $table->decimal('total_hours', 7, 2);
            $table->unsignedInteger('rate_per_hour');
            $table->unsignedBigInteger('amount');
            $table->string('status', 10)->default('draft');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->unique(['month', 'user_id']);
        });
    }
};
