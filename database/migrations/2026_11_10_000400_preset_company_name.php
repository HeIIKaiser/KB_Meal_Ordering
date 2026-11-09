<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The organisation name used to be hard-coded ("KB SPA"). Installations that already exist
 * (they have employees) keep it as their setting; new installations start without a name and
 * use APP_COMPANY or Nastavenia → Objednávanie.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('settings')->where('key', 'company_name')->exists() || ! DB::table('employees')->exists()) {
            return;
        }

        DB::table('settings')->insert(['key' => 'company_name', 'value' => json_encode('KB SPA'), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Keep the setting; it is harmless and may have been edited since.
    }
};
