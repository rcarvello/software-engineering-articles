<?php
declare(strict_types=1);

namespace App\Employee;

/**
 * Seconda implementazione dell'interfaccia: tiene i dipendenti in memoria.
 * Utile nei test e nelle demo, perché non richiede alcun file.
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
