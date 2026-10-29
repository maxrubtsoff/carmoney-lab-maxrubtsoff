<?php

declare(strict_types=1);

namespace CarMoneyLab\Tests\Feature;

use CarMoneyLab\Repository\ApplicationRepository;
use CarMoneyLab\Tests\Feature\Support\ApplicationApi;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Защита от второй активной заявки одного клиента (нужна MySQL со схемой db/schema.sql
 * или с применённой db/migrations/001_active_applicant_unique.sql).
 */
final class ApplicationDuplicateGuardTest extends TestCase
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

    public function testFirstApplicationOfClientIsCreated(): void
    {
        $ref = $this->api->newApplicantRef();

        $result = $this->api->createApplication(ApplicationApi::validPayload($ref));

        self::assertSame(201, $result['status']);
        self::assertSame(1, $this->api->countApplications($ref, activeOnly: true));
    }

    public function testFirstApplicationKeepsResponseBodyShape(): void
    {
        $ref = $this->api->newApplicantRef();

        $result = $this->api->createApplication(ApplicationApi::validPayload($ref));

        self::assertSame(
            ['id', 'vehicle_age', 'ltv', 'decision', 'approved_limit'],
            array_keys($result['body']),
        );
    }

    public function testSecondActiveApplicationOfSameClientReturns409(): void
    {
        $ref = $this->api->newApplicantRef();
        $this->api->createApplication(ApplicationApi::validPayload($ref));

        $result = $this->api->createApplication(ApplicationApi::validPayload($ref));

        self::assertSame(409, $result['status']);
    }

    public function testConflictBodyNamesApplicantRefInErrors(): void
    {
        $ref = $this->api->newApplicantRef();
        $this->api->createApplication(ApplicationApi::validPayload($ref));

        $result = $this->api->createApplication(ApplicationApi::validPayload($ref));

        self::assertSame(['errors'], array_keys($result['body']));
        self::assertSame(['applicant_ref'], array_keys($result['body']['errors']));
        self::assertNotSame('', $result['body']['errors']['applicant_ref']);
    }

    public function testRejectedDuplicateLeavesNoRowsInAnyTable(): void
    {
        $ref = $this->api->newApplicantRef();
        $this->api->createApplication(ApplicationApi::validPayload($ref));

        $this->api->createApplication(ApplicationApi::validPayload($ref));

        self::assertSame(
            [1, 1, 1],
            [
                $this->api->countApplications($ref),
                $this->api->countVehicles($ref),
                $this->api->countDecisions($ref),
            ],
        );
    }

    public function testNewApplicationIsAllowedAfterPreviousIsCancelled(): void
    {
        $ref = $this->api->newApplicantRef();
        $first = $this->api->createApplication(ApplicationApi::validPayload($ref));
        $this->api->cancelApplication((int) $first['body']['id']);

        $second = $this->api->createApplication(ApplicationApi::validPayload($ref));

        self::assertSame(201, $second['status']);
        self::assertSame(1, $this->api->countApplications($ref, activeOnly: true));
    }

    public function testCancelEndpointSetsCancelledStatus(): void
    {
        $ref = $this->api->newApplicantRef();
        $created = $this->api->createApplication(ApplicationApi::validPayload($ref));
        $id = (int) $created['body']['id'];

        $result = $this->api->cancelApplication($id);

        self::assertSame(
            [200, ['id' => $id, 'status' => 'cancelled']],
            [$result['status'], $result['body']],
        );
    }

    public function testCancelIsIdempotentForAlreadyCancelledApplication(): void
    {
        $ref = $this->api->newApplicantRef();
        $created = $this->api->createApplication(ApplicationApi::validPayload($ref));
        $id = (int) $created['body']['id'];
        $this->api->cancelApplication($id);

        $result = $this->api->cancelApplication($id);

        self::assertSame(200, $result['status']);
    }

    public function testCancelReturns404ForUnknownApplication(): void
    {
        $result = $this->api->cancelApplication(2147483000);

        self::assertSame(404, $result['status']);
    }

    /** @return array<string,array{string}> */
    public static function nonDigitIdSuffixes(): array
    {
        return [
            'буквы после цифр' => ['abc'],
            'дробное' => ['.5'],
            'экспонента' => ['e0'],
        ];
    }

    #[DataProvider('nonDigitIdSuffixes')]
    public function testCancelRejectsNonDigitIdAndKeepsApplicationActive(string $suffix): void
    {
        $ref = $this->api->newApplicantRef();
        $created = $this->api->createApplication(ApplicationApi::validPayload($ref));
        $id = (int) $created['body']['id'];

        $result = $this->api->cancelApplicationByRawId($id . $suffix);

        self::assertSame(
            [404, ['errors' => ['id' => 'Заявка не найдена']], 1],
            [$result['status'], $result['body'], $this->api->countApplications($ref, activeOnly: true)],
        );
    }

    public function testThirdApplicationReturns409WhileSecondAfterCancelIsActive(): void
    {
        $ref = $this->api->newApplicantRef();
        $first = $this->api->createApplication(ApplicationApi::validPayload($ref));
        $this->api->cancelApplication((int) $first['body']['id']);
        $this->api->createApplication(ApplicationApi::validPayload($ref));

        $third = $this->api->createApplication(ApplicationApi::validPayload($ref));

        self::assertSame(409, $third['status']);
    }

    public function testDifferentClientsDoNotBlockEachOther(): void
    {
        $first = $this->api->newApplicantRef();
        $second = $this->api->newApplicantRef();
        $this->api->createApplication(ApplicationApi::validPayload($first));

        $result = $this->api->createApplication(ApplicationApi::validPayload($second));

        self::assertSame(201, $result['status']);
    }

    public function testApplicantRefIsComparedByExactValue(): void
    {
        $ref = $this->api->newApplicantRef();
        $this->api->createApplication(ApplicationApi::validPayload($ref));

        $differentCase = $this->api->createApplication(ApplicationApi::validPayload(strtolower($ref)));

        self::assertSame(201, $differentCase['status']);
    }

    public function testInvalidRequestReturns422AndCreatesNoRows(): void
    {
        $ref = $this->api->newApplicantRef();
        $payload = ApplicationApi::validPayload($ref);
        $payload['vin'] = 'BAD';

        $result = $this->api->createApplication($payload);

        self::assertSame(
            [422, true, 0],
            [$result['status'], isset($result['body']['errors']['vin']), $this->api->countApplications($ref)],
        );
    }

    public function testInvalidRepeatRequestReturns422InsteadOf409(): void
    {
        $ref = $this->api->newApplicantRef();
        $this->api->createApplication(ApplicationApi::validPayload($ref));
        $payload = ApplicationApi::validPayload($ref);
        $payload['requested_amount'] = 1;

        $result = $this->api->createApplication($payload);

        self::assertSame([422, 1], [$result['status'], $this->api->countApplications($ref)]);
    }

    public function testAnonymousApplicationsAreNotProtected(): void
    {
        $first = $this->api->createApplication(ApplicationApi::validPayload());

        $second = $this->api->createApplication(ApplicationApi::validPayload());

        self::assertSame([201, 201], [$first['status'], $second['status']]);
    }

    public function testLtvEndpointStaysStatelessForRepeatedRequests(): void
    {
        $ref = $this->api->newApplicantRef();
        $payload = ApplicationApi::validPayload($ref);

        $first = $this->api->calculateLtv($payload);
        $second = $this->api->calculateLtv($payload);

        self::assertSame(
            [200, 200, 50.0, 0],
            [$first['status'], $second['status'], $second['body']['ltv'], $this->api->countApplications($ref)],
        );
    }

    /** @return array<string,array{string}> */
    public static function activeStatuses(): array
    {
        return array_combine(
            ApplicationRepository::ACTIVE_STATUSES,
            array_map(static fn (string $status): array => [$status], ApplicationRepository::ACTIVE_STATUSES),
        );
    }

    #[DataProvider('activeStatuses')]
    public function testDatabaseTreatsEveryActiveStatusAsBlocking(string $status): void
    {
        $ref = $this->api->newApplicantRef();
        $this->insertApplication($ref, $status);

        $driverCode = null;
        try {
            $this->insertApplication($ref, $status);
        } catch (PDOException $exception) {
            $driverCode = $exception->errorInfo[1] ?? null;
        }

        self::assertSame(1062, $driverCode);
    }

    public function testDatabaseAllowsSecondApplicationWhenFirstIsCancelled(): void
    {
        $ref = $this->api->newApplicantRef();
        $this->insertApplication($ref, ApplicationRepository::STATUS_CANCELLED);

        $this->insertApplication($ref, ApplicationRepository::STATUS_CANCELLED);
        $this->insertApplication($ref, 'new');

        self::assertSame(3, $this->api->countApplications($ref));
    }

    private function insertApplication(string $applicantRef, string $status): void
    {
        $this->api->pdo()->prepare(
            'INSERT INTO applications (applicant_ref, requested_amount, term_months, status)
             VALUES (:ref, 500000, 24, :status)'
        )->execute([':ref' => $applicantRef, ':status' => $status]);
    }
}
