<?php

declare(strict_types=1);

namespace CarMoneyLab\Tests\Feature;

use CarMoneyLab\Repository\ApplicationRepository;
use CarMoneyLab\Tests\Feature\Support\ApplicationApi;
use PHPUnit\Framework\TestCase;

/**
 * Список активных статусов в коде и в DDL — две копии одного правила; тест держит их равными.
 * Выражение читается из живой базы, поэтому ловит расхождение и в db/schema.sql,
 * и в ApplicationRepository::ACTIVE_STATUSES, и в базе, не получившей миграцию.
 */
final class ActiveStatusesSchemaTest extends TestCase
{
    private ApplicationApi $api;

    protected function setUp(): void
    {
        $this->api = ApplicationApi::connect();
    }

    public function testActiveStatusesInGeneratedColumnMatchRepositoryConstant(): void
    {
        $inDatabase = $this->activeStatusesFromGenerationExpression();
        $inCode = ApplicationRepository::ACTIVE_STATUSES;
        sort($inCode);

        self::assertSame($inCode, $inDatabase);
    }

    public function testEveryActiveStatusExistsInStatusEnum(): void
    {
        $enumValues = $this->statusEnumValues();

        self::assertSame(
            [],
            array_values(array_diff(ApplicationRepository::ACTIVE_STATUSES, $enumValues)),
        );
    }

    public function testCancelledStatusIsNotActive(): void
    {
        self::assertNotContains(ApplicationRepository::STATUS_CANCELLED, ApplicationRepository::ACTIVE_STATUSES);
    }

    /** @return list<string> отсортированные статусы из IN (...) в выражении active_applicant_ref */
    private function activeStatusesFromGenerationExpression(): array
    {
        $statement = $this->api->pdo()->prepare(
            "SELECT GENERATION_EXPRESSION FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'applications' AND COLUMN_NAME = 'active_applicant_ref'"
        );
        $statement->execute();
        $expression = $statement->fetchColumn();

        self::assertIsString($expression, 'В applications нет колонки active_applicant_ref: схема или миграция 001 не применена');
        self::assertSame(
            1,
            preg_match('/`status`\s+in\s*\(([^)]*)\)/i', $expression, $list),
            'В выражении active_applicant_ref не найден список IN по status: ' . $expression,
        );

        // MySQL хранит выражение с экранированными кавычками: _utf8mb4\'new\'.
        preg_match_all("/\\\\*'([^'\\\\]*)\\\\*'/", $list[1], $matches);
        $statuses = $matches[1];
        sort($statuses);

        return $statuses;
    }

    /** @return list<string> */
    private function statusEnumValues(): array
    {
        $statement = $this->api->pdo()->prepare(
            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'applications' AND COLUMN_NAME = 'status'"
        );
        $statement->execute();
        preg_match_all("/'([^']*)'/", (string) $statement->fetchColumn(), $matches);

        return $matches[1];
    }
}
