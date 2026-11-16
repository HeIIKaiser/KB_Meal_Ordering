<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Temps (estimate by orders) and self-employed (fixed price, invoice settlement) —
 * docs/CATEGORIES.md. Defaults keep today's behaviour: estimate_source = plan,
 * settlement = payroll, no fixed prices.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meal_groups', function (Blueprint $table) {
            $table->string('estimate_source', 8)->default('plan')->after('mandatory_per_day'); // plan | orders
            $table->string('settlement', 8)->default('payroll')->after('estimate_source');     // payroll | invoice
            $table->unsignedTinyInteger('max_per_slot')->nullable()->after('settlement');        // fixed mode cap
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->string('billing_name', 150)->nullable();
            $table->string('ico', 12)->nullable();
            $table->string('dic', 14)->nullable();
            $table->string('billing_address', 255)->nullable();
            $table->string('billing_email', 150)->nullable();
            $table->string('billing_note', 255)->nullable();
        });

        // Fixed prices, versioned by valid_from (meal date), for a group or one person,
        // for all meals (meal_slot_id NULL) or one meal.
        Schema::create('fixed_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meal_group_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('meal_slot_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('amount_cents');
            $table->date('valid_from');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_id', 'valid_from']);
            $table->index(['meal_group_id', 'valid_from']);
        });

        // Price type of fixed-mode orders (never chosen by the daily rule).
        if (! DB::table('price_types')->where('code', 'fixed')->exists()) {
            DB::table('price_types')->insert(['code' => 'fixed', 'name' => 'Pevná cena', 'sort' => 30, 'per_slot_daily_limit' => null,
                'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_prices');
        Schema::table('employees', fn (Blueprint $table) => $table->dropColumn(['billing_name', 'ico', 'dic', 'billing_address', 'billing_email', 'billing_note']));
        Schema::table('meal_groups', fn (Blueprint $table) => $table->dropColumn(['estimate_source', 'settlement', 'max_per_slot']));
        // price type "fixed" stays (orders may reference it)
    }
};
