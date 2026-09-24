<?php

declare(strict_types=1);

namespace CarMoneyLab\Tests\Unit;

use CarMoneyLab\Domain\DecisionEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DecisionEngineTest extends TestCase
{
    private DecisionEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new DecisionEngine(['approve_max' => 60.0, 'review_max' => 85.0], 400000);
    }

    #[DataProvider('ltvValues')]
    public function testDecidesByLtv(float $ltv, string $expected): void
    {
        self::assertSame($expected, $this->engine->decide($ltv, 0));
    }

    /** @return array<string,array{float,string}> */
    public static function ltvValues(): array
    {
        return [
            'низкий LTV' => [28.5, DecisionEngine::APPROVE],
            'середина зелёной зоны' => [45.0, DecisionEngine::APPROVE],
            'серая зона' => [72.3, DecisionEngine::REVIEW],
            'верхняя граница серой зоны' => [85.0, DecisionEngine::REVIEW],
            'сразу за верхней границей' => [85.01, DecisionEngine::REJECT],
            'высокий LTV' => [120.0, DecisionEngine::REJECT],
        ];
    }

    #[DataProvider('mileageOverrides')]
    public function testMileageAboveThresholdForcesReview(float $ltv, int $mileage, string $expected): void
    {
        self::assertSame($expected, $this->engine->decide($ltv, $mileage));
    }

    /** @return array<string,array{float,int,string}> */
    public static function mileageOverrides(): array
    {
        return [
            'пробег выше порога понижает approve до review' => [50.0, 400001, DecisionEngine::REVIEW],
            'пробег выше порога в review-зоне LTV даёт review' => [70.0, 400001, DecisionEngine::REVIEW],
            'пробег выше порога перекрывает reject до review' => [95.0, 400001, DecisionEngine::REVIEW],
            'пробег ниже порога: review-зона LTV без изменений' => [70.0, 399999, DecisionEngine::REVIEW],
            'пробег ниже порога: reject-зона LTV без изменений' => [95.0, 399999, DecisionEngine::REJECT],
            'граница 400000 не срабатывает' => [50.0, 400000, DecisionEngine::APPROVE],
            'верх окна правила 500000' => [50.0, 500000, DecisionEngine::REVIEW],
        ];
    }
}
