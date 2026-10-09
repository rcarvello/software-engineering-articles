<?php
// Smoke test senza framework: php tests/smoke.php
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
        fwrite(STDERR, "FALLITO: $label\n");
        exit(1);
    }
    echo "OK  - $label\n";
}

// Due classi che si richiedono a vicenda, per provare il rilevamento dei cicli
final class CycleA { public function __construct(public CycleB $b) {} }
final class CycleB { public function __construct(public CycleA $a) {} }

// Container configurato come in produzione
/** @var Container $container */
$container = require __DIR__ . '/../bootstrap.php';

// Data fissa: i test non dipendono dal giorno in cui vengono lanciati
$on = new DateTimeImmutable('2026-10-08');

// 1. L'interfaccia viene risolta nell'implementazione JSON e legge 10 dipendenti
$repository = $container->get(EmployeeRepositoryInterface::class);
$employees  = $repository->findAll();
check($repository instanceof JsonEmployeeRepository, 'l\'interfaccia è collegata a JsonEmployeeRepository');
check(count($employees) === 10, 'il repository legge 10 dipendenti dal JSON');

// 2. getAge(): compleanno già passato / non ancora passato
$giulia = $employees[0]; // nata il 1985-03-15
$marco  = $employees[1]; // nato il 1990-12-01
check($giulia->getAge($on) === 41, 'getAge: compleanno già passato (41)');
check($marco->getAge($on) === 35, 'getAge: compleanno non ancora passato (35)');

// 3. Department::getInfo()
check(
    $giulia->getDepartment()->getInfo() === 'Vendite [VEN] - sede di Milano',
    'Department::getInfo()'
);

// 4. Autowiring: EmployeeReport non è registrato ma viene costruito
$report = $container->get(EmployeeReport::class);
check($report instanceof EmployeeReport, 'autowiring di EmployeeReport');
check(count($report->rows($on)) === 10, 'il report elenca 10 righe');

// 5. Singleton: l'interfaccia restituisce sempre la stessa istanza
check(
    $container->get(EmployeeRepositoryInterface::class) === $container->get(EmployeeRepositoryInterface::class),
    'EmployeeRepositoryInterface è un singleton'
);

// 6. Cambio di implementazione senza toccare il report
$test = new Container();
$test->instance(
    EmployeeRepositoryInterface::class,
    new InMemoryEmployeeRepository([
        new Employee(99, 'Prova', 'Test', new DateTimeImmutable('2000-01-01'), new Department('T', 'Test', 'Qui')),
    ])
);
$rows = $test->get(EmployeeReport::class)->rows($on);
check(count($rows) === 1 && $rows[0]['nome'] === 'Prova Test', 'con un repository in memoria il report non cambia');

// 7. Interfaccia senza binding -> errore chiaro
try {
    (new Container())->get(EmployeeRepositoryInterface::class);
    check(false, 'interfaccia senza binding deve fallire');
} catch (ContainerException $e) {
    check(
        str_contains($e->getMessage(), 'EmployeeRepositoryInterface') && str_contains($e->getMessage(), 'non è istanziabile'),
        'errore chiaro su interfaccia senza binding'
    );
}

// 8. Parametro scalare non configurato -> errore chiaro
$senzaPercorso = new Container();
$senzaPercorso->bind(EmployeeRepositoryInterface::class, JsonEmployeeRepository::class);
try {
    $senzaPercorso->get(EmployeeRepositoryInterface::class);
    check(false, 'jsonPath mancante deve fallire');
} catch (ContainerException $e) {
    check(str_contains($e->getMessage(), '$jsonPath'), 'errore chiaro se manca il parametro jsonPath');
}

// 9. Dipendenza circolare
try {
    (new Container())->get(CycleA::class);
    check(false, 'dipendenza circolare deve fallire');
} catch (ContainerException $e) {
    check(str_contains($e->getMessage(), 'circolare'), 'rilevamento dipendenza circolare');
}

// 10. I dati non sono servizi: Employee non si ottiene dal container
try {
    $container->get(Employee::class);
    check(false, 'Employee dal container deve fallire');
} catch (ContainerException $e) {
    check(str_contains($e->getMessage(), '$id'), 'Employee non è un servizio: il container non può costruirlo');
}

echo "\nTutti i test sono passati.\n";
