<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('osobne_cislo', 32)->unique();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('department', 100)->nullable();
            $table->string('pin_hash')->nullable();
            $table->rememberToken();
            $table->boolean('is_active')->default(true);
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->timestamp('pin_changed_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();

            $table->index(['last_name', 'first_name']);
        });

        Schema::create('meal_slots', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name', 50);
            // Used in messages: "Objednávky na {name_accusative} sú už uzavreté."
            $table->string('name_accusative', 50)->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->unsignedTinyInteger('cutoff_days_before')->default(0);
            $table->time('cutoff_time');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('price_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name', 50);
            $table->unsignedSmallInteger('sort')->default(0);
            // null = use the setting (e.g. max_extra_per_slot)
            $table->unsignedSmallInteger('per_slot_daily_limit')->nullable();
            $table->timestamps();
        });

        Schema::create('prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('price_type_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('amount_cents');
            $table->date('valid_from');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['price_type_id', 'valid_from']);
        });

        Schema::create('menus', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->foreignId('meal_slot_id')->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->unique(['date', 'meal_slot_id']);
        });

        Schema::create('menu_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('menu_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('number');
            $table->string('name');
            $table->string('allergens', 64)->nullable(); // "1,3,7"
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['menu_id', 'number']);
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->date('date');
            $table->foreignId('meal_slot_id')->constrained()->restrictOnDelete();
            $table->foreignId('menu_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('price_type_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('unit_price_cents');
            $table->string('status', 16)->default('active');
            // 1 when this is an active donated order, NULL otherwise. Together with the
            // unique index below it makes a second active donated order per
            // employee/date/slot impossible at the database level.
            $table->unsignedTinyInteger('donated_guard')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'date', 'meal_slot_id', 'donated_guard'], 'orders_one_donated_unique');
            $table->index(['date', 'meal_slot_id', 'status']);
            $table->index(['employee_id', 'date']);
        });

        Schema::create('slot_locks', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->foreignId('meal_slot_id')->constrained()->restrictOnDelete();
            $table->foreignId('locked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('locked_at');
            $table->timestamps();

            $table->unique(['date', 'meal_slot_id']);
        });

        Schema::create('closed_days', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->foreignId('meal_slot_id')->nullable()->constrained()->restrictOnDelete(); // null = whole day
            $table->string('note')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('date');
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->id(); // numeric id so the activity log can reference it
            $table->string('key', 64)->unique();
            $table->text('value')->nullable(); // JSON-encoded
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['settings', 'closed_days', 'slot_locks', 'orders', 'menu_items', 'menus', 'prices', 'price_types', 'meal_slots', 'employees'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
