<?php

declare(strict_types=1);

namespace CarMoneyLab\Domain;

/**
 * Решение по заявке: сначала LTV, затем поправка на пробег.
 *
 *   LTV <= approve_max              -> approve
 *   approve_max < LTV <= review_max -> review
 *   LTV > review_max                -> reject
 *
 * Пробег строго больше mileage_review_above_km перекрывает любое решение
 * по LTV (включая reject) и даёт review.
 */
final class DecisionEngine
{
    public const APPROVE = 'approve';
    public const REVIEW = 'review';
    public const REJECT = 'reject';

    private float $approveMax;
    private float $reviewMax;
    private int $mileageReviewAboveKm;

    /**
     * @param array{approve_max:float,review_max:float} $thresholds
     */
    public function __construct(array $thresholds, int $mileageReviewAboveKm)
    {
        $this->approveMax = $thresholds['approve_max'];
        $this->reviewMax = $thresholds['review_max'];
        $this->mileageReviewAboveKm = $mileageReviewAboveKm;
    }

    public function decide(float $ltv, int $mileage): string
    {
        $decision = match (true) {
            $ltv < $this->approveMax => self::APPROVE,
            $ltv <= $this->reviewMax => self::REVIEW,
            default => self::REJECT,
        };

        if ($mileage > $this->mileageReviewAboveKm) {
            return self::REVIEW;
        }

        return $decision;
    }
}
