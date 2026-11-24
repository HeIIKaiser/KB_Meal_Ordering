<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Master-sheet import (2026-11-24): cost-centre number per employee (accounting groups
 * exports by it) and alternative group names used in the import ("Forma stravy").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('stredisko_cislo', 16)->nullable()->index()->after('department');
        });
        Schema::table('meal_groups', function (Blueprint $table) {
            $table->json('import_aliases')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('meal_groups', function (Blueprint $table) {
            $table->dropColumn('import_aliases');
        });
        Schema::table('employees', function (Blueprint $table) {
            $table->dropIndex(['stredisko_cislo']);
            $table->dropColumn('stredisko_cislo');
        });
    }
};
