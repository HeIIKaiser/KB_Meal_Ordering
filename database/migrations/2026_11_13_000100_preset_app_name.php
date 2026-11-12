<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The installed app used to be called "Jedáleň" with a food icon. Existing installations
 * (they have employees) get "KB Zamestnanci" (Roman, 2026-11-13); new installations use
 * APP_NAME until the admin sets "Názov aplikácie".
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('settings')->where('key', 'app_name')->exists() || ! DB::table('employees')->exists()) {
            return;
        }

        DB::table('settings')->insert(['key' => 'app_name', 'value' => json_encode('KB Zamestnanci'), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Keep the setting.
    }
};
