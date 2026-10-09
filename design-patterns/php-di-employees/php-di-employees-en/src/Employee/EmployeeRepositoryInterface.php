<?php
declare(strict_types=1);

namespace App\Employee;

interface EmployeeRepositoryInterface
{
    /** @return Employee[] */
    public function findAll(): array;
}
