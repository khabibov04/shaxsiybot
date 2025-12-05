# Shaxsiy Bot - Personal Task & Finance Manager

A comprehensive Telegram bot for personal task management, financial tracking, and AI-powered assistance built with Laravel.

## Features

### 📋 Task Management
- Daily, weekly, monthly, yearly, and custom range tasks
- Priority levels (High/Medium/Low)
- Categories and tags (#work, #home, #personal, #finance)
- Recurring tasks (daily, weekly, monthly, yearly)
- Morning daily plan and evening summary
- Task rating (1-5 stars)
- Task optimization: AI suggests difficult tasks for morning

### 💰 Financial Tracking
- Income and expense tracking
- Automatic category assignment
- Reports: daily, weekly, monthly, yearly, custom range
- Budget alerts and limits
- Charts and analysis
- Category-based forecasting

### 💳 Debt Management
- Track given and received debts
- Due date tracking with reminders
- Partial payment support
- Automatic overdue notifications
- Debt balance calculation

### 📅 Calendar
- Daily, weekly, monthly, yearly views
- Shows tasks, income/expenses, debts
- Interactive navigation
- Custom date range selection

### 🤖 AI Assistant
- Analyze tasks, debts, and expenses
- Personalized recommendations
- Natural language queries
- Progress monitoring

### 🔔 Smart Notifications
- Morning reminders with daily plan
- Evening summaries
- Debt due reminders
- Budget alerts
- Priority-based notifications

### 📊 Gamification
- Points for completing tasks
- Achievement badges
- Streak tracking
- Progress levels

### 💱 Currency Support
- Multiple currencies (USD, EUR, RUB, UZS)
- Automatic exchange rate updates

### 📤 Data Management
- Export to JSON
- Import from JSON backup
- Multimedia attachments support

## Shortcut Commands

| Command | Description |
|---------|-------------|
| `/today` | View today's tasks |
| `/week` | View this week's tasks |
| `/month` | View this month's tasks |
| `/year` | View yearly overview |
| `/balance` | Check current balance |
| `/debts` | View active debts |
| `/addtask` | Add a new task |
| `/income` | Add income |
| `/expense` | Add expense |
| `/stats` | View statistics |
| `/export` | Export your data |
| `/settings` | Bot settings |
| `/ai [question]` | Ask AI assistant |
| `/cancel` | Cancel current action |
| `/help` | Show all commands |

### Quick Expense Entry
Simply type: `50 food lunch at cafe`
The bot will automatically create an expense entry with auto-categorization.

## Installation

### Requirements
- PHP 8.2+
- MySQL 8.0+ or PostgreSQL
- Composer
- Node.js & NPM (for frontend assets)

### Setup

1. **Clone the repository**
```bash
git clone <repository-url>
cd shaxsiybot
```

2. **Install dependencies**
```bash
composer install
npm install
```

3. **Configure environment**
```bash
cp .env.example .env
php artisan key:generate
```

4. **Edit `.env` file with your settings:**
```env
DB_DATABASE=shaxsiybot
DB_USERNAME=your_username
DB_PASSWORD=your_password

TELEGRAM_BOT_TOKEN=your_bot_token_here
TELEGRAM_WEBHOOK_URL=https://yourdomain.com/api/telegram/webhook
TELEGRAM_BOT_USERNAME=your_bot_username

# Optional: For AI features
OPENAI_API_KEY=your_openai_key
```

5. **Run migrations**
```bash
php artisan migrate
```

6. **Set up the webhook**
```bash
# Via artisan command or API
curl -X POST https://yourdomain.com/api/telegram/set-webhook?url=https://yourdomain.com/api/telegram/webhook
```

### Running Locally (for development)

1. **Start the Laravel server**
```bash
php artisan serve
```

2. **Use ngrok for webhook testing**
```bash
ngrok http 8000
```

3. **Set webhook to ngrok URL**
```bash
curl -X POST http://localhost:8000/api/telegram/set-webhook?url=https://your-ngrok-url.ngrok.io/api/telegram/webhook
```

### Scheduled Tasks

Add this to your crontab for scheduled notifications:
```bash
* * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
```

Or run the scheduler manually:
```bash
php artisan schedule:work
```

## API Endpoints

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/telegram/webhook` | POST | Telegram webhook |
| `/api/telegram/set-webhook` | POST | Set webhook URL |
| `/api/telegram/delete-webhook` | POST | Delete webhook |
| `/api/telegram/webhook-info` | GET | Get webhook info |
| `/api/telegram/health` | GET | Health check |

## Console Commands

| Command | Description |
|---------|-------------|
| `telegram:morning-notifications` | Send morning notifications |
| `telegram:evening-notifications` | Send evening summaries |
| `telegram:task-reminders` | Send task reminders |
| `telegram:debt-reminders` | Send debt reminders |
| `telegram:process-recurring-tasks` | Create recurring task instances |
| `telegram:update-currency-rates` | Update exchange rates |

## Architecture

```
app/
├── Console/Commands/          # Scheduled commands
├── Http/Controllers/          # API controllers
├── Models/                    # Eloquent models
├── Providers/                 # Service providers
└── Services/
    └── Telegram/
        ├── TelegramBotService.php    # Core bot service
        ├── MessageHandler.php        # Message routing
        └── Handlers/                 # Feature handlers
            ├── TaskHandler.php
            ├── FinanceHandler.php
            ├── DebtHandler.php
            ├── CalendarHandler.php
            ├── SettingsHandler.php
            ├── AIHandler.php
            └── StateHandler.php
```

## Database Schema

- `telegram_users` - Bot users with settings and gamification data
- `tasks` - Tasks with priorities, categories, recurrence
- `transactions` - Income and expense records
- `debts` - Debt tracking with reminders
- `reminders` - Scheduled reminders
- `media_files` - Attached files
- `chat_histories` - AI chat history
- `currency_rates` - Exchange rates
- `user_achievements` - Gamification achievements
- `sync_queue` - Offline sync queue

## Contributing

1. Fork the repository
2. Create a feature branch
3. Commit your changes
4. Push to the branch
5. Create a Pull Request

## License

MIT License

## Support

For issues and feature requests, please use the GitHub issues page.
