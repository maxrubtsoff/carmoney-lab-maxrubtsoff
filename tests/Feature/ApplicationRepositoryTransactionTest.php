<?php

declare(strict_types=1);

namespace CarMoneyLab\Tests\Feature;

use CarMoneyLab\Domain\DuplicateActiveApplicationException;
use CarMoneyLab\Repository\ApplicationRepository;
use CarMoneyLab\Tests\Feature\Support\ApplicationApi;
use CarMoneyLab\Tests\Feature\Support\FailingPdo;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Транзакция в ApplicationRepository::save(): сбой на любой из трёх вставок не оставляет
 * строк, а любая ошибка, кроме нарушения индекса активной заявки, не превращается в 409.
 * Сбои настоящие (строгий режим MySQL) либо подставные через FailingPdo.
 */
final class ApplicationRepositoryTransactionTest extends TestCase
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

    public function testVehicleInsertFailureLeavesNoApplicationRow(): void
    {
        $ref = $this->api->newApplicantRef();
        $input = $this->validInput();
        $input['vin'] = str_repeat('X', 40); // CHAR(17): строгий режим отвергает вставку в vehicles

        $this->saveExpectingFailure($this->api->pdo(), $ref, $input, $this->validAssessment());

        self::assertSame([0, 0, 0], $this->rowCounts($ref));
    }

    public function testDecisionInsertFailureLeavesNoRowsInAnyTable(): void
    {
        $ref = $this->api->newApplicantRef();
        $assessment = $this->validAssessment();
        $assessment['decision'] = 'bogus'; // не входит в ENUM decisions.decision: падает третья вставка

        $this->saveExpectingFailure($this->api->pdo(), $ref, $this->validInput(), $assessment);

        self::assertSame([0, 0, 0], $this->rowCounts($ref));
    }

    public function testRealDatabaseErrorIsRethrownNotConvertedToConflict(): void
    {
        $input = $this->validInput();
        $input['vin'] = str_repeat('X', 40);

        $failure = $this->saveExpectingFailure(
            $this->api->pdo(),
            $this->api->newApplicantRef(),
            $input,
            $this->validAssessment(),
        );

        self::assertInstanceOf(PDOException::class, $failure);
    }

    public function testConnectionIsNotLeftInsideTransactionAfterFailure(): void
    {
        $input = $this->validInput();
        $input['vin'] = str_repeat('X', 40);

        $this->saveExpectingFailure($this->api->pdo(), $this->api->newApplicantRef(), $input, $this->validAssessment());

        self::assertFalse($this->api->pdo()->inTransaction());
    }

    public function testDuplicateKeyOfOtherIndexIsRethrownAndRolledBack(): void
    {
        $ref = $this->api->newApplicantRef();
        $pdo = FailingPdo::connect();
        $pdo->failOn(
            'INSERT INTO vehicles',
            "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '1' for key 'vehicles.PRIMARY'",
            1062,
        );

        $failure = $this->saveExpectingFailure($pdo, $ref, $this->validInput(), $this->validAssessment());

        self::assertSame(
            [PDOException::class, [0, 0, 0], false],
            [$failure === null ? null : $failure::class, $this->rowCounts($ref), $pdo->inTransaction()],
        );
    }

    public function testDuplicateKeyOfActiveApplicantIndexBecomesConflictException(): void
    {
        $ref = $this->api->newApplicantRef();
        $this->api->createApplication(ApplicationApi::validPayload($ref));
        // Обычный PDO: настоящий 1062 даёт сам индекс при INSERT INTO applications второй активной заявки.
        $failure = $this->saveExpectingFailure(
            $this->api->pdo(),
            $ref,
            $this->validInput(),
            $this->validAssessment(),
        );

        self::assertInstanceOf(DuplicateActiveApplicationException::class, $failure);
    }

    public function testSuccessfulSaveWritesAllThreeTables(): void
    {
        $ref = $this->api->newApplicantRef();

        (new ApplicationRepository($this->api->pdo()))->save($ref, $this->validInput(), $this->validAssessment());

        self::assertSame([1, 1, 1], $this->rowCounts($ref));
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed> $assessment
     */
    private function saveExpectingFailure(PDO $pdo, string $ref, array $input, array $assessment): ?Throwable
    {
        try {
            (new ApplicationRepository($pdo))->save($ref, $input, $assessment);
        } catch (Throwable $exception) {
            return $exception;
        }

        return null;
    }

    /** @return array{int,int,int} заявки, автомобили, решения клиента */
    private function rowCounts(string $ref): array
    {
        return [
            $this->api->countApplications($ref),
            $this->api->countVehicles($ref),
            $this->api->countDecisions($ref),
        ];
    }

    /** @return array{vin:string,year:int,mileage:int,market_value:int,requested_amount:int,term_months:int} */
    private function validInput(): array
    {
        return [
            'vin' => 'XTA21099998765432',
            'year' => (int) date('Y') - 4,
            'mileage' => 80000,
            'market_value' => 1000000,
            'requested_amount' => 500000,
            'term_months' => 24,
        ];
    }

    /** @return array{ltv:float,decision:string,approved_limit:int} */
    private function validAssessment(): array
    {
        return ['ltv' => 50.0, 'decision' => 'approve', 'approved_limit' => 500000];
    }
}
