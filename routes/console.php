<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| This file is where you may define all of your Closure based console
| commands. Each Closure is bound to a command instance allowing a
| simple approach to interacting with each command's IO methods.
|
*/

// Morning notifications - runs every 5 minutes to catch different timezones
Schedule::command('telegram:morning-notifications')->everyFiveMinutes();

// Evening notifications - runs every 5 minutes to catch different timezones
Schedule::command('telegram:evening-notifications')->everyFiveMinutes();

// Task reminders - runs every minute for precise timing
Schedule::command('telegram:task-reminders')->everyMinute();

// Debt reminders - runs every hour
Schedule::command('telegram:debt-reminders')->hourly();

// Process recurring tasks - runs daily at midnight
Schedule::command('telegram:process-recurring-tasks')->dailyAt('00:05');

// Update currency rates - runs twice daily
Schedule::command('telegram:update-currency-rates')->twiceDaily(6, 18);
