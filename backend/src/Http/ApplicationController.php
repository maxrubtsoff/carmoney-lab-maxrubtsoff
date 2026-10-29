<?php

declare(strict_types=1);

namespace CarMoneyLab\Http;

use CarMoneyLab\Domain\ApplicationValidator;
use CarMoneyLab\Domain\AssessmentService;
use CarMoneyLab\Domain\DuplicateActiveApplicationException;
use CarMoneyLab\Domain\ValidationException;
use CarMoneyLab\Repository\ApplicationRepository;
use CarMoneyLab\Support\Json;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class ApplicationController
{
    public function __construct(
        private readonly AssessmentService $assessment,
        private readonly ApplicationRepository $repository,
        private readonly ApplicationValidator $validator,
    ) {
    }

    /** POST /api/applications — приём заявки и решение по ней; 409, если у клиента уже есть активная заявка */
    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $payload = (array) ($request->getParsedBody() ?? []);

        // Ошибки заявки и идентификатора клиента отдаём одним ответом 422, до любых записей в БД.
        $errors = [];
        $result = null;
        $applicantRef = '';

        try {
            $result = $this->assessment->assess($payload);
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
        }

        try {
            $applicantRef = $this->validator->applicantRef($payload);
        } catch (ValidationException $exception) {
            $errors += $exception->errors();
        }

        if ($errors !== [] || $result === null) {
            return Json::write($response, ['errors' => $errors], 422);
        }

        try {
            $id = $this->repository->save($applicantRef, $result['input'], $result);
        } catch (DuplicateActiveApplicationException $exception) {
            return Json::write($response, ['errors' => $exception->errors()], 409);
        }

        error_log(sprintf(
            'application=%d ltv=%s decision=%s limit=%d',
            $id,
            $result['ltv'],
            $result['decision'],
            $result['approved_limit'],
        ));

        return Json::write($response, [
            'id' => $id,
            'vehicle_age' => $result['vehicle_age'],
            'ltv' => $result['ltv'],
            'decision' => $result['decision'],
            'approved_limit' => $result['approved_limit'],
        ], 201);
    }

    /** POST /api/ltv — расчёт LTV и решения без сохранения заявки */
    public function ltv(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $payload = (array) ($request->getParsedBody() ?? []);

        try {
            $result = $this->assessment->assess($payload);
        } catch (ValidationException $exception) {
            return Json::write($response, ['errors' => $exception->errors()], 422);
        }

        return Json::write($response, [
            'vehicle_age' => $result['vehicle_age'],
            'ltv' => $result['ltv'],
            'decision' => $result['decision'],
            'approved_limit' => $result['approved_limit'],
        ]);
    }

    /** POST /api/applications/{id}/cancel — закрыть заявку (после этого клиент может подать новую) */
    public function cancel(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        // Идентификатор — только цифры: иначе (int) превратил бы «1abc», «1.5», «1e0» в id=1.
        if (!ctype_digit((string) $args['id'])) {
            return Json::write($response, ['errors' => ['id' => 'Заявка не найдена']], 404);
        }

        $id = (int) $args['id'];

        if (!$this->repository->cancel($id)) {
            return Json::write($response, ['errors' => ['id' => 'Заявка не найдена']], 404);
        }

        return Json::write($response, ['id' => $id, 'status' => ApplicationRepository::STATUS_CANCELLED]);
    }

    /** GET /api/applications/{id} — карточка заявки */
    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $row = $this->repository->find((int) $args['id']);

        // Если заявки нет, отдаём пустой объект — фронт тогда просто ничего не рисует.
        return Json::write($response, $row ?: (object) []);
    }

    /** GET /api/applications?status=decided — список заявок */
    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $status = $request->getQueryParams()['status'] ?? null;

        return Json::write($response, [
            'items' => $this->repository->listApplications($status !== null ? (string) $status : null),
        ]);
    }
}
