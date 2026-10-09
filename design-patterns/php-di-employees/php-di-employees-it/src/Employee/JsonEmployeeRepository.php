<?php
declare(strict_types=1);

namespace App\Employee;

use DateTimeImmutable;
use RuntimeException;

final class JsonEmployeeRepository implements EmployeeRepositoryInterface
{
    /** @var Employee[]|null cache in memoria */
    private ?array $employees = null;

    // Parametro scalare senza default: il container lo riceve con setParameter()
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
            throw new RuntimeException("File dati non trovato: {$this->jsonPath}");
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
                firstName:  $row['nome'],
                lastName:   $row['cognome'],
                birthDate:  new DateTimeImmutable($row['dataNascita']),
                department: new Department(
                    $row['department']['codice'],
                    $row['department']['nome'],
                    $row['department']['sede'],
                ),
            ),
            $rows
        );
    }
}
