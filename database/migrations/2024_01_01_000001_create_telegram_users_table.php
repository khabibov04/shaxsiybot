<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_users', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('telegram_id')->unique();
            $table->string('username')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('language_code', 10)->default('en');
            $table->string('timezone')->default('UTC');
            $table->string('currency', 3)->default('USD');
            
            // Gamification
            $table->integer('total_points')->default(0);
            $table->string('current_badge')->default('beginner');
            $table->integer('tasks_completed')->default(0);
            $table->integer('streak_days')->default(0);
            $table->date('last_activity_date')->nullable();
            
            // Settings
            $table->boolean('morning_notifications')->default(true);
            $table->boolean('evening_notifications')->default(true);
            $table->boolean('debt_reminders')->default(true);
            $table->boolean('budget_alerts')->default(true);
            $table->time('morning_time')->default('08:00');
            $table->time('evening_time')->default('20:00');
            
            // Budget limits
            $table->decimal('daily_budget_limit', 15, 2)->nullable();
            $table->decimal('weekly_budget_limit', 15, 2)->nullable();
            $table->decimal('monthly_budget_limit', 15, 2)->nullable();
            
            // State for conversation handling
            $table->string('current_state')->nullable();
            $table->json('state_data')->nullable();
            
            $table->boolean('is_premium')->default(false);
            $table->boolean('is_blocked')->default(false);
            $table->timestamps();
            
            $table->index('telegram_id');
            $table->index('current_state');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_users');
    }
};

