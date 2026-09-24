<?php

declare(strict_types=1);

namespace CarMoneyLab\Tests\Feature;

use CarMoneyLab\AppFactory;
use CarMoneyLab\Domain\DecisionEngine;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\RequestFactory;

final class SaveEndpointHighMileageTest extends TestCase
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

    public function testSaveEndpointStoresReviewDecisionForHighMileage(): void
    {
        $app = AppFactory::create();

        $request = (new RequestFactory())->createRequest('POST', '/api/applications')
            ->withHeader('Content-Type', 'application/json')
            ->withParsedBody($this->highMileagePayload());

        $response = $app->handle($request);
        $body = json_decode((string) $response->getBody(), true);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(DecisionEngine::REVIEW, $body['decision']);
        self::assertSame(0, $body['approved_limit']);

        $show = $app->handle(
            (new RequestFactory())->createRequest('GET', '/api/applications/' . $body['id'])
        );
        $stored = json_decode((string) $show->getBody(), true);

        self::assertSame(200, $show->getStatusCode());
        self::assertSame(DecisionEngine::REVIEW, $stored['decision']);
        self::assertSame(0, $stored['approved_limit']);
        self::assertSame(400001, $stored['mileage_km']);
    }
}
