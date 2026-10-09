<?php
// Composition root: qui si descrive come collegare i servizi.
declare(strict_types=1);

require __DIR__ . '/autoload.php';

use App\Container\Container;
use App\Employee\EmployeeRepositoryInterface;
use App\Employee\JsonEmployeeRepository;

$container = new Container();

// Interfaccia -> implementazione scelta. singleton(): una sola istanza condivisa,
// quindi il file JSON viene letto una volta sola per richiesta.
$container->singleton(EmployeeRepositoryInterface::class, JsonEmployeeRepository::class);

// L'unico valore che il container non può indovinare: il percorso del file.
$container->setParameter(
    JsonEmployeeRepository::class,
    'jsonPath',
    __DIR__ . '/data/employees.json'
);

// Nota: EmployeeReport, Employee e Department NON vanno registrati.
// - EmployeeReport viene creato in autowiring (dipende solo dall'interfaccia, già collegata)
// - Employee e Department sono dati: li crea il repository, non il container

return $container;
