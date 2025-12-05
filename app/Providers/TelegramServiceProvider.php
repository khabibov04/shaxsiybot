<?php

namespace App\Providers;

use App\Services\Telegram\TelegramBotService;
use App\Services\Telegram\MessageHandler;
use App\Services\Telegram\Handlers\TaskHandler;
use App\Services\Telegram\Handlers\FinanceHandler;
use App\Services\Telegram\Handlers\DebtHandler;
use App\Services\Telegram\Handlers\CalendarHandler;
use App\Services\Telegram\Handlers\SettingsHandler;
use App\Services\Telegram\Handlers\AIHandler;
use App\Services\Telegram\Handlers\StateHandler;
use Illuminate\Support\ServiceProvider;

class TelegramServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Register TelegramBotService as singleton
        $this->app->singleton(TelegramBotService::class, function ($app) {
            return new TelegramBotService();
        });

        // Register handlers
        $this->app->singleton(TaskHandler::class, function ($app) {
            return new TaskHandler($app->make(TelegramBotService::class));
        });

        $this->app->singleton(FinanceHandler::class, function ($app) {
            return new FinanceHandler($app->make(TelegramBotService::class));
        });

        $this->app->singleton(DebtHandler::class, function ($app) {
            return new DebtHandler($app->make(TelegramBotService::class));
        });

        $this->app->singleton(CalendarHandler::class, function ($app) {
            return new CalendarHandler($app->make(TelegramBotService::class));
        });

        $this->app->singleton(SettingsHandler::class, function ($app) {
            return new SettingsHandler($app->make(TelegramBotService::class));
        });

        $this->app->singleton(AIHandler::class, function ($app) {
            return new AIHandler($app->make(TelegramBotService::class));
        });

        $this->app->singleton(StateHandler::class, function ($app) {
            return new StateHandler(
                $app->make(TelegramBotService::class),
                $app->make(TaskHandler::class),
                $app->make(FinanceHandler::class),
                $app->make(DebtHandler::class),
                $app->make(CalendarHandler::class),
                $app->make(SettingsHandler::class)
            );
        });

        // Register MessageHandler
        $this->app->singleton(MessageHandler::class, function ($app) {
            return new MessageHandler(
                $app->make(TelegramBotService::class),
                $app->make(TaskHandler::class),
                $app->make(FinanceHandler::class),
                $app->make(DebtHandler::class),
                $app->make(CalendarHandler::class),
                $app->make(SettingsHandler::class),
                $app->make(AIHandler::class),
                $app->make(StateHandler::class)
            );
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}

