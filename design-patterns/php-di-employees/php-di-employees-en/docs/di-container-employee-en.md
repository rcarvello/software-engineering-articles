# Dependency Injection in PHP: Building and Using a DI Container, with a Complete Example

> A DI container is not magic: it is a class that reads constructors and builds objects for you. In this article we build one in plain PHP, explain it line by line, and use it on a concrete case: a list of employees read from a JSON file, with an interface that lets you change the data source without touching the rest of the code.

When people talk about Dependency Injection (DI) they usually think of a framework. But DI is a **principle**, not a framework feature: a class declares what it needs, and someone else hands it over. The **container** is the tool that automates that handover.

In this article we look at:

- why DI exists (the problem);
- the domain code: `Employee`, `Department`, a repository interface and two implementations;
- the **complete container**, built in three steps and explained method by method;
- how it is configured (`bootstrap.php`) and how it is used (`public/index.php`);
- the tests, and why the data (`Employee`, `Department`) stays out of the container.

Everything runs on **PHP 8.1+**, with no Composer and no external libraries.

---

## 1. The problem: who builds what?

Here is a class written the "old way":

```php
final class EmployeeReport
{
    private JsonEmployeeRepository $employees;

    public function __construct()
    {
        // the class picks and builds its own dependency
        $this->employees = new JsonEmployeeRepository(__DIR__ . '/../../data/employees.json');
    }
}
```

The drawbacks are obvious:

- **not testable**: to try the report you need the real JSON file, you cannot hand it fake data;
- **tightly coupled**: if the data came from a database, you would have to edit the report;
- **hidden dependencies**: reading the constructor signature you cannot tell what the class really needs;
- **hard-coded values**: the file path is written inside the class.

Dependency Injection flips the perspective: the class **declares** what it needs.

```php
final class EmployeeReport
{
    public function __construct(private EmployeeRepositoryInterface $employees) {}
}
```

So far there is no container: you can wire everything by hand.

```php
$report = new EmployeeReport(new JsonEmployeeRepository('data/employees.json'));
```

That works as long as the dependency graph is small. When a service depends on another, which depends on a third, which needs some configuration, the wiring code becomes long and repetitive. This is where the **container** comes in: an object that knows how to assemble the graph for you.

---

## 2. The example: employees and departments

The domain is deliberately small: 10 employees, each with a birth date and a department.

| Class | Kind | What it does |
|---|---|---|
| `Container` | infrastructure | builds objects by reading constructors (see chapter 7) |
| `EmployeeRepositoryInterface` | contract | `findAll()` returns a list of `Employee` |
| `JsonEmployeeRepository` | service | implements the contract by reading a JSON file |
| `InMemoryEmployeeRepository` | service | implements the contract with an in-memory list (tests and demos) |
| `EmployeeReport` | service | receives the repository and prepares the rows to display |
| `Employee` | data | name, birth date, department; `getAge()` method |
| `Department` | data | code, name, location; `getInfo()` method |

The distinction between **services** and **data** is the key idea of this article:

- **services** *do* something, have dependencies, and there is usually only one instance of each: these are what the container has to assemble;
- **data** *represents* something: there are many, each with its own values (10 employees, 4 departments). They are created by whoever knows the values, here the repository reading the JSON. **They do not go through the container.**

Project layout:

```
php-di-employees/
├── start.bat / test.bat
├── autoload.php
├── bootstrap.php
├── data/employees.json
├── public/index.php
├── src/
│   ├── Container/   (Container, ContainerException, NotFoundException)
│   ├── Employee/    (Employee, Department, EmployeeRepositoryInterface,
│   │                 JsonEmployeeRepository, InMemoryEmployeeRepository)
│   └── Report/      (EmployeeReport)
└── tests/smoke.php
```

Loading classes needs no Composer: a PSR-4 autoloader of a few lines is enough (`App\` → `src/`).

```php
<?php
// Minimal PSR-4 autoloader for the App\ namespace -> src/
// (no Composer dependency)
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';

    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file     = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($file)) {
        require $file;
    }
});
```

---

## 3. The data: the JSON file

Each employee has a `birthDate` and a nested `department`:

```json
[
  { "id": 1,  "firstName": "Giulia",    "lastName": "Rossi",     "birthDate": "1985-03-15",
    "department": { "code": "SAL", "name": "Sales",               "location": "Milan"   } },
  ...
]
```

The full file contains 10 employees spread across four departments (Sales, Purchasing, Information Systems, Human Resources).

---

## 4. Department and Employee: the data classes

`Department` is a plain container with a method that describes the department:

```php
<?php
declare(strict_types=1);

namespace App\Employee;

final class Department
{
    public function __construct(
        private string $code,
        private string $name,
        private string $location,
    ) {}

    public function getCode(): string
    {
        return $this->code;
    }

    public function getInfo(): string
    {
        return sprintf('%s [%s] - %s office', $this->name, $this->code, $this->location);
    }
}
```

`Employee` holds the birth date and its `Department`. The age is computed with `DateTimeImmutable::diff()`, which returns *completed* years: if the birthday has not arrived yet, the current year does not count.

```php
<?php
declare(strict_types=1);

namespace App\Employee;

use DateTimeImmutable;

final class Employee
{
    public function __construct(
        private int $id,
        private string $firstName,
        private string $lastName,
        private DateTimeImmutable $birthDate,
        private Department $department,
    ) {}

    public function getId(): int
    {
        return $this->id;
    }

    public function getFullName(): string
    {
        return $this->firstName . ' ' . $this->lastName;
    }

    public function getBirthDate(): DateTimeImmutable
    {
        return $this->birthDate;
    }

    public function getDepartment(): Department
    {
        return $this->department;
    }

    /**
     * Age in completed years on the given date (default: today).
     * Passing the date makes the method testable.
     */
    public function getAge(?DateTimeImmutable $on = null): int
    {
        $on ??= new DateTimeImmutable('today');

        return $this->birthDate->diff($on)->y;
    }
}
```

`getAge()` takes an optional date. By default it uses today, but tests pass a fixed date so the result does not depend on the day you run them. It is the same idea as DI applied to a single method: what is needed (here, "what day is it") comes in from outside instead of being hidden inside.

Neither of these two classes knows anything about the container.

---

## 5. The interface and its implementations

The contract is one line: whoever honors it knows how to return a list of employees.

```php
<?php
declare(strict_types=1);

namespace App\Employee;

interface EmployeeRepositoryInterface
{
    /** @return Employee[] */
    public function findAll(): array;
}
```

The first implementation reads the JSON file. It has a single dependency, the file path:

```php
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
```

Two remarks:

1. The constructor asks for `string $jsonPath` with **no default value**. The container cannot invent a path: it has to be configured (we will see how in the bootstrap).
2. The repository **creates** `Employee` and `Department` with `new`. That is fine: they are data built from values read from the file, not services with dependencies.

The second implementation uses no file: it keeps the employees in memory. It is used in tests and demos.

```php
<?php
declare(strict_types=1);

namespace App\Employee;

/**
 * Second implementation of the interface: it keeps the employees in memory.
 * Handy for tests and demos, because it needs no file.
 */
final class InMemoryEmployeeRepository implements EmployeeRepositoryInterface
{
    /** @param Employee[] $employees */
    public function __construct(private array $employees = []) {}

    public function findAll(): array
    {
        return $this->employees;
    }
}
```

The interface name describes the **role** (`EmployeeRepositoryInterface`), the class names describe the **technique** (`Json…`, `InMemory…`). Tomorrow you could add a `DatabaseEmployeeRepository` without changing anything that uses the interface.

---

## 6. EmployeeReport: the consumer of the interface

The report depends on the **interface**, not on a concrete class:

```php
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
```

There is no `new JsonEmployeeRepository(...)` and no file path here. `EmployeeReport` simply says "I need someone who can list the employees" and does not know who that will be. If the data moved from JSON to a database, this file would not change.

But at this point the code has a problem: **nobody has decided yet which implementation to hand over**. That is the container's job.

---

## 7. The container, step by step

### 7.1 First step: a registry of factories

The simplest possible version is a map of *id → function that builds the object*:

```php
final class MiniContainer
{
    private array $factories = [];

    public function set(string $id, callable $factory): void
    {
        $this->factories[$id] = $factory;
    }

    public function get(string $id): mixed
    {
        if (!isset($this->factories[$id])) {
            throw new RuntimeException("Service $id is not registered");
        }

        return ($this->factories[$id])($this);
    }
}
```

Usage:

```php
$c = new MiniContainer();

$c->set(
    EmployeeRepositoryInterface::class,
    fn() => new JsonEmployeeRepository(__DIR__ . '/data/employees.json')
);
$c->set(
    EmployeeReport::class,
    fn(MiniContainer $c) => new EmployeeReport($c->get(EmployeeRepositoryInterface::class))
);

$report = $c->get(EmployeeReport::class);   // works
```

It is already a container. But it has two limits:

1. it creates a **new instance on every `get()`**, even when you would want a shared one (a database connection, a repository that has already read its data);
2. you have to **write every factory by hand**, even for trivial classes like `EmployeeReport`, whose constructor already says everything.

### 7.2 Second step: reading constructors with Reflection

PHP can read a constructor's signature at runtime through the **Reflection API**:

```php
$reflection  = new ReflectionClass(EmployeeReport::class);
$constructor = $reflection->getConstructor();

foreach ($constructor->getParameters() as $param) {
    echo $param->getName(), ' => ', $param->getType()->getName(), PHP_EOL;
}
// employees => App\Employee\EmployeeRepositoryInterface
```

The `EmployeeReport` constructor states that it needs an `EmployeeRepositoryInterface`. The container can therefore:

1. inspect the constructor of the requested class;
2. for each parameter **typed with a class or an interface**, recursively call `get()` on that type;
3. for scalar parameters, use a configured value or the default;
4. instantiate the class with the resolved arguments (`newInstanceArgs`).

This is **autowiring**: no hand-written factory for concrete classes.

### 7.3 The complete container

First the two exceptions, to tell "not found" apart from other configuration errors:

```php
<?php
declare(strict_types=1);

namespace App\Container;

use RuntimeException;

class ContainerException extends RuntimeException {}
```

```php
<?php
declare(strict_types=1);

namespace App\Container;

final class NotFoundException extends ContainerException {}
```

Then the container itself, about 180 lines, a good part of them comments and blank lines:

```php
<?php
declare(strict_types=1);

namespace App\Container;

use Closure;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;

final class Container
{
    /** @var array<string, array{concrete: Closure|string, shared: bool}> */
    private array $bindings = [];

    /** @var array<string, object> instances already created (singletons) */
    private array $instances = [];

    /** @var array<string, array<string, mixed>> parameter values: [class][parameterName] */
    private array $parameters = [];

    /** @var array<string, true> ids being resolved right now (cycle detection) */
    private array $resolving = [];

    /**
     * Registers a service. $concrete can be:
     *  - null        → the id itself is the class to build
     *  - string      → a concrete class name (e.g. interface → implementation)
     *  - Closure     → a custom factory, it receives the container
     */
    public function bind(string $id, Closure|string|null $concrete = null, bool $shared = false): void
    {
        $this->bindings[$id] = [
            'concrete' => $concrete ?? $id,
            'shared'   => $shared,
        ];

        // a new binding discards any instance created earlier
        unset($this->instances[$id]);
    }

    public function singleton(string $id, Closure|string|null $concrete = null): void
    {
        $this->bind($id, $concrete, shared: true);
    }

    /** Registers an object that has already been built. */
    public function instance(string $id, object $instance): void
    {
        $this->instances[$id] = $instance;
    }

    /**
     * Sets the value of a scalar constructor parameter of a class.
     * The value can be a Closure: it is run when the object is built.
     */
    public function setParameter(string $class, string $name, mixed $value): void
    {
        $this->parameters[$class][$name] = $value;
    }

    public function has(string $id): bool
    {
        return isset($this->bindings[$id])
            || isset($this->instances[$id])
            || class_exists($id);
    }

    public function get(string $id): mixed
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        if (isset($this->resolving[$id])) {
            $chain = implode(' → ', array_keys($this->resolving)) . " → $id";
            throw new ContainerException("Circular dependency: $chain");
        }

        $this->resolving[$id] = true;

        try {
            $object = $this->resolve($id);
        } finally {
            unset($this->resolving[$id]);
        }

        if ($this->bindings[$id]['shared'] ?? false) {
            $this->instances[$id] = $object;
        }

        return $object;
    }

    private function resolve(string $id): mixed
    {
        $concrete = $this->bindings[$id]['concrete'] ?? $id;

        // 1. explicit factory
        if ($concrete instanceof Closure) {
            return $concrete($this);
        }

        // 2. alias to another class (e.g. interface → implementation)
        if ($concrete !== $id) {
            return $this->get($concrete);
        }

        // 3. autowiring
        return $this->build($id);
    }

    private function build(string $class): object
    {
        if (!class_exists($class) && !interface_exists($class)) {
            throw new NotFoundException(sprintf(
                'Cannot resolve "%s": it is neither an existing class nor a registered service.',
                $class
            ));
        }

        $reflection = new ReflectionClass($class);

        if (!$reflection->isInstantiable()) {
            throw new ContainerException(sprintf(
                '"%s" is not instantiable (interface or abstract class without a binding?).',
                $class
            ));
        }

        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return new $class();
        }

        $args = array_map(
            fn(ReflectionParameter $p) => $this->resolveParameter($p, $class),
            $constructor->getParameters()
        );

        return $reflection->newInstanceArgs($args);
    }

    private function resolveParameter(ReflectionParameter $param, string $class): mixed
    {
        $name = $param->getName();

        // a) value explicitly configured for this class/parameter
        if (isset($this->parameters[$class]) && array_key_exists($name, $this->parameters[$class])) {
            $value = $this->parameters[$class][$name];

            return $value instanceof Closure ? $value($this) : $value;
        }

        // b) parameter typed with a class/interface → resolve it recursively
        $type = $param->getType();

        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            return $this->get($type->getName());
        }

        // c) default value declared in the constructor
        if ($param->isDefaultValueAvailable()) {
            return $param->getDefaultValue();
        }

        // d) nullable parameter without a default
        if ($type !== null && $type->allowsNull()) {
            return null;
        }

        throw new ContainerException(sprintf(
            'Cannot resolve parameter $%s of %s: configure it with setParameter().',
            $name,
            $class
        ));
    }
}
```

### 7.4 How it works, method by method

**The four properties** are the container's entire memory:

| Property | Holds | Used for |
|---|---|---|
| `$bindings` | `id → [concrete, shared]` | remembering what maps to what |
| `$instances` | `id → object` | always returning the same instance for singletons |
| `$parameters` | `class → [parameter name → value]` | supplying scalar values to constructors |
| `$resolving` | the ids being built right now | noticing circular dependencies |

**The public interface:**

| Method | What it does |
|---|---|
| `bind($id, $concrete, $shared)` | registers a service. `$concrete` can be `null` (the id is already the class), a string (another class: typically interface → implementation) or a `Closure` (a hand-written factory) |
| `singleton($id, $concrete)` | like `bind`, but with `shared: true`: the instance is created only once |
| `instance($id, $object)` | registers an object that is already built (handy in tests) |
| `setParameter($class, $name, $value)` | supplies the value of a scalar constructor parameter; the value can be a `Closure`, run only when the object is built |
| `has($id)` | tells whether the id is known |
| `get($id)` | returns the requested object, building it if needed |

**`get()`** does four things, in order:

1. if a shared instance already exists for that id, it returns it;
2. if the id is **already being built**, then A needs B and B needs A: it raises an error showing the whole chain;
3. it delegates to `resolve()`, inside a `try/finally` that always removes the id from `$resolving`, even on error;
4. if the binding is `shared`, it keeps the object for later requests.

**`resolve()`** picks the strategy, in a precise order:

| Step | Condition | What happens |
|---|---|---|
| 1 | the binding is a `Closure` | runs it, passing the container |
| 2 | the binding is another class (`concrete !== id`) | calls `get()` on that class: this is how `EmployeeRepositoryInterface` becomes `JsonEmployeeRepository` |
| 3 | no binding | autowiring through `build()` |

**`build()`** checks that the class exists and is instantiable (an interface or an abstract class is not) and then, through the constructor, resolves each parameter with `resolveParameter()` and creates the object with `newInstanceArgs`.

**`resolveParameter()`** decides where each argument comes from, in an order that is the heart of the container:

| Order | Condition | Value used |
|---|---|---|
| a | there is a `setParameter()` for that class and name | the configured value (if it is a `Closure`, the result of running it) |
| b | the type is a class or an interface | `get()` on that type (recursion) |
| c | the constructor declares a default value | the default |
| d | the type allows `null` | `null` |
| — | none of the above | a `ContainerException` saying which parameter to configure |

### 7.5 Errors speak clearly

A good container does not fail obscurely. These are the real messages it produces:

```
// Interface without a binding
"App\Employee\EmployeeRepositoryInterface" is not instantiable (interface or abstract class without a binding?).

// Scalar parameter that was not configured
Cannot resolve parameter $jsonPath of App\Employee\JsonEmployeeRepository: configure it with setParameter().

// Class that does not exist
Cannot resolve "App\Not\Found": it is neither an existing class nor a registered service.

// Circular dependency
Circular dependency: CycleA → CycleB → CycleA
```

---

## 8. The bootstrap: where everything is configured

A single file where you describe how the parts are wired together:

```php
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
```

That is two statements. Everything else is deduced from the constructor types:

| Class | Must be registered? | Reason |
|---|---|---|
| `EmployeeRepositoryInterface` | **yes**: `singleton` pointing to `JsonEmployeeRepository` | an interface cannot be instantiated: the container cannot know which implementation you want; and one shared instance avoids re-reading the file |
| `JsonEmployeeRepository` | only its parameter: `setParameter` | the file path is a string, the container cannot guess it |
| `EmployeeReport` | **no** | autowiring: it only depends on the interface, already wired |
| `Employee`, `Department` | **no**, and they must not enter the container | they are data, the repository creates them |

One detail to remember: the `singleton`'s sharing applies **to the registered id**. If you ask for `get(EmployeeRepositoryInterface::class)` you always get the same object; if you ask directly for `get(JsonEmployeeRepository::class)`, which is not registered, the container creates a new one every time. That is why application code always asks for the **interface**, never for the concrete class.

---

## 9. What happens when you ask for an `EmployeeReport`

```
get(EmployeeReport)
 └─ no binding → autowiring
     └─ parameter $employees: EmployeeRepositoryInterface
         └─ get(EmployeeRepositoryInterface) → singleton, binding to JsonEmployeeRepository
             └─ get(JsonEmployeeRepository) → no binding → autowiring
                 └─ parameter $jsonPath: string → setParameter() → ".../data/employees.json"
```

The container descends to the bottom, builds the objects from the bottom up and hands them over. On the second `get(EmployeeRepositoryInterface::class)` it returns the instance it already created, so the JSON file is read only once.

---

## 10. The front controller

```php
<?php
// Front controller
declare(strict_types=1);

/** @var App\Container\Container $container */
$container = require __DIR__ . '/../bootstrap.php';

use App\Report\EmployeeReport;

function e(string|int|float $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

try {
    // The only place where application code "asks" the container for something
    $report = $container->get(EmployeeReport::class);
    $rows   = $report->rows();
    $avg    = $report->averageAge();
} catch (Throwable $ex) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Error: ' . $ex->getMessage();
    exit;
}

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Employees - DI container example</title>
    <style>
        body  { font-family: system-ui, sans-serif; margin: 2rem auto; max-width: 52rem; padding: 0 1rem; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border-bottom: 1px solid #ccc; padding: .5rem .6rem; text-align: left; }
        th    { background: #f3f3f3; }
        td.n  { text-align: right; }
    </style>
</head>
<body>
    <h1>Employees</h1>
    <table>
        <thead>
            <tr><th>#</th><th>Name</th><th>Age</th><th>Department</th></tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td class="n"><?= e($r['id']) ?></td>
                <td><?= e($r['name']) ?></td>
                <td class="n"><?= e($r['age']) ?></td>
                <td><?= e($r['department']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p>Average age: <strong><?= e(number_format($avg, 1, '.', ',')) ?></strong> years</p>
</body>
</html>
```

This is the **only** place where application code talks to the container. It asks for the `EmployeeReport` and from then on works with ordinary PHP objects. The rest of the file is just the HTML table (with `htmlspecialchars` on the values).

Start it with:

```bash
php -S localhost:8000 -t public
# then open: http://localhost:8000/
```

On Windows, just double-click `start.bat`. Result (the ages depend on the date you run the app; here they are computed as of 8 October 2026):

| # | Name | Age | Department |
|---:|---|---:|---|
| 1 | Giulia Rossi | 41 | Sales [SAL] - Milan office |
| 2 | Marco Bianchi | 35 | Information Systems [IT] - Rome office |
| 3 | Francesca Verdi | 48 | Purchasing [PUR] - Turin office |
| 4 | Luca Ferrari | 31 | Information Systems [IT] - Rome office |
| 5 | Chiara Esposito | 38 | Human Resources [HR] - Bologna office |
| 6 | Andrea Romano | 54 | Sales [SAL] - Milan office |
| 7 | Sara Colombo | 26 | Information Systems [IT] - Rome office |
| 8 | Davide Ricci | 43 | Purchasing [PUR] - Turin office |
| 9 | Elena Marino | 33 | Human Resources [HR] - Bologna office |
| 10 | Paolo Greco | 58 | Sales [SAL] - Milan office |

Average age: **40.7** years

---

## 11. The tests

The test uses the same `bootstrap.php` as production and a fixed date for the ages:

```php
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
```

Run it with `php tests/smoke.php` or `test.bat`. Test number 6 is the one that shows the benefit of the interface: a fresh container is created, and with `instance()` it is handed an in-memory repository holding **one** employee, and `EmployeeReport` works without a single line of the report having changed. No file, no configuration.

---

## 12. Why Employee and Department are not in the container

A container exists to resolve **dependencies**: "to build X I need a Y". `Employee` has no dependencies of that kind: it has values (a name, a date). If you try to ask the container for one:

```php
$container->get(Employee::class);
// ContainerException: Cannot resolve parameter $id of App\Employee\Employee: configure it with setParameter().
```

the container does not know which id, which name, which date to use, and rightly stops. A practical rule:

- if an object has **collaborators** (other services), the container builds it;
- if an object has **values** (domain data), whoever knows the values builds it, with `new`: usually a repository or a factory.

Mixing the two is the most common reason containers "don't work": you try to make the container build objects that are not services.

---

## 13. Beware of the Service Locator

A powerful container brings a temptation: **injecting the container itself** into classes and calling `$container->get(...)` everywhere.

```php
// ❌ Anti-pattern: Service Locator
final class EmployeeReport
{
    public function __construct(private Container $container) {}

    public function rows(): array
    {
        $employees = $this->container->get(EmployeeRepositoryInterface::class)->findAll();
        // ...
    }
}
```

Dependencies become hidden again (you can no longer read them from the constructor), tests get awkward, and the container turns into a disguised global. The practical rule: **the container lives only in the "composition root"**, the application's entry point (here `bootstrap.php` and `public/index.php`). Everything else receives its collaborators ready-made.

---

## 14. Limits and possible extensions

The container is deliberately small. Here is what it does not do, and how you could extend it:

- **Reflection cache**: Reflection has a cost. In production you can store, for each class, the list of resolved parameters, in a static array or in a generated file.
- **Early validation**: configuration errors show up on the first request, not at startup. A "compiled" container (like Symfony's) validates the whole graph before serving the first request.
- **Method injection**: a `call()` method that also resolves the arguments of a method, useful for controller actions.
- **PSR-11 compatibility**: just make `Container` implement `Psr\Container\ContainerInterface` (methods `get` and `has`) and the exceptions implement the matching interfaces, and the container becomes interchangeable with any library that supports it.
- **Automatic aliasing**: wiring an interface to its implementation by itself when there is only one. Here it was left explicit on purpose: a visible `bind()` in `bootstrap.php` explains the architecture better than a hidden convention.

It is the same mechanism that frameworks like Laravel and Symfony already provide, with caching, compilation and many conveniences on top.

---

## Conclusion

With a handful of classes the main principles emerge:

1. **Services** declare their dependencies in the constructor, typed on **interfaces** whenever possible; the container wires them up.
2. **Data** (`Employee`, `Department`) stays out of the container: whoever knows the values creates it.
3. You register **only what the container cannot deduce**: interfaces (`bind`/`singleton`), scalar parameters (`setParameter`) and instances to share (`singleton`).
4. Changing the implementation, for a test or a new data source, means changing **one line** in the bootstrap.
5. The container lives in the **composition root**; from there on the code works with ordinary objects.

Building one, even just for fun, changes how you read frameworks: when they report an unwired service or a circular dependency, you know exactly which piece of logic is at work.

*To go further: add a `DatabaseEmployeeRepository`, change one line in `bootstrap.php`, and notice that `EmployeeReport` does not need to be touched.*

---

### Appendix: quick checklist

- [ ] Services and data are kept apart: only services go through the container.
- [ ] Every service declares its dependencies in the constructor, typed, preferably on an interface.
- [ ] Every interface has a `bind()` or `singleton()` pointing to the chosen implementation.
- [ ] Scalar values (paths, keys, timeouts) are configured with `setParameter()` in `bootstrap.php`.
- [ ] Instances to share (repositories, clients, connections) are `singleton`s, and are always requested through the registered id.
- [ ] The container is not injected into application services.
- [ ] Tests replace a binding (`instance()` or `bind()`) without touching production code.
