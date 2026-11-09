<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Soup of the day: part of the lunch menu, served to everyone, not ordered or
 * priced separately (Roman, 2026-11-09). Stored on the menu, shown to employees
 * and on the kitchen list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meal_slots', function (Blueprint $table) {
            $table->boolean('has_soup')->default(false)->after('cutoff_time');
        });

        Schema::table('menus', function (Blueprint $table) {
            $table->string('soup_name')->nullable()->after('meal_slot_id');
            $table->string('soup_allergens', 64)->nullable()->after('soup_name');
        });

        DB::table('meal_slots')->where('code', 'obed')->update(['has_soup' => true]);
    }

    public function down(): void
    {
        Schema::table('menus', fn (Blueprint $table) => $table->dropColumn(['soup_name', 'soup_allergens']));
        Schema::table('meal_slots', fn (Blueprint $table) => $table->dropColumn('has_soup'));
    }
};
