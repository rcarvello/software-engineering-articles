<?php
declare(strict_types=1);

namespace App\Report;

use App\Employee\Employee;
use App\Employee\EmployeeRepositoryInterface;
use DateTimeImmutable;

final class EmployeeReport
{
    // Dipendenza dichiarata sull'INTERFACCIA: il report non sa da dove arrivano i dati
    public function __construct(private EmployeeRepositoryInterface $employees) {}

    /** @return array<int, array{id: int, nome: string, eta: int, dipartimento: string}> */
    public function rows(?DateTimeImmutable $on = null): array
    {
        return array_map(
            static fn(Employee $e) => [
                'id'           => $e->getId(),
                'nome'         => $e->getFullName(),
                'eta'          => $e->getAge($on),
                'dipartimento' => $e->getDepartment()->getInfo(),
            ],
            $this->employees->findAll()
        );
    }

    public function averageAge(?DateTimeImmutable $on = null): float
    {
        $ages = array_column($this->rows($on), 'eta');

        return $ages === [] ? 0.0 : array_sum($ages) / count($ages);
    }
}
