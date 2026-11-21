<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** FR-RJN-04: libur nasional dan periode UTS/UAS. Minggu dideteksi otomatis. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_calendar_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('semester_id')->constrained()->cascadeOnDelete();
            $table->date('date_from');
            $table->date('date_to');
            $table->string('type', 20); // national_holiday | uts | uas
            $table->string('label', 150);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['semester_id', 'date_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_calendar_events');
    }
};
