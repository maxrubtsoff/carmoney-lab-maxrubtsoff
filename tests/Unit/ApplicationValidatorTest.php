<?php

declare(strict_types=1);

namespace CarMoneyLab\Tests\Unit;

use CarMoneyLab\Domain\ApplicationValidator;
use CarMoneyLab\Domain\ValidationException;
use CarMoneyLab\Domain\VehicleAge;
use CarMoneyLab\Domain\VinValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ApplicationValidatorTest extends TestCase
{
    private ApplicationValidator $validator;

    protected function setUp(): void
    {
        $rules = require __DIR__ . '/../../backend/config/rules.php';

        $this->validator = new ApplicationValidator(
            $rules,
            new VinValidator($rules['vin']),
            new VehicleAge((int) date('Y')),
        );
    }

    /** @return array<string,mixed> */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'vin' => 'XTA21099998765432',
            'year' => (int) date('Y') - 5,
            'mileage' => 84000,
            'market_value' => 900000,
            'requested_amount' => 450000,
            'term_months' => 24,
        ], $overrides);
    }

    public function testAcceptsValidApplicationAndNormalisesVin(): void
    {
        $result = $this->validator->validate($this->validPayload(['vin' => 'xta21099998765432']));

        self::assertSame('XTA21099998765432', $result['vin']);
        self::assertSame(450000, $result['requested_amount']);
    }

    public function testRejectsYearInTheFuture(): void
    {
        $this->expectException(ValidationException::class);

        $this->validator->validate($this->validPayload(['year' => (int) date('Y') + 1]));
    }

    public function testRejectsAmountBelowMinimum(): void
    {
        try {
            $this->validator->validate($this->validPayload(['requested_amount' => 1000]));
            self::fail('Ожидали ValidationException');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('requested_amount', $exception->errors());
        }
    }

    public function testCollectsAllErrorsAtOnce(): void
    {
        try {
            $this->validator->validate($this->validPayload([
                'vin' => 'BAD',
                'term_months' => 120,
                'market_value' => 0,
            ]));
            self::fail('Ожидали ValidationException');
        } catch (ValidationException $exception) {
            self::assertSame(
                ['vin', 'market_value', 'term_months'],
                array_keys($exception->errors()),
            );
        }
    }

    #[DataProvider('invalidMileage')]
    public function testRejectsInvalidMileage(array $overrides): void
    {
        try {
            $this->validator->validate($this->validPayload($overrides));
            self::fail('Ожидали ValidationException');
        } catch (ValidationException $exception) {
            self::assertSame(['mileage' => 'Пробег от 0 до 500000 км'], $exception->errors());
        }
    }

    /** @return array<string,array{array<string,mixed>}> */
    public static function invalidMileage(): array
    {
        return [
            'пробег null' => [['mileage' => null]],
            'пробег выше потолка 500000' => [['mileage' => 500001]],
            'отрицательный пробег' => [['mileage' => -1]],
        ];
    }

    /** @return array<string,array{array<string,mixed>,string}> */
    public static function applicantRefInputs(): array
    {
        return [
            'не передан' => [[], 'CL-ANON'],
            'null' => [['applicant_ref' => null], 'CL-ANON'],
            'пустая строка' => [['applicant_ref' => ''], 'CL-ANON'],
            'пробелы' => [['applicant_ref' => "  \t "], 'CL-ANON'],
            'явный CL-ANON' => [['applicant_ref' => 'CL-ANON'], 'CL-ANON'],
            'обычное значение' => [['applicant_ref' => 'CL-0001'], 'CL-0001'],
            'значение с пробелами по краям' => [['applicant_ref' => '  CL-0001 '], 'CL-0001'],
            'регистр не меняется' => [['applicant_ref' => 'cl-0001'], 'cl-0001'],
            'ровно 32 символа' => [['applicant_ref' => str_repeat('a', 32)], str_repeat('a', 32)],
            '32 кириллических символа' => [['applicant_ref' => str_repeat('я', 32)], str_repeat('я', 32)],
            '32 символа плюс пробелы' => [['applicant_ref' => ' ' . str_repeat('a', 32) . ' '], str_repeat('a', 32)],
        ];
    }

    /** @param array<string,mixed> $payload */
    #[DataProvider('applicantRefInputs')]
    public function testNormalisesApplicantRef(array $payload, string $expected): void
    {
        self::assertSame($expected, $this->validator->applicantRef($payload));
    }

    /** @return array<string,array{mixed}> */
    public static function invalidApplicantRefs(): array
    {
        return [
            '33 символа' => [str_repeat('a', 33)],
            '33 кириллических символа' => [str_repeat('я', 33)],
            'массив' => [['CL-1']],
            'пустой массив' => [[]],
            'true' => [true],
            'false' => [false],
            'целое число' => [7],
            'дробное число' => [0.5],
        ];
    }

    #[DataProvider('invalidApplicantRefs')]
    public function testRejectsInvalidApplicantRef(mixed $value): void
    {
        try {
            $this->validator->applicantRef(['applicant_ref' => $value]);
            self::fail('Ожидали ValidationException');
        } catch (ValidationException $exception) {
            self::assertSame(['applicant_ref'], array_keys($exception->errors()));
        }
    }

    public function testValidateIgnoresApplicantRef(): void
    {
        $result = $this->validator->validate($this->validPayload(['applicant_ref' => ['x']]));

        self::assertArrayNotHasKey('applicant_ref', $result);
    }

    public function testRejectsMissingMileage(): void
    {
        $payload = $this->validPayload();
        unset($payload['mileage']);

        try {
            $this->validator->validate($payload);
            self::fail('Ожидали ValidationException');
        } catch (ValidationException $exception) {
            self::assertSame(['mileage' => 'Пробег от 0 до 500000 км'], $exception->errors());
        }
    }
}
