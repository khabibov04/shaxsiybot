<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('telegram_user_id')->constrained('telegram_users')->onDelete('cascade');
            
            $table->morphs('attachable'); // Can link to tasks, debts, transactions
            
            $table->enum('type', ['image', 'document', 'audio', 'voice', 'video', 'other']);
            $table->string('telegram_file_id');
            $table->string('file_name')->nullable();
            $table->string('mime_type')->nullable();
            $table->integer('file_size')->nullable();
            
            $table->string('local_path')->nullable();
            $table->text('transcription')->nullable(); // For voice messages
            
            $table->timestamps();
            
            $table->index(['telegram_user_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_files');
    }
};

