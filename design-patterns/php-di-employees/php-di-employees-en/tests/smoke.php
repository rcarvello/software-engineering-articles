<?php
// Framework-free smoke test: php tests/smoke.php
declare(strict_types=1);

require __DIR__ . '/../autoload.php';

use App\Container\Container;
use App\Container\ContainerException;
use App\Employee\Department;
use App\Employee\Employee;
use App\Employee\EmployeeRepositoryInterface;
use App\Employee\InMemoryEmployeeRepository;
use App\Employee\JsonEmployeeRepository;
use App\Report\EmployeeReport;

function check(bool $condition, string $label): void
{
    if (!$condition) {
        fwrite(STDERR, "FAILED: $label\n");
        exit(1);
    }
    echo "OK  - $label\n";
}

// Two classes that require each other, to exercise cycle detection
final class CycleA { public function __construct(public CycleB $b) {} }
final class CycleB { public function __construct(public CycleA $a) {} }

// Container configured exactly as in production
/** @var Container $container */
$container = require __DIR__ . '/../bootstrap.php';

// Fixed date: the tests do not depend on the day they are run
$on = new DateTimeImmutable('2026-10-08');

// 1. The interface resolves to the JSON implementation and reads 10 employees
$repository = $container->get(EmployeeRepositoryInterface::class);
$employees  = $repository->findAll();
check($repository instanceof JsonEmployeeRepository, 'the interface is bound to JsonEmployeeRepository');
check(count($employees) === 10, 'the repository reads 10 employees from the JSON file');

// 2. getAge(): birthday already passed / not yet passed
$giulia = $employees[0]; // born 1985-03-15
$marco  = $employees[1]; // born 1990-12-01
check($giulia->getAge($on) === 41, 'getAge: birthday already passed (41)');
check($marco->getAge($on) === 35, 'getAge: birthday not yet reached (35)');

// 3. Department::getInfo()
check(
    $giulia->getDepartment()->getInfo() === 'Sales [SAL] - Milan office',
    'Department::getInfo()'
);

// 4. Autowiring: EmployeeReport is not registered, yet it gets built
$report = $container->get(EmployeeReport::class);
check($report instanceof EmployeeReport, 'autowiring of EmployeeReport');
check(count($report->rows($on)) === 10, 'the report lists 10 rows');

// 5. Singleton: the interface always returns the same instance
check(
    $container->get(EmployeeRepositoryInterface::class) === $container->get(EmployeeRepositoryInterface::class),
    'EmployeeRepositoryInterface is a singleton'
);

// 6. Swapping the implementation without touching the report
$test = new Container();
$test->instance(
    EmployeeRepositoryInterface::class,
    new InMemoryEmployeeRepository([
        new Employee(99, 'Jane', 'Doe', new DateTimeImmutable('2000-01-01'), new Department('TST', 'Testing', 'Nowhere')),
    ])
);
$rows = $test->get(EmployeeReport::class)->rows($on);
check(count($rows) === 1 && $rows[0]['name'] === 'Jane Doe', 'with an in-memory repository the report is unchanged');

// 7. Interface without a binding -> clear error
try {
    (new Container())->get(EmployeeRepositoryInterface::class);
    check(false, 'an interface without a binding must fail');
} catch (ContainerException $e) {
    check(
        str_contains($e->getMessage(), 'EmployeeRepositoryInterface') && str_contains($e->getMessage(), 'not instantiable'),
        'clear error for an interface without a binding'
    );
}

// 8. Unconfigured scalar parameter -> clear error
$noPath = new Container();
$noPath->bind(EmployeeRepositoryInterface::class, JsonEmployeeRepository::class);
try {
    $noPath->get(EmployeeRepositoryInterface::class);
    check(false, 'a missing jsonPath must fail');
} catch (ContainerException $e) {
    check(str_contains($e->getMessage(), '$jsonPath'), 'clear error when the jsonPath parameter is missing');
}

// 9. Circular dependency
try {
    (new Container())->get(CycleA::class);
    check(false, 'a circular dependency must fail');
} catch (ContainerException $e) {
    check(str_contains($e->getMessage(), 'Circular'), 'circular dependency detection');
}

// 10. Data are not services: Employee cannot be obtained from the container
try {
    $container->get(Employee::class);
    check(false, 'Employee from the container must fail');
} catch (ContainerException $e) {
    check(str_contains($e->getMessage(), '$id'), 'Employee is not a service: the container cannot build it');
}

echo "\nAll tests passed.\n";
