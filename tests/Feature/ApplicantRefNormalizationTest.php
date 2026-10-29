<?php

declare(strict_types=1);

namespace CarMoneyLab\Tests\Feature;

use CarMoneyLab\Domain\ApplicationValidator;
use CarMoneyLab\Tests\Feature\Support\ApplicationApi;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Обработка applicant_ref в POST /api/applications: trim, аноним, 422 на невалидное значение
 * (нужна MySQL со схемой db/schema.sql).
 */
final class ApplicantRefNormalizationTest extends TestCase
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

    /** @return array<string,array{mixed}> значения, которые означают анонима */
    public static function anonymousValues(): array
    {
        return [
            'пустая строка' => [''],
            'только пробелы' => ['   '],
            'пробелы и перевод строки' => [" \t\n "],
            'null' => [null],
        ];
    }

    #[DataProvider('anonymousValues')]
    public function testEmptyApplicantRefIsStoredAsAnonymous(mixed $value): void
    {
        $payload = ApplicationApi::validPayload();
        $payload['applicant_ref'] = $value;

        $result = $this->api->createApplication($payload);

        self::assertSame(
            [201, ApplicationValidator::ANONYMOUS_APPLICANT],
            [$result['status'], $this->api->storedApplicantRef((int) $result['body']['id'])],
        );
    }

    #[DataProvider('anonymousValues')]
    public function testRepeatedEmptyApplicantRefIsNotBlocked(mixed $value): void
    {
        $payload = ApplicationApi::validPayload();
        $payload['applicant_ref'] = $value;
        $this->api->createApplication($payload);

        $second = $this->api->createApplication($payload);

        self::assertSame(201, $second['status']);
    }

    public function testMissingApplicantRefIsStoredAsAnonymous(): void
    {
        $result = $this->api->createApplication(ApplicationApi::validPayload());

        self::assertSame(
            ApplicationValidator::ANONYMOUS_APPLICANT,
            $this->api->storedApplicantRef((int) $result['body']['id']),
        );
    }

    public function testApplicantRefIsTrimmedBeforeSaving(): void
    {
        $ref = $this->api->newApplicantRef();

        $result = $this->api->createApplication(ApplicationApi::validPayload('  ' . $ref . " \n"));

        self::assertSame(
            [201, $ref],
            [$result['status'], $this->api->storedApplicantRef((int) $result['body']['id'])],
        );
    }

    public function testApplicantRefWithSurroundingSpacesIsTheSameClient(): void
    {
        $ref = $this->api->newApplicantRef();
        $this->api->createApplication(ApplicationApi::validPayload($ref));

        $result = $this->api->createApplication(ApplicationApi::validPayload(' ' . $ref . ' '));

        self::assertSame(409, $result['status']);
    }

    public function testApplicantRefOfMaximumLengthIsAccepted(): void
    {
        $ref = $this->api->newApplicantRef(str_repeat('x', ApplicationValidator::APPLICANT_REF_MAX_LENGTH - 16));

        $result = $this->api->createApplication(ApplicationApi::validPayload($ref));

        self::assertSame(
            [201, ApplicationValidator::APPLICANT_REF_MAX_LENGTH],
            [$result['status'], mb_strlen($this->api->storedApplicantRef((int) $result['body']['id']), 'UTF-8')],
        );
    }

    public function testMultibyteApplicantRefIsLimitedByCharactersNotBytes(): void
    {
        $ref = $this->api->newApplicantRef(str_repeat('я', ApplicationValidator::APPLICANT_REF_MAX_LENGTH - 16));

        $result = $this->api->createApplication(ApplicationApi::validPayload($ref));

        self::assertSame(201, $result['status']);
    }

    public function testApplicantRefLongerThanColumnReturns422(): void
    {
        $ref = $this->api->newApplicantRef(str_repeat('x', ApplicationValidator::APPLICANT_REF_MAX_LENGTH - 15));
        $before = $this->api->totalApplications();

        $result = $this->api->createApplication(ApplicationApi::validPayload($ref));

        self::assertSame(
            [422, ['applicant_ref'], $before],
            [$result['status'], array_keys($result['body']['errors']), $this->api->totalApplications()],
        );
    }

    /** @return array<string,array{mixed}> */
    public static function nonStringValues(): array
    {
        return [
            'массив' => [['CL-T1']],
            'пустой массив' => [[]],
            'объект' => [['a' => 'b']],
            'true' => [true],
            'false' => [false],
            'целое число' => [12345],
            'ноль' => [0],
            'дробное число' => [1.5],
        ];
    }

    #[DataProvider('nonStringValues')]
    public function testNonStringApplicantRefReturns422WithoutWriting(mixed $value): void
    {
        $payload = ApplicationApi::validPayload();
        $payload['applicant_ref'] = $value;
        $before = $this->api->totalApplications();

        $result = $this->api->createApplication($payload);

        self::assertSame(
            [422, ['errors'], ['applicant_ref'], $before],
            [
                $result['status'],
                array_keys($result['body']),
                array_keys($result['body']['errors']),
                $this->api->totalApplications(),
            ],
        );
    }

    public function testApplicantRefErrorIsReportedTogetherWithOtherFieldErrors(): void
    {
        $payload = ApplicationApi::validPayload();
        $payload['applicant_ref'] = ['x'];
        $payload['vin'] = 'BAD';

        $result = $this->api->createApplication($payload);

        self::assertSame(['vin', 'applicant_ref'], array_keys($result['body']['errors']));
    }

    public function testLtvEndpointIgnoresApplicantRef(): void
    {
        $payload = ApplicationApi::validPayload();
        $payload['applicant_ref'] = ['x'];

        $result = $this->api->calculateLtv($payload);

        self::assertSame([200, 50.0], [$result['status'], $result['body']['ltv']]);
    }

    public function testValidApplicationKeepsResponseBodyShapeWithTrimmedRef(): void
    {
        $ref = $this->api->newApplicantRef();

        $result = $this->api->createApplication(ApplicationApi::validPayload(' ' . $ref));

        self::assertSame(
            ['id', 'vehicle_age', 'ltv', 'decision', 'approved_limit'],
            array_keys($result['body']),
        );
    }
}
