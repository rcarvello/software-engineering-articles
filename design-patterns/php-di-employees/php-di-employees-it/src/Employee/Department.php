<?php
declare(strict_types=1);

namespace App\Employee;

final class Department
{
    public function __construct(
        private string $code,
        private string $name,
        private string $location,
    ) {}

    public function getCode(): string
    {
        return $this->code;
    }

    public function getInfo(): string
    {
        return sprintf('%s [%s] - sede di %s', $this->name, $this->code, $this->location);
    }
}
