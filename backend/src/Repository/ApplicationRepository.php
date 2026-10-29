<?php

declare(strict_types=1);

namespace CarMoneyLab\Repository;

use CarMoneyLab\Domain\DuplicateActiveApplicationException;
use PDO;
use PDOException;
use Throwable;

/**
 * Хранение заявок, автомобилей и решений.
 */
final class ApplicationRepository
{
    /**
     * Активные статусы: заявка клиента в них блокирует создание новой.
     * Список должен совпадать с выражением active_applicant_ref в db/schema.sql
     * и в db/migrations/001_active_applicant_unique.sql.
     */
    public const ACTIVE_STATUSES = ['new', 'decided'];

    public const STATUS_DECIDED = 'decided';
    public const STATUS_CANCELLED = 'cancelled';

    /** Имя уникального индекса по active_applicant_ref (db/schema.sql). */
    private const ACTIVE_APPLICANT_INDEX = 'uq_applications_active_applicant';

    private const MYSQL_DUPLICATE_ENTRY = 1062;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Сохраняет заявку, автомобиль и решение одной транзакцией.
     *
     * Вторую активную заявку клиента отсекает уникальный индекс в БД, а не проверка в коде:
     * проверка перед вставкой не защищает от двух одновременных запросов.
     *
     * @param array{vin:string,year:int,mileage:int,market_value:int,requested_amount:int,term_months:int} $input
     * @param array{ltv:float,decision:string,approved_limit:int} $assessment
     * @throws DuplicateActiveApplicationException у клиента уже есть активная заявка
     */
    public function save(string $applicantRef, array $input, array $assessment): int
    {
        $this->pdo->beginTransaction();

        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO applications (applicant_ref, requested_amount, term_months, status)
                 VALUES (:applicant_ref, :amount, :term, :status)'
            );
            $statement->execute([
                ':applicant_ref' => $applicantRef,
                ':amount' => $input['requested_amount'],
                ':term' => $input['term_months'],
                ':status' => self::STATUS_DECIDED,
            ]);

            $applicationId = (int) $this->pdo->lastInsertId();

            $vehicle = $this->pdo->prepare(
                'INSERT INTO vehicles (application_id, vin, production_year, mileage_km, market_value)
                 VALUES (:application_id, :vin, :year, :mileage, :market_value)'
            );
            $vehicle->execute([
                ':application_id' => $applicationId,
                ':vin' => $input['vin'],
                ':year' => $input['year'],
                ':mileage' => $input['mileage'],
                ':market_value' => $input['market_value'],
            ]);

            $decision = $this->pdo->prepare(
                'INSERT INTO decisions (application_id, ltv, decision, approved_limit)
                 VALUES (:application_id, :ltv, :decision, :approved_limit)'
            );
            $decision->execute([
                ':application_id' => $applicationId,
                ':ltv' => $assessment['ltv'],
                ':decision' => $assessment['decision'],
                ':approved_limit' => $assessment['approved_limit'],
            ]);

            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            if ($exception instanceof PDOException && $this->isActiveApplicantViolation($exception)) {
                throw new DuplicateActiveApplicationException($applicantRef);
            }

            throw $exception;
        }

        return $applicationId;
    }

    /**
     * Закрывает заявку: статус cancelled. Повторное закрытие не ошибка.
     *
     * @return bool false, если заявки с таким id нет
     */
    public function cancel(int $id): bool
    {
        $update = $this->pdo->prepare('UPDATE applications SET status = :status WHERE id = :id');
        $update->execute([':status' => self::STATUS_CANCELLED, ':id' => $id]);

        if ($update->rowCount() > 0) {
            return true;
        }

        // 0 затронутых строк: заявка уже закрыта либо её нет.
        $exists = $this->pdo->prepare('SELECT COUNT(*) FROM applications WHERE id = :id');
        $exists->execute([':id' => $id]);

        return (int) $exists->fetchColumn() > 0;
    }

    private function isActiveApplicantViolation(PDOException $exception): bool
    {
        $driverCode = $exception->errorInfo[1] ?? null;

        return $driverCode === self::MYSQL_DUPLICATE_ENTRY
            && str_contains($exception->getMessage(), self::ACTIVE_APPLICANT_INDEX);
    }

    /** @return array<string,mixed> пустой массив, если заявки нет */
    public function find(int $id): array
    {
        $statement = $this->pdo->prepare(
            'SELECT a.id, a.applicant_ref, a.requested_amount, a.term_months, a.status, a.created_at,
                    v.vin, v.production_year, v.mileage_km, v.market_value,
                    d.ltv, d.decision, d.approved_limit
             FROM applications a
             LEFT JOIN vehicles v ON v.application_id = a.id
             LEFT JOIN decisions d ON d.application_id = a.id
             WHERE a.id = :id'
        );
        $statement->execute([':id' => $id]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? [] : $row;
    }

    /**
     * Список заявок для оператора.
     *
     * @return list<array<string,mixed>>
     */
    public function listApplications(?string $status = null, int $limit = 50): array
    {
        $sql = 'SELECT id, applicant_ref, requested_amount, term_months, status, created_at FROM applications';

        if ($status !== null && $status !== '') {
            $sql .= " WHERE status = '" . $status . "'";
        }

        $sql .= ' ORDER BY id DESC LIMIT ' . $limit;

        $rows = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        foreach ($rows as $row) {
            $vehicle = $this->pdo->prepare('SELECT vin, production_year FROM vehicles WHERE application_id = :id');
            $vehicle->execute([':id' => $row['id']]);
            $row['vehicle'] = $vehicle->fetch(PDO::FETCH_ASSOC) ?: null;

            $decision = $this->pdo->prepare('SELECT ltv, decision, approved_limit FROM decisions WHERE application_id = :id');
            $decision->execute([':id' => $row['id']]);
            $row['decision'] = $decision->fetch(PDO::FETCH_ASSOC) ?: null;

            $result[] = $row;
        }

        return $result;
    }
}
