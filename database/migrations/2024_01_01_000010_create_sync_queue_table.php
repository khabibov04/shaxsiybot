<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // For offline saving with later sync
        Schema::create('sync_queue', function (Blueprint $table) {
            $table->id();
            $table->foreignId('telegram_user_id')->constrained('telegram_users')->onDelete('cascade');
            
            $table->string('action'); // create, update, delete
            $table->string('model_type');
            $table->unsignedBigInteger('model_id')->nullable();
            $table->json('data');
            
            $table->boolean('is_synced')->default(false);
            $table->timestamp('synced_at')->nullable();
            $table->integer('retry_count')->default(0);
            $table->text('error_message')->nullable();
            
            $table->timestamps();
            
            $table->index(['telegram_user_id', 'is_synced']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_queue');
    }
};


