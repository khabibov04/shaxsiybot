<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('telegram_user_id')->constrained('telegram_users')->onDelete('cascade');
            
            $table->enum('type', ['income', 'expense']);
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3)->default('USD');
            $table->decimal('amount_usd', 15, 2)->nullable(); // Converted amount for reports
            
            $table->string('category');
            $table->string('subcategory')->nullable();
            $table->text('note')->nullable();
            
            $table->date('date');
            $table->time('time')->nullable();
            
            // Auto-categorization confidence
            $table->boolean('auto_categorized')->default(false);
            $table->float('category_confidence')->nullable();
            
            // Recurring transaction
            $table->boolean('is_recurring')->default(false);
            $table->enum('recurrence_type', ['daily', 'weekly', 'monthly', 'yearly'])->nullable();
            $table->integer('recurrence_interval')->default(1);
            $table->date('recurrence_end_date')->nullable();
            
            // Location (optional)
            $table->string('location')->nullable();
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['telegram_user_id', 'date']);
            $table->index(['telegram_user_id', 'type']);
            $table->index(['telegram_user_id', 'category']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};

