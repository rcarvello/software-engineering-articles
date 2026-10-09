# php-di-employees

Esempio di ** Dependency Injection Container in PHP puro** (nessuna dipendenza, nemmeno Composer): un elenco di dipendenti letto da un file JSON, con interfaccia del repository, autowiring e singleton.

| Classe | Ruolo |
|---|---|
| `Container` | il DI container (autowiring via Reflection, `bind`, `singleton`, `instance`, `setParameter`) |
| `EmployeeRepositoryInterface` | contratto: `findAll()` restituisce un elenco di `Employee` |
| `JsonEmployeeRepository` | implementazione che legge `data/employees.json` |
| `InMemoryEmployeeRepository` | implementazione alternativa, per test e demo |
| `Employee` | dato: nome, `dataNascita`, `Department`, metodo `getAge()` |
| `Department` | dato: codice, nome, sede, metodo `getInfo()` |
| `EmployeeReport` | servizio: riceve l'interfaccia dal container e prepara le righe da mostrare |

L'articolo che spiega l'esempio, container compreso, è in `docs/di-container-employee.md`.

## Requisiti

- PHP 8.1 o superiore nel `PATH` (`php -v` deve funzionare)

## Avvio rapido (Windows)

- **`avvia.bat`**: avvia `php -S localhost:8000 -t public` e apre il browser
- **`test.bat`**: esegue gli smoke test

## Avvio manuale (qualsiasi sistema)

```bash
php -S localhost:8000 -t public
php tests/smoke.php
```

## Struttura

```
php-di-employees/
├── avvia.bat / test.bat
├── autoload.php             autoloader PSR-4 minimale (App\ -> src/)
├── bootstrap.php            composition root: configura il container
├── data/employees.json      10 dipendenti con dataNascita e department
├── public/index.php         front controller (tabella HTML)
├── src/
│   ├── Container/           Container, ContainerException, NotFoundException
│   ├── Employee/            Employee, Department, interfaccia + due repository
│   └── Report/              EmployeeReport
├── tests/smoke.php
└── docs/                    articolo in Markdown
```
