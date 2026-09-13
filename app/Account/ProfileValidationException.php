<?php
declare(strict_types=1);
namespace App\Account;

final class ProfileValidationException extends \InvalidArgumentException
{
    public function __construct(private array $fields)
    {
        parent::__construct('Profile data is invalid.');
    }

    public function errors(): array { return $this->fields; }
}
