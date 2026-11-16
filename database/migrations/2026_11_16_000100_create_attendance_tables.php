<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Attendance from WebVisitor (docs/ATTENDANCE.md): mirror of the synced days, per-month
 * results, automatic mandatory-meal charges, sync log; mandatory mode settings; employee
 * fields for the meal cap and the Vema number.
 *
 * Safe on existing data: all new columns are nullable/defaulted, nothing changes until
 * attendance data arrives or a group is switched to mandatory mode.
 */
return new class extends Migration
{
    public function up(): void
    {
        // "mandatory" needs more than the 8 characters of the ticket-mode columns.
        Schema::table('meal_group_modes', fn (Blueprint $table) => $table->string('mode', 16)->change());
        Schema::table('orders', fn (Blueprint $table) => $table->string('pricing_mode', 16)->default('daily')->change());

        Schema::table('meal_groups', function (Blueprint $table) {
            $table->unsignedTinyInteger('mandatory_per_day')->default(2)->after('default_tickets');
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->unsignedTinyInteger('max_meals_per_day')->nullable()->after('department');
            $table->string('vema_cislo', 16)->nullable()->after('osobne_cislo');
            $table->index('vema_cislo');
        });

        // Mirror of WebVisitor days (no names). Replaced month by month by each sync.
        Schema::create('attendance_days', function (Blueprint $table) {
            $table->id();
            $table->string('osobne_cislo', 32); // as sent (PERSONALNUMBER, no leading zeros)
            $table->date('date');
            $table->unsignedInteger('planned_seconds')->default(0);
            $table->string('plan_name', 60)->nullable();
            $table->unsignedInteger('total_seconds')->default(0);
            $table->json('absences')->nullable();
            $table->integer('status')->nullable();
            $table->boolean('month_locked')->default(false);
            $table->timestamp('synced_at');

            $table->unique(['osobne_cislo', 'date']);
            $table->index('date');
        });

        Schema::create('attendance_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->char('month', 7);
            $table->unsignedSmallInteger('estimate_meals')->nullable();   // ticket-mode days (null = none)
            $table->unsignedSmallInteger('final_meals')->nullable();
            $table->unsignedSmallInteger('mandatory_estimate')->nullable(); // mandatory-mode meals
            $table->unsignedSmallInteger('mandatory_final')->nullable();
            $table->json('flags')->nullable();
            $table->timestamp('computed_at')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->foreignId('finalized_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['employee_id', 'month']);
        });

        // Automatic charges of mandatory mode, per day (not orders: the kitchen never sees them).
        Schema::create('mandatory_meals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->date('date');
            $table->unsignedTinyInteger('count');
            $table->unsignedInteger('unit_price_cents');
            $table->string('source', 10); // estimate | final
            $table->timestamps();

            $table->unique(['employee_id', 'date']);
            $table->index('date');
        });

        Schema::create('attendance_syncs', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 8);            // data | status
            $table->char('month', 7)->nullable();
            $table->boolean('ok');
            $table->text('message')->nullable();
            $table->unsignedInteger('employees')->default(0);
            $table->unsignedInteger('days')->default(0);
            $table->json('unmatched')->nullable();
            $table->json('late_changes')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('received_at');

            $table->index(['kind', 'received_at']);
            $table->index(['month', 'received_at']);
        });
    }

    public function down(): void
    {
        foreach (['attendance_syncs', 'mandatory_meals', 'attendance_results', 'attendance_days'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('employees', function (Blueprint $table) {
            $table->dropIndex(['vema_cislo']);
            $table->dropColumn(['max_meals_per_day', 'vema_cislo']);
        });
        Schema::table('meal_groups', fn (Blueprint $table) => $table->dropColumn('mandatory_per_day'));
    }
};
