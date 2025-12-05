<?php

namespace App\Console\Commands;

use App\Models\Task;
use Illuminate\Console\Command;

class ProcessRecurringTasks extends Command
{
    protected $signature = 'telegram:process-recurring-tasks';
    protected $description = 'Create new instances of recurring tasks';

    public function handle(): int
    {
        $this->info('Processing recurring tasks...');

        // Get all recurring tasks that are completed and need a new instance
        $tasks = Task::where('is_recurring', true)
            ->where('status', 'completed')
            ->whereNotNull('recurrence_type')
            ->where(function ($query) {
                $query->whereNull('recurrence_end_date')
                    ->orWhere('recurrence_end_date', '>=', today());
            })
            ->get();

        $created = 0;
        $skipped = 0;

        foreach ($tasks as $task) {
            // Calculate next date
            $nextDate = match($task->recurrence_type) {
                'daily' => $task->date->addDays($task->recurrence_interval),
                'weekly' => $task->date->addWeeks($task->recurrence_interval),
                'monthly' => $task->date->addMonths($task->recurrence_interval),
                'yearly' => $task->date->addYears($task->recurrence_interval),
                default => null,
            };

            if (!$nextDate) {
                $skipped++;
                continue;
            }

            // Check if recurrence end date passed
            if ($task->recurrence_end_date && $nextDate->gt($task->recurrence_end_date)) {
                $skipped++;
                continue;
            }

            // Check if next instance already exists
            $exists = Task::where('telegram_user_id', $task->telegram_user_id)
                ->where('title', $task->title)
                ->where('is_recurring', true)
                ->whereDate('date', $nextDate)
                ->exists();

            if ($exists) {
                $skipped++;
                continue;
            }

            // Create new instance
            Task::create([
                'telegram_user_id' => $task->telegram_user_id,
                'title' => $task->title,
                'description' => $task->description,
                'priority' => $task->priority,
                'category' => $task->category,
                'tags' => $task->tags,
                'period_type' => $task->period_type,
                'date' => $nextDate,
                'time' => $task->time,
                'reminder_time' => $task->reminder_time,
                'is_recurring' => true,
                'recurrence_type' => $task->recurrence_type,
                'recurrence_days' => $task->recurrence_days,
                'recurrence_interval' => $task->recurrence_interval,
                'recurrence_end_date' => $task->recurrence_end_date,
                'estimated_duration_minutes' => $task->estimated_duration_minutes,
                'difficulty_level' => $task->difficulty_level,
            ]);

            $created++;
            $this->line("Created recurring task: {$task->title} for {$nextDate->format('Y-m-d')}");
        }

        $this->info("Created: {$created}, Skipped: {$skipped}");

        return Command::SUCCESS;
    }
}

