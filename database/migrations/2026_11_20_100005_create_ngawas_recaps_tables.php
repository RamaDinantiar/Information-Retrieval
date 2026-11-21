<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FR-RJN-08/09: status rekap bulanan (draft → final → buka kembali).
 * Tidak ada tabel konfirmasi asisten (keputusan P5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ngawas_recaps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('semester_id')->constrained()->cascadeOnDelete();
            $table->char('month', 7); // YYYY-MM
            $table->string('status', 10)->default('draft'); // draft | final
            $table->unsignedSmallInteger('revision')->default(0);
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finalized_at')->nullable();
            $table->string('reopened_note', 500)->nullable();
            $table->json('snapshot_json')->nullable();
            $table->foreignId('file_id')->nullable()->constrained('files')->nullOnDelete();
            $table->timestamps();

            $table->unique(['semester_id', 'month']);
        });

        Schema::create('ngawas_recap_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ngawas_recap_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('revision');
            $table->foreignId('file_id')->nullable()->constrained('files')->nullOnDelete();
            $table->string('note', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['ngawas_recap_id', 'revision']);
        });

        Schema::create('ngawas_recap_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('semester_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->json('class_order_json')->nullable();
            $table->timestamps();

            $table->unique(['semester_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ngawas_recap_orders');
        Schema::dropIfExists('ngawas_recap_revisions');
        Schema::dropIfExists('ngawas_recaps');
    }
};
