<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Number of the person in the attendance system (WebVisitor PERSONALNUMBER) when it
 * differs from osobné číslo, e.g. self-employed with numbers from Pramen. NULL = osobné číslo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('attendance_cislo', 16)->nullable()->unique()->after('vema_cislo');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropUnique(['attendance_cislo']);
            $table->dropColumn('attendance_cislo');
        });
    }
};
