<?php

declare(strict_types=1);

namespace CarMoneyLab\Tests\Feature\Support;

use CarMoneyLab\AppFactory;
use CarMoneyLab\Database;
use CarMoneyLab\Repository\ApplicationRepository;
use PDO;
use Slim\App;
use Slim\Psr7\Factory\RequestFactory;

/**
 * Помощник feature-тестов: вызовы API через Slim на настоящей MySQL и уборка за собой.
 * Все клиенты в тестах синтетические (CL-T...), чужие и учебные заявки не затрагиваются.
 */
final class ApplicationApi
{
    /** @var list<int> */
    private array $createdIds = [];

    /** @var list<string> */
    private array $createdRefs = [];

    public function __construct(private readonly App $app, private readonly PDO $pdo)
    {
    }

    public static function connect(): self
    {
        $pdo = Database::connect();

        return new self(AppFactory::create($pdo), $pdo);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /** Новый уникальный синтетический клиент: не пересекается с seed и с прошлыми прогонами. */
    public function newApplicantRef(string $suffix = ''): string
    {
        $ref = 'CL-T' . bin2hex(random_bytes(6)) . $suffix;
        $this->createdRefs[] = $ref;

        return $ref;
    }

    /** @return array<string,mixed> */
    public static function validPayload(?string $applicantRef = null): array
    {
        $payload = [
            'vin' => 'XTA21099998765432',
            'year' => (int) date('Y') - 4,
            'mileage' => 80000,
            'market_value' => 1000000,
            'requested_amount' => 500000,
            'term_months' => 24,
        ];

        if ($applicantRef !== null) {
            $payload['applicant_ref'] = $applicantRef;
        }

        return $payload;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{status:int,body:array<string,mixed>}
     */
    public function createApplication(array $payload): array
    {
        $result = $this->post('/api/applications', $payload);

        if ($result['status'] === 201) {
            $this->createdIds[] = (int) $result['body']['id'];
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{status:int,body:array<string,mixed>}
     */
    public function calculateLtv(array $payload): array
    {
        return $this->post('/api/ltv', $payload);
    }

    /** @return array{status:int,body:array<string,mixed>} */
    public function cancelApplication(int $id): array
    {
        return $this->post('/api/applications/' . $id . '/cancel', []);
    }

    /**
     * Отмена по «сырому» значению {id} из пути (например «1abc»): как есть, без приведения к int.
     *
     * @return array{status:int,body:array<string,mixed>}
     */
    public function cancelApplicationByRawId(string $rawId): array
    {
        return $this->post('/api/applications/' . $rawId . '/cancel', []);
    }

    /** Статус заявки в БД. */
    public function storedStatus(int $applicationId): string
    {
        $statement = $this->pdo->prepare('SELECT status FROM applications WHERE id = :id');
        $statement->execute([':id' => $applicationId]);

        return (string) $statement->fetchColumn();
    }

    /** Число заявок клиента; $activeOnly — только new/decided. */
    public function countApplications(string $applicantRef, bool $activeOnly = false): int
    {
        $sql = 'SELECT COUNT(*) FROM applications WHERE applicant_ref = :ref';
        $params = [':ref' => $applicantRef];

        if ($activeOnly) {
            $placeholders = [];
            foreach (ApplicationRepository::ACTIVE_STATUSES as $index => $status) {
                $placeholders[] = ':status' . $index;
                $params[':status' . $index] = $status;
            }
            $sql .= ' AND status IN (' . implode(', ', $placeholders) . ')';
        }

        return $this->scalar($sql, $params);
    }

    /** applicant_ref, с которым заявка сохранена в БД. */
    public function storedApplicantRef(int $applicationId): string
    {
        $statement = $this->pdo->prepare('SELECT applicant_ref FROM applications WHERE id = :id');
        $statement->execute([':id' => $applicationId]);

        return (string) $statement->fetchColumn();
    }

    /** Регистрирует ref для уборки: тест сам выбирает значение, но чистит за собой. */
    public function trackApplicantRef(string $applicantRef): string
    {
        $this->createdRefs[] = $applicantRef;

        return $applicantRef;
    }

    /** Общее число заявок в таблице: чтобы убедиться, что 422 ничего не записал. */
    public function totalApplications(): int
    {
        return $this->scalar('SELECT COUNT(*) FROM applications', []);
    }

    public function countVehicles(string $applicantRef): int
    {
        return $this->scalar(
            'SELECT COUNT(*) FROM vehicles v JOIN applications a ON a.id = v.application_id WHERE a.applicant_ref = :ref',
            [':ref' => $applicantRef],
        );
    }

    public function countDecisions(string $applicantRef): int
    {
        return $this->scalar(
            'SELECT COUNT(*) FROM decisions d JOIN applications a ON a.id = d.application_id WHERE a.applicant_ref = :ref',
            [':ref' => $applicantRef],
        );
    }

    /** Удаляет созданные тестом заявки; vehicles и decisions уходят каскадом. */
    public function cleanUp(): void
    {
        foreach ($this->createdRefs as $ref) {
            $this->pdo->prepare('DELETE FROM applications WHERE applicant_ref = :ref')->execute([':ref' => $ref]);
        }

        foreach ($this->createdIds as $id) {
            $this->pdo->prepare('DELETE FROM applications WHERE id = :id')->execute([':id' => $id]);
        }

        $this->createdRefs = [];
        $this->createdIds = [];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{status:int,body:array<string,mixed>}
     */
    private function post(string $path, array $payload): array
    {
        $request = (new RequestFactory())->createRequest('POST', $path)
            ->withHeader('Content-Type', 'application/json')
            ->withParsedBody($payload);

        $response = $this->app->handle($request);

        return [
            'status' => $response->getStatusCode(),
            'body' => (array) json_decode((string) $response->getBody(), true),
        ];
    }

    /** @param array<string,mixed> $params */
    private function scalar(string $sql, array $params): int
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return (int) $statement->fetchColumn();
    }
}
