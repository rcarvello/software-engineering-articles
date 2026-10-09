<?php
// Composition root: this is where the services are wired together.
declare(strict_types=1);

require __DIR__ . '/autoload.php';

use App\Container\Container;
use App\Employee\EmployeeRepositoryInterface;
use App\Employee\JsonEmployeeRepository;

$container = new Container();

// Interface -> chosen implementation. singleton(): a single shared instance,
// so the JSON file is read only once per request.
$container->singleton(EmployeeRepositoryInterface::class, JsonEmployeeRepository::class);

// The only value the container cannot guess: the path of the data file.
$container->setParameter(
    JsonEmployeeRepository::class,
    'jsonPath',
    __DIR__ . '/data/employees.json'
);

// Note: EmployeeReport, Employee and Department are NOT registered.
// - EmployeeReport is built by autowiring (it only needs the interface, already bound)
// - Employee and Department are data: the repository creates them, not the container

return $container;
