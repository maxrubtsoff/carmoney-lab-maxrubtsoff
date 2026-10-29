<?php

declare(strict_types=1);

namespace CarMoneyLab\Domain;

use RuntimeException;

/**
 * У клиента уже есть активная заявка (статус new или decided): вторую создать нельзя.
 */
final class DuplicateActiveApplicationException extends RuntimeException
{
    public function __construct(private readonly string $applicantRef)
    {
        parent::__construct('У клиента уже есть активная заявка');
    }

    public function applicantRef(): string
    {
        return $this->applicantRef;
    }

    /** @return array<string,string> поле => сообщение, тот же формат, что у ValidationException */
    public function errors(): array
    {
        return ['applicant_ref' => 'У клиента уже есть активная заявка; создайте новую после закрытия предыдущей'];
    }
}
