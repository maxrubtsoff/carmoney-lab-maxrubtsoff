<?php

declare(strict_types=1);

namespace CarMoneyLab\Tests\Feature;

use CarMoneyLab\Tests\Feature\Support\ApplicationApi;
use CarMoneyLab\Tests\Feature\Support\ConcurrentApplicationRunner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Гонка двух и более одновременных заявок одного клиента. Запросы идут из отдельных
 * PHP-процессов с отдельными соединениями PDO к MySQL (см. ConcurrentApplicationRunner).
 */
final class ConcurrentApplicationTest extends TestCase
{
    private ApplicationApi $api;

    protected function setUp(): void
    {
        $this->api = ApplicationApi::connect();
    }

    protected function tearDown(): void
    {
        $this->api->cleanUp();
    }

    /** @return array<string,array{int}> */
    public static function workerCounts(): array
    {
        return ['two workers' => [2], 'eight workers' => [8]];
    }

    #[DataProvider('workerCounts')]
    public function testConcurrentRequestsOfSameClientGiveExactlyOneCreated(int $workers): void
    {
        $ref = $this->api->newApplicantRef();

        $results = (new ConcurrentApplicationRunner())->run(ApplicationApi::validPayload($ref), $workers);

        $statuses = array_column($results, 'status');
        sort($statuses);
        self::assertSame(array_merge([201], array_fill(0, $workers - 1, 409)), $statuses);
    }

    #[DataProvider('workerCounts')]
    public function testConcurrentRequestsOfSameClientLeaveOneActiveApplication(int $workers): void
    {
        $ref = $this->api->newApplicantRef();

        (new ConcurrentApplicationRunner())->run(ApplicationApi::validPayload($ref), $workers);

        self::assertSame(
            [1, 1, 1, 1],
            [
                $this->api->countApplications($ref),
                $this->api->countApplications($ref, activeOnly: true),
                $this->api->countVehicles($ref),
                $this->api->countDecisions($ref),
            ],
        );
    }

    public function testWorkersReallyRunInParallelInSeparateProcesses(): void
    {
        $ref = $this->api->newApplicantRef();

        $results = (new ConcurrentApplicationRunner())->run(ApplicationApi::validPayload($ref), 8);

        $latestStart = max(array_column($results, 'started'));
        $earliestFinish = min(array_column($results, 'finished'));
        self::assertTrue(
            $latestStart < $earliestFinish && count(array_unique(array_column($results, 'pid'))) === 8,
            sprintf(
                'Окна выполнения запросов не пересеклись или процессы не разные: последний старт %.4f, первое завершение %.4f',
                $latestStart,
                $earliestFinish,
            ),
        );
    }
}
