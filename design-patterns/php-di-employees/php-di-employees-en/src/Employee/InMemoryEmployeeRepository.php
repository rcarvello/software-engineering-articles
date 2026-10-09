<?php
declare(strict_types=1);

namespace App\Employee;

/**
 * Second implementation of the interface: it keeps the employees in memory.
 * Handy for tests and demos, because it needs no file.
 */
final class InMemoryEmployeeRepository implements EmployeeRepositoryInterface
{
    /** @param Employee[] $employees */
    public function __construct(private array $employees = []) {}

    public function findAll(): array
    {
        return $this->employees;
    }
}
