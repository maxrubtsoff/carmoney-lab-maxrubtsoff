<?php

declare(strict_types=1);

namespace CarMoneyLab\Tests\Feature\Support;

use PDO;
use PDOException;
use PDOStatement;

/**
 * Настоящее соединение с MySQL, которое бросает заданную PDOException на первом prepare()
 * запроса с указанным фрагментом. Нужно, чтобы проверить откат транзакции и разбор ошибок
 * в ApplicationRepository::save(): остальные запросы идут в базу как обычно.
 */
final class FailingPdo extends PDO
{
    private ?PDOException $failure = null;

    private string $failOnFragment = '';

    public static function connect(): self
    {
        return new self(
            getenv('DB_DSN') ?: 'mysql:host=db;port=3306;dbname=carmoney_lab;charset=utf8mb4',
            getenv('DB_USER') ?: 'lab',
            getenv('DB_PASSWORD') ?: 'lab',
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        );
    }

    /**
     * @param int $driverCode код ошибки драйвера (errorInfo[1]), например 1062
     */
    public function failOn(string $queryFragment, string $message, int $driverCode, string $sqlState = '23000'): void
    {
        $this->failOnFragment = $queryFragment;
        $this->failure = new PDOException($message, $driverCode);
        $this->failure->errorInfo = [$sqlState, $driverCode, $message];
    }

    /** @param array<int,mixed> $options */
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if ($this->failure !== null && str_contains($query, $this->failOnFragment)) {
            throw $this->failure;
        }

        return parent::prepare($query, $options);
    }
}
