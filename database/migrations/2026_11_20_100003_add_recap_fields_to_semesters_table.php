<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Kop rekap ngawas: nomor SK dan label semester. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('semesters', function (Blueprint $table) {
            $table->string('sk_number', 80)->nullable()->after('end_date');
            $table->string('term_label', 80)->nullable()->after('sk_number');
        });
    }

    public function down(): void
    {
        Schema::table('semesters', function (Blueprint $table) {
            $table->dropColumn(['sk_number', 'term_label']);
        });
    }
};
