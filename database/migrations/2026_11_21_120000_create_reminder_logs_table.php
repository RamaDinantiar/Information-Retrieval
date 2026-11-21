<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Pencatat pengingat harian: mencegah kirim ganda dan membatasi jumlah pengulangan per objek. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reminder_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 30);
            $table->string('subject', 100);
            $table->date('sent_on');
            $table->timestamps();

            $table->unique(['user_id', 'kind', 'subject', 'sent_on']);
            $table->index(['user_id', 'kind', 'subject']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminder_logs');
    }
};
