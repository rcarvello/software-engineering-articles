<?php
declare(strict_types=1);

namespace App\Employee;

use DateTimeImmutable;
use RuntimeException;

final class JsonEmployeeRepository implements EmployeeRepositoryInterface
{
    /** @var Employee[]|null in-memory cache */
    private ?array $employees = null;

    // Scalar parameter with no default: the container gets it through setParameter()
    public function __construct(private string $jsonPath) {}

    /** @return Employee[] */
    public function findAll(): array
    {
        return $this->employees ??= $this->load();
    }

    /** @return Employee[] */
    private function load(): array
    {
        if (!is_file($this->jsonPath)) {
            throw new RuntimeException("Data file not found: {$this->jsonPath}");
        }

        $rows = json_decode(
            (string) file_get_contents($this->jsonPath),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        return array_map(
            static fn(array $row) => new Employee(
                id:         (int) $row['id'],
                firstName:  $row['firstName'],
                lastName:   $row['lastName'],
                birthDate:  new DateTimeImmutable($row['birthDate']),
                department: new Department(
                    $row['department']['code'],
                    $row['department']['name'],
                    $row['department']['location'],
                ),
            ),
            $rows
        );
    }
}
