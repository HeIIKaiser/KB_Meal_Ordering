<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket mode (lístky): employee groups with a pricing mode history, group membership
 * history, monthly ticket counts, individual overrides and closed months.
 *
 * Safe on existing data: no groups exist yet, so everybody stays in daily mode;
 * existing orders get pricing_mode = daily.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meal_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->unique();
            $table->unsignedSmallInteger('default_tickets')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Pricing mode of a group from a date on (the latest row on or before a meal date applies).
        Schema::create('meal_group_modes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meal_group_id')->constrained()->cascadeOnDelete();
            $table->string('mode', 8); // daily | tickets
            $table->date('effective_from');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['meal_group_id', 'effective_from']);
        });

        // Which group an employee belongs to from a date on (meal_group_id NULL = no group).
        Schema::create('employee_group_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meal_group_id')->nullable()->constrained()->restrictOnDelete();
            $table->date('effective_from');
            $table->timestamps();

            $table->unique(['employee_id', 'effective_from']);
            $table->index('meal_group_id');
        });

        Schema::create('ticket_month_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meal_group_id')->constrained()->cascadeOnDelete();
            $table->char('month', 7); // YYYY-MM
            $table->unsignedSmallInteger('tickets');
            $table->timestamps();

            $table->unique(['meal_group_id', 'month']);
        });

        Schema::create('ticket_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->char('month', 7);
            $table->unsignedSmallInteger('tickets');
            $table->string('source', 8)->default('manual'); // manual | import
            $table->timestamps();

            $table->unique(['employee_id', 'month']);
        });

        Schema::create('closed_months', function (Blueprint $table) {
            $table->id();
            $table->char('month', 7)->unique();
            $table->timestamp('closed_at');
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('pricing_mode', 8)->default('daily')->after('unit_price_cents');
            $table->index(['employee_id', 'pricing_mode', 'date']);
        });
        DB::table('orders')->update(['pricing_mode' => 'daily']);
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['employee_id', 'pricing_mode', 'date']);
            $table->dropColumn('pricing_mode');
        });
        foreach (['closed_months', 'ticket_overrides', 'ticket_month_counts', 'employee_group_memberships', 'meal_group_modes', 'meal_groups'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
