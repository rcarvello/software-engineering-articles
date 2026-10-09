<?php
declare(strict_types=1);

namespace App\Employee;

use DateTimeImmutable;

final class Employee
{
    public function __construct(
        private int $id,
        private string $firstName,
        private string $lastName,
        private DateTimeImmutable $birthDate,
        private Department $department,
    ) {}

    public function getId(): int
    {
        return $this->id;
    }

    public function getFullName(): string
    {
        return $this->firstName . ' ' . $this->lastName;
    }

    public function getBirthDate(): DateTimeImmutable
    {
        return $this->birthDate;
    }

    public function getDepartment(): Department
    {
        return $this->department;
    }

    /**
     * Età in anni compiuti alla data indicata (default: oggi).
     * Passare la data rende il metodo testabile.
     */
    public function getAge(?DateTimeImmutable $on = null): int
    {
        $on ??= new DateTimeImmutable('today');

        return $this->birthDate->diff($on)->y;
    }
}
