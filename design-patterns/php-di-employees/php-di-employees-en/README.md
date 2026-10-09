# php-di-employees

A **DI container in plain PHP** (no dependencies, not even Composer): a list of employees read from a JSON file, with a repository interface, autowiring and singletons.

| Class | Role |
|---|---|
| `Container` | the DI container (Reflection-based autowiring, `bind`, `singleton`, `instance`, `setParameter`) |
| `EmployeeRepositoryInterface` | contract: `findAll()` returns a list of `Employee` |
| `JsonEmployeeRepository` | implementation that reads `data/employees.json` |
| `InMemoryEmployeeRepository` | alternative implementation, for tests and demos |
| `Employee` | data: name, `birthDate`, `Department`, `getAge()` method |
| `Department` | data: code, name, location, `getInfo()` method |
| `EmployeeReport` | service: receives the interface from the container and prepares the rows to display |

The article that explains the example, container included, is in `docs/di-container-employee-en.md`.

## Requirements

- PHP 8.1 or later in your `PATH` (`php -v` must work)

## Quick start (Windows)

- **`start.bat`**: runs `php -S localhost:8000 -t public` and opens the browser
- **`test.bat`**: runs the smoke tests

## Manual start (any system)

```bash
php -S localhost:8000 -t public
php tests/smoke.php
```

## Layout

```
php-di-employees/
├── start.bat / test.bat
├── autoload.php             minimal PSR-4 autoloader (App\ -> src/)
├── bootstrap.php            composition root: configures the container
├── data/employees.json      10 employees with birthDate and department
├── public/index.php         front controller (HTML table)
├── src/
│   ├── Container/           Container, ContainerException, NotFoundException
│   ├── Employee/            Employee, Department, interface + two repositories
│   └── Report/              EmployeeReport
├── tests/smoke.php
└── docs/                    article in Markdown
```
