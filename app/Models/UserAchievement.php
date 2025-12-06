<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserAchievement extends Model
{
    use HasFactory;

    protected $fillable = [
        'telegram_user_id',
        'achievement_key',
        'achievement_name',
        'achievement_icon',
        'description',
        'points_awarded',
        'earned_at',
    ];

    protected $casts = [
        'earned_at' => 'datetime',
    ];

    public function telegramUser(): BelongsTo
    {
        return $this->belongsTo(TelegramUser::class);
    }

    public static function getAvailableAchievements(): array
    {
        return [
            'first_task' => [
                'name' => 'First Step',
                'icon' => '🎯',
                'description' => 'Complete your first task',
                'points' => 10,
            ],
            'task_streak_7' => [
                'name' => 'Week Warrior',
                'icon' => '🔥',
                'description' => 'Complete tasks 7 days in a row',
                'points' => 50,
            ],
            'task_streak_30' => [
                'name' => 'Monthly Master',
                'icon' => '🏆',
                'description' => 'Complete tasks 30 days in a row',
                'points' => 200,
            ],
            'tasks_10' => [
                'name' => 'Getting Started',
                'icon' => '⭐',
                'description' => 'Complete 10 tasks',
                'points' => 20,
            ],
            'tasks_50' => [
                'name' => 'Productive',
                'icon' => '🌟',
                'description' => 'Complete 50 tasks',
                'points' => 100,
            ],
            'tasks_100' => [
                'name' => 'Task Master',
                'icon' => '👑',
                'description' => 'Complete 100 tasks',
                'points' => 250,
            ],
            'high_priority_5' => [
                'name' => 'Priority Handler',
                'icon' => '🔴',
                'description' => 'Complete 5 high priority tasks',
                'points' => 30,
            ],
            'budget_keeper' => [
                'name' => 'Budget Keeper',
                'icon' => '💰',
                'description' => 'Stay within budget for a month',
                'points' => 100,
            ],
            'debt_free' => [
                'name' => 'Debt Free',
                'icon' => '🆓',
                'description' => 'Pay off all debts',
                'points' => 150,
            ],
            'early_bird' => [
                'name' => 'Early Bird',
                'icon' => '🌅',
                'description' => 'Complete 10 tasks before 9 AM',
                'points' => 50,
            ],
            'night_owl' => [
                'name' => 'Night Owl',
                'icon' => '🦉',
                'description' => 'Complete 10 tasks after 9 PM',
                'points' => 50,
            ],
            'perfect_week' => [
                'name' => 'Perfect Week',
                'icon' => '💯',
                'description' => 'Complete all planned tasks in a week',
                'points' => 100,
            ],
        ];
    }

    public static function award(TelegramUser $user, string $achievementKey): ?UserAchievement
    {
        $achievements = self::getAvailableAchievements();
        
        if (!isset($achievements[$achievementKey])) {
            return null;
        }
        
        // Check if already earned
        if ($user->achievements()->where('achievement_key', $achievementKey)->exists()) {
            return null;
        }
        
        $achievement = $achievements[$achievementKey];
        
        $userAchievement = self::create([
            'telegram_user_id' => $user->id,
            'achievement_key' => $achievementKey,
            'achievement_name' => $achievement['name'],
            'achievement_icon' => $achievement['icon'],
            'description' => $achievement['description'],
            'points_awarded' => $achievement['points'],
            'earned_at' => now(),
        ]);
        
        $user->addPoints($achievement['points']);
        
        return $userAchievement;
    }
}


