<?php

declare(strict_types=1);

namespace CarMoneyLab\Tests\Feature\Support;

use RuntimeException;

/**
 * Запускает N настоящих параллельных PHP-процессов, каждый со своим PDO, и по общему сигналу
 * шлёт из них POST /api/applications. Не последовательные вызовы и не потоки одного процесса.
 */
final class ConcurrentApplicationRunner
{
    private const READ_TIMEOUT_SECONDS = 30;

    /** Запас между командой «старт» и самим стартом: чтобы все процессы успели её получить. */
    private const START_DELAY_SECONDS = 0.5;

    /**
     * @param array<string,mixed> $payload
     * @return list<array{status:int,body:array<string,mixed>,started:float,finished:float,pid:int}>
     */
    public function run(array $payload, int $workers): array
    {
        $script = __DIR__ . '/concurrent_worker.php';
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $running = [];

        for ($i = 0; $i < $workers; $i++) {
            $process = proc_open([PHP_BINARY, $script], $descriptors, $pipes, dirname(__DIR__, 3));
            if (!is_resource($process)) {
                throw new RuntimeException('Не удалось запустить рабочий процесс');
            }
            stream_set_timeout($pipes[1], self::READ_TIMEOUT_SECONDS);
            $running[] = ['process' => $process, 'pipes' => $pipes];
        }

        // Барьер: ждём, пока каждый процесс подключится к БД и прогреется.
        foreach ($running as $worker) {
            $line = fgets($worker['pipes'][1]);
            if ($line === false || trim($line) !== 'READY') {
                throw new RuntimeException('Рабочий процесс не готов: ' . stream_get_contents($worker['pipes'][2]));
            }
        }

        $startAt = microtime(true) + self::START_DELAY_SECONDS;
        $command = json_encode(['start_at' => $startAt, 'payload' => $payload], JSON_THROW_ON_ERROR) . "\n";
        foreach ($running as $worker) {
            fwrite($worker['pipes'][0], $command);
            fclose($worker['pipes'][0]);
        }

        $results = [];
        foreach ($running as $worker) {
            $stdout = (string) stream_get_contents($worker['pipes'][1]);
            $stderr = (string) stream_get_contents($worker['pipes'][2]);
            fclose($worker['pipes'][1]);
            fclose($worker['pipes'][2]);
            $exitCode = proc_close($worker['process']);

            $lines = array_values(array_filter(
                array_map('trim', explode("\n", $stdout)),
                static fn (string $line): bool => $line !== '',
            ));
            $last = end($lines);
            if ($exitCode !== 0 || $last === false) {
                throw new RuntimeException(sprintf('Рабочий процесс завершился с кодом %d: %s', $exitCode, $stderr));
            }

            $results[] = json_decode($last, true, 512, JSON_THROW_ON_ERROR);
        }

        return $results;
    }
}
