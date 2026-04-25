<?php

class FeatureFactoryException extends RuntimeException
{
    private array $details;

    public function __construct(string $message, array $details = [])
    {
        parent::__construct($message);
        $this->details = array_values($details);
    }

    public function details(): array
    {
        return $this->details;
    }
}
