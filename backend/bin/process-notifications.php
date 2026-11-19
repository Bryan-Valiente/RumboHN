<?php

declare(strict_types=1);

use Rumbo\Backend\Config;
use Rumbo\Backend\Database;
use Rumbo\Backend\Notifications\NotificationRepository;
use Rumbo\Backend\Notifications\NotificationScheduler;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este comando solo puede ejecutarse desde CLI.\n");
    exit(1);
}

require dirname(__DIR__) . '/src/bootstrap.php';

$pdo = (new Database(new Config()))->connection();
$result = (new NotificationScheduler($pdo, new NotificationRepository($pdo)))->process();
printf("Procesados: %d. Notificaciones creadas: %d.\n", $result['processedUsers'], $result['created']);
