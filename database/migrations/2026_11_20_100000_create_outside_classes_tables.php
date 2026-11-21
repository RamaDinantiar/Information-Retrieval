<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRD Rekap Jam Ngawas 6.1: kelas lab lain yang diawas asisten IR. Sengaja
 * terpisah dari `courses`/`classes` (dipakai untuk enrollment, tugas, nilai).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outside_classes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('semester_id')->constrained()->cascadeOnDelete();
            $table->string('course_name', 150);
            $table->string('class_name', 50);
            $table->unsignedTinyInteger('credits'); // 1–6
            $table->string('origin_lab', 100);
            $table->unsignedTinyInteger('day_of_week')->nullable(); // 1 = Senin … 7
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->timestamp('lab_confirmed_at')->nullable();
            $table->string('lab_confirmation_note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->index('semester_id');
        });

        Schema::create('outside_class_assistants', function (Blueprint $table) {
            $table->foreignId('outside_class_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->primary(['outside_class_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outside_class_assistants');
        Schema::dropIfExists('outside_classes');
    }
};
