<?php

declare(strict_types=1);

namespace CarMoneyLab\Tests\Feature;

use CarMoneyLab\AppFactory;
use CarMoneyLab\Domain\DecisionEngine;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\RequestFactory;

final class LtvEndpointHighMileageTest extends TestCase
{
    /** @return array<string,mixed> */
    private function highMileagePayload(): array
    {
        return [
            'vin' => 'XTA21099998765432',
            'year' => (int) date('Y') - 4,
            'mileage' => 400001,
            'market_value' => 1000000,
            'requested_amount' => 500000,
            'term_months' => 24,
        ];
    }

    public function testLtvEndpointReturnsReviewForHighMileage(): void
    {
        $app = AppFactory::create();

        $request = (new RequestFactory())->createRequest('POST', '/api/ltv')
            ->withHeader('Content-Type', 'application/json')
            ->withParsedBody($this->highMileagePayload());

        $response = $app->handle($request);
        $body = json_decode((string) $response->getBody(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(50.0, $body['ltv']);
        self::assertSame(DecisionEngine::REVIEW, $body['decision']);
        self::assertSame(0, $body['approved_limit']);
    }
}
