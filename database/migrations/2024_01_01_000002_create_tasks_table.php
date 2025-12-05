<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('telegram_user_id')->constrained('telegram_users')->onDelete('cascade');
            
            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('priority', ['high', 'medium', 'low'])->default('medium');
            $table->string('category')->default('other');
            $table->json('tags')->nullable(); // ['#work', '#urgent']
            
            // Time settings
            $table->enum('period_type', ['daily', 'weekly', 'monthly', 'yearly', 'custom'])->default('daily');
            $table->date('date')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->time('time')->nullable();
            $table->time('reminder_time')->nullable();
            
            // Recurring settings
            $table->boolean('is_recurring')->default(false);
            $table->enum('recurrence_type', ['daily', 'weekly', 'monthly', 'yearly'])->nullable();
            $table->json('recurrence_days')->nullable(); // [1,3,5] for Mon, Wed, Fri
            $table->integer('recurrence_interval')->default(1);
            $table->date('recurrence_end_date')->nullable();
            
            // Status
            $table->enum('status', ['pending', 'in_progress', 'completed', 'cancelled'])->default('pending');
            $table->timestamp('completed_at')->nullable();
            
            // Rating & Feedback
            $table->tinyInteger('rating')->nullable(); // 1-5
            $table->text('completion_note')->nullable();
            
            // Points earned for completing
            $table->integer('points_earned')->default(0);
            
            // For morning plan / evening summary
            $table->boolean('is_morning_plan')->default(false);
            $table->boolean('evening_reviewed')->default(false);
            
            // AI optimization suggestion
            $table->string('optimal_time_suggestion')->nullable();
            $table->integer('estimated_duration_minutes')->nullable();
            $table->integer('difficulty_level')->default(3); // 1-5, 5 being most difficult
            
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['telegram_user_id', 'date']);
            $table->index(['telegram_user_id', 'status']);
            $table->index(['telegram_user_id', 'period_type']);
            $table->index('priority');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};

