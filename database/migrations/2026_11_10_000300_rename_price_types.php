<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Roman (2026-11-10): the 1,50 € meal is "Zamestnanecká strava" (mandatory employer
 * contribution, not a gift) and the 6,00 € meal is "Dokupovaná strava".
 * Only renames the display names; codes (donated/extra) stay.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('price_types')->where('code', 'donated')->where('name', 'Darované')->update(['name' => 'Zamestnanecká strava']);
        DB::table('price_types')->where('code', 'extra')->where('name', 'Extra')->update(['name' => 'Dokupovaná strava']);
    }

    public function down(): void
    {
        DB::table('price_types')->where('code', 'donated')->where('name', 'Zamestnanecká strava')->update(['name' => 'Darované']);
        DB::table('price_types')->where('code', 'extra')->where('name', 'Dokupovaná strava')->update(['name' => 'Extra']);
    }
};
