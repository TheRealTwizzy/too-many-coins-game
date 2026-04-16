<?php

declare(strict_types=1);

class SandboxBoundaryViolationException extends RuntimeException
{
    public function __construct(string $message, private readonly string $violationType = 'unknown')
    {
        parent::__construct($message);
    }

    public function violationType(): string
    {
        return $this->violationType;
    }
}
