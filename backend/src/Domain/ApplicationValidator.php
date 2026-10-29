<?php

declare(strict_types=1);

namespace CarMoneyLab\Domain;

/**
 * Валидация входящей заявки по справочнику правил.
 * Возвращает нормализованные значения или бросает ValidationException.
 */
final class ApplicationValidator
{
    /** Клиент без идентификатора: под защиту от повторной активной заявки не попадает. */
    public const ANONYMOUS_APPLICANT = 'CL-ANON';

    /** Длина applicant_ref в символах: VARCHAR(32) в db/schema.sql (applications.applicant_ref). */
    public const APPLICANT_REF_MAX_LENGTH = 32;

    public function __construct(
        private readonly array $rules,
        private readonly VinValidator $vinValidator,
        private readonly VehicleAge $vehicleAge,
    ) {
    }

    /**
     * Идентификатор клиента для сохранения заявки.
     *
     * Значение обрезается trim; не передан, null или пустой после trim — аноним CL-ANON.
     * Не строка либо длиннее 32 символов — ошибка валидации.
     *
     * @param array<string,mixed> $payload
     * @return string нормализованный applicant_ref
     * @throws ValidationException
     */
    public function applicantRef(array $payload): string
    {
        $value = $payload['applicant_ref'] ?? null;

        if ($value === null) {
            return self::ANONYMOUS_APPLICANT;
        }

        if (!is_string($value)) {
            throw new ValidationException(['applicant_ref' => $this->applicantRefError()]);
        }

        $value = trim($value);

        if ($value === '') {
            return self::ANONYMOUS_APPLICANT;
        }

        if (mb_strlen($value, 'UTF-8') > self::APPLICANT_REF_MAX_LENGTH) {
            throw new ValidationException(['applicant_ref' => $this->applicantRefError()]);
        }

        return $value;
    }

    private function applicantRefError(): string
    {
        return sprintf('Идентификатор клиента — строка не длиннее %d символов', self::APPLICANT_REF_MAX_LENGTH);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{vin:string,year:int,mileage:int,market_value:int,requested_amount:int,term_months:int}
     */
    public function validate(array $payload): array
    {
        $errors = [];

        $vin = strtoupper(trim((string) ($payload['vin'] ?? '')));
        if (!$this->vinValidator->isValid($vin)) {
            $errors['vin'] = 'VIN должен состоять из 17 символов A-Z и 0-9 без букв I, O, Q';
        }

        $year = (int) ($payload['year'] ?? 0);
        $age = $this->vehicleAge->inYears($year);
        if ($year < $this->rules['vehicle']['min_year']) {
            $errors['year'] = sprintf('Год выпуска не раньше %d', $this->rules['vehicle']['min_year']);
        } elseif ($age < 0) {
            $errors['year'] = 'Год выпуска не может быть в будущем';
        } elseif ($age > $this->rules['vehicle']['max_age_years']) {
            $errors['year'] = sprintf('Возраст авто больше %d лет', $this->rules['vehicle']['max_age_years']);
        }

        $mileage = (int) ($payload['mileage'] ?? -1);
        if ($mileage < 0 || $mileage > $this->rules['vehicle']['max_mileage_km']) {
            $errors['mileage'] = sprintf('Пробег от 0 до %d км', $this->rules['vehicle']['max_mileage_km']);
        }

        $marketValue = (int) ($payload['market_value'] ?? 0);
        if ($marketValue <= 0) {
            $errors['market_value'] = 'Оценочная стоимость должна быть больше нуля';
        }

        $amount = (int) ($payload['requested_amount'] ?? 0);
        if ($amount < $this->rules['amount']['min'] || $amount > $this->rules['amount']['max']) {
            $errors['requested_amount'] = sprintf(
                'Сумма от %d до %d рублей',
                $this->rules['amount']['min'],
                $this->rules['amount']['max'],
            );
        }

        $term = (int) ($payload['term_months'] ?? 0);
        if ($term < $this->rules['term']['min_months'] || $term > $this->rules['term']['max_months']) {
            $errors['term_months'] = sprintf(
                'Срок от %d до %d месяцев',
                $this->rules['term']['min_months'],
                $this->rules['term']['max_months'],
            );
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return [
            'vin' => $vin,
            'year' => $year,
            'mileage' => $mileage,
            'market_value' => $marketValue,
            'requested_amount' => $amount,
            'term_months' => $term,
        ];
    }
}
