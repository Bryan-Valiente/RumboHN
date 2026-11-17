<?php

declare(strict_types=1);

namespace Rumbo\Backend\Notifications;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class NotificationScheduler
{
    public function __construct(private readonly PDO $pdo, private readonly NotificationRepository $notifications)
    {
    }

    /** @return array{processedUsers: int, created: int} */
    public function process(?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $users = $this->pdo->query(
            'SELECT u.id, p.timezone, p.display_name,
                    COALESCE(np.morning_enabled, 1) AS morning_enabled,
                    COALESCE(np.evening_enabled, 1) AS evening_enabled,
                    COALESCE(np.due_enabled, 1) AS due_enabled,
                    COALESCE(np.morning_time, \'07:00:00\') AS morning_time,
                    COALESCE(np.evening_time, \'20:30:00\') AS evening_time
             FROM users u
             INNER JOIN user_profiles p ON p.user_id = u.id
             LEFT JOIN notification_preferences np ON np.user_id = u.id
             WHERE u.status = \'active\''
        )->fetchAll();
        $created = 0;

        foreach ($users as $user) {
            $timezone = new DateTimeZone((string) $user['timezone']);
            $local = $now->setTimezone($timezone);
            $date = $local->format('Y-m-d');
            if ((bool) $user['morning_enabled'] && self::isDueTime($local, (string) $user['morning_time'])) {
                $created += $this->notifications->record(
                    (string) $user['id'],
                    'morning',
                    'Resumen financiero de la mañana',
                    'Revisá tu rumbo financiero antes de comenzar el día.',
                    'info',
                    'morning:' . $date,
                    $now,
                ) ? 1 : 0;
            }
            if ((bool) $user['evening_enabled'] && self::isDueTime($local, (string) $user['evening_time'])) {
                $created += $this->notifications->record(
                    (string) $user['id'],
                    'evening',
                    'Revisión financiera de la tarde',
                    'Tomá un momento para registrar y revisar los movimientos de hoy.',
                    'info',
                    'evening:' . $date,
                    $now,
                ) ? 1 : 0;
            }
            if ((bool) $user['due_enabled']) {
                $created += $this->createDueNotifications((string) $user['id'], $local, $now);
            }
        }

        return ['processedUsers' => count($users), 'created' => $created];
    }

    private function createDueNotifications(string $userId, DateTimeImmutable $local, DateTimeImmutable $now): int
    {
        $dueComparison = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? 'due_date <= date(:today, \'+\' || reminder_days_before || \' days\')'
            : 'due_date <= DATE_ADD(:today, INTERVAL reminder_days_before DAY)';
        $statement = $this->pdo->prepare(
            'SELECT id, name, due_date, reminder_days_before
             FROM reminders
             WHERE user_id = :user_id
               AND archived_at IS NULL
               AND status IN (\'upcoming\', \'today\', \'overdue\')
               AND ' . $dueComparison
        );
        $statement->execute([':user_id' => $userId, ':today' => $local->format('Y-m-d')]);
        $created = 0;
        foreach ($statement->fetchAll() as $reminder) {
            $dueDate = (string) $reminder['due_date'];
            $created += $this->notifications->record(
                $userId,
                'due',
                'Pago próximo: ' . (string) $reminder['name'],
                'Este compromiso vence el ' . $dueDate . '.',
                $dueDate < $local->format('Y-m-d') ? 'warning' : 'info',
                'due:' . (string) $reminder['id'] . ':' . $dueDate,
                $now,
            ) ? 1 : 0;
        }
        return $created;
    }

    private static function isDueTime(DateTimeImmutable $local, string $configuredTime): bool
    {
        $configured = substr($configuredTime, 0, 5);
        if (!preg_match('/^\d{2}:\d{2}$/', $configured)) {
            return false;
        }
        [$hours, $minutes] = array_map('intval', explode(':', $configured));
        $nowMinutes = ((int) $local->format('H')) * 60 + (int) $local->format('i');
        $configuredMinutes = $hours * 60 + $minutes;
        return $nowMinutes >= $configuredMinutes && $nowMinutes < ($configuredMinutes + 15);
    }
}
