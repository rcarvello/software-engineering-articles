<?php
declare(strict_types=1);

namespace App\Report;

use App\Employee\Employee;
use App\Employee\EmployeeRepositoryInterface;
use DateTimeImmutable;

final class EmployeeReport
{
    // Dependency declared on the INTERFACE: the report does not know where the data comes from
    public function __construct(private EmployeeRepositoryInterface $employees) {}

    /** @return array<int, array{id: int, name: string, age: int, department: string}> */
    public function rows(?DateTimeImmutable $on = null): array
    {
        return array_map(
            static fn(Employee $e) => [
                'id'         => $e->getId(),
                'name'       => $e->getFullName(),
                'age'        => $e->getAge($on),
                'department' => $e->getDepartment()->getInfo(),
            ],
            $this->employees->findAll()
        );
    }

    public function averageAge(?DateTimeImmutable $on = null): float
    {
        $ages = array_column($this->rows($on), 'age');

        return $ages === [] ? 0.0 : array_sum($ages) / count($ages);
    }
}
