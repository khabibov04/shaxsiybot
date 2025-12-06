<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('telegram_user_id')->constrained('telegram_users')->onDelete('cascade');
            
            $table->enum('role', ['user', 'assistant', 'system']);
            $table->text('message');
            $table->json('context_data')->nullable(); // Additional context for AI
            
            $table->integer('tokens_used')->nullable();
            
            $table->timestamps();
            
            $table->index(['telegram_user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_histories');
    }
};


