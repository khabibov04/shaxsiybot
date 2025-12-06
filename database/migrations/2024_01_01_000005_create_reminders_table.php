<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('telegram_user_id')->constrained('telegram_users')->onDelete('cascade');
            
            $table->morphs('remindable'); // Can link to tasks, debts, etc.
            
            $table->string('title');
            $table->text('message')->nullable();
            
            $table->datetime('remind_at');
            $table->enum('type', ['once', 'recurring'])->default('once');
            $table->enum('recurrence_type', ['daily', 'weekly', 'monthly'])->nullable();
            
            $table->enum('priority', ['high', 'medium', 'low'])->default('medium');
            
            $table->boolean('is_sent')->default(false);
            $table->timestamp('sent_at')->nullable();
            
            $table->boolean('is_voice')->default(false);
            $table->string('voice_file_id')->nullable();
            
            $table->timestamps();
            
            $table->index(['telegram_user_id', 'remind_at']);
            $table->index(['is_sent', 'remind_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminders');
    }
};


