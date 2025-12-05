<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_achievements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('telegram_user_id')->constrained('telegram_users')->onDelete('cascade');
            
            $table->string('achievement_key');
            $table->string('achievement_name');
            $table->string('achievement_icon');
            $table->text('description')->nullable();
            $table->integer('points_awarded')->default(0);
            
            $table->timestamp('earned_at');
            $table->timestamps();
            
            $table->unique(['telegram_user_id', 'achievement_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_achievements');
    }
};

