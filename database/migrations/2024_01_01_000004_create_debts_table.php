<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('debts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('telegram_user_id')->constrained('telegram_users')->onDelete('cascade');
            
            $table->enum('type', ['given', 'received']); // given = I gave money, received = I borrowed
            $table->string('person_name');
            $table->string('person_contact')->nullable(); // Phone or username
            
            $table->decimal('amount', 15, 2);
            $table->decimal('amount_paid', 15, 2)->default(0);
            $table->string('currency', 3)->default('USD');
            
            $table->text('note')->nullable();
            $table->date('date'); // When debt was created
            $table->date('due_date')->nullable();
            
            $table->enum('status', ['active', 'partial', 'paid', 'overdue', 'cancelled'])->default('active');
            $table->timestamp('paid_at')->nullable();
            
            // Reminders
            $table->boolean('reminder_enabled')->default(true);
            $table->integer('reminder_days_before')->default(3);
            $table->timestamp('last_reminder_sent')->nullable();
            $table->integer('reminder_count')->default(0);
            
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['telegram_user_id', 'status']);
            $table->index(['telegram_user_id', 'type']);
            $table->index(['telegram_user_id', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('debts');
    }
};


