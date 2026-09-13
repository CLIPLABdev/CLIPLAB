<?php

declare(strict_types=1);

namespace App\Exceptions;

use InvalidArgumentException;
use RuntimeException;

final class ClipRenderValidationException extends RuntimeException
{
    /** @var array<string, string> */
    private array $validationErrors;

    /** @param array<string, string> $errors */
    public function __construct(array $errors, private ?int $authorizedProjectId = null)
    {
        if ($errors === []) {
            throw new InvalidArgumentException('Clip render validation errors cannot be empty.');
        }
        foreach ($errors as $field => $message) {
            if (!is_string($field) || $field === '' || !is_string($message) || trim($message) === '') {
                throw new InvalidArgumentException('Clip render validation errors must contain field messages.');
            }
        }
        if ($authorizedProjectId !== null && $authorizedProjectId < 1) {
            throw new InvalidArgumentException('Authorized project identifier must be positive.');
        }

        parent::__construct('The clip render request is invalid.');
        $this->validationErrors = $errors;
    }

    /** @return array<string, string> */
    public function errors(): array
    {
        return $this->validationErrors;
    }

    public function projectId(): ?int
    {
        return $this->authorizedProjectId;
    }
}
