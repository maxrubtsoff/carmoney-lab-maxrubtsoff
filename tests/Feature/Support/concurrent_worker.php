<?php

declare(strict_types=1);

/**
 * Рабочий процесс для теста гонки (ConcurrentApplicationRunner): отдельный PHP-процесс
 * со своим соединением PDO к MySQL.
 *
 * Протокол: сообщает READY на stdout, ждёт в stdin строку JSON {"start_at": float, "payload": {...}},
 * крутится до start_at (общий старт у всех процессов) и отправляет POST /api/applications.
 * В stdout пишет одну строку JSON: {"status", "body", "started", "finished", "pid"}.
 */

use CarMoneyLab\AppFactory;
use Slim\Psr7\Factory\RequestFactory;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$app = AppFactory::create();

// Прогрев: маршрутизация и автозагрузка не должны попасть в окно гонки.
$app->handle((new RequestFactory())->createRequest('GET', '/health'));

fwrite(STDOUT, "READY\n");
fflush(STDOUT);

$command = json_decode((string) fgets(STDIN), true, 512, JSON_THROW_ON_ERROR);

while (microtime(true) < $command['start_at']) {
    // активное ожидание общего старта
}

$started = microtime(true);
$response = $app->handle(
    (new RequestFactory())->createRequest('POST', '/api/applications')
        ->withHeader('Content-Type', 'application/json')
        ->withParsedBody($command['payload'])
);
$finished = microtime(true);

fwrite(STDOUT, json_encode([
    'status' => $response->getStatusCode(),
    'body' => json_decode((string) $response->getBody(), true),
    'started' => $started,
    'finished' => $finished,
    'pid' => getmypid(),
], JSON_THROW_ON_ERROR) . "\n");
