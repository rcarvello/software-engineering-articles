# Dependency Injection in PHP: costruire e usare un DI Container, con un esempio completo

> Un DI container non è magia: è una classe che legge i costruttori e crea gli oggetti al posto tuo. In questo articolo ne costruiamo uno in PHP puro, lo spieghiamo riga per riga e lo usiamo su un caso concreto: un elenco di dipendenti letto da un file JSON, con un'interfaccia che permette di cambiare la sorgente dei dati senza toccare il resto del codice.

Quando si parla di Dependency Injection (DI) si pensa subito a un framework. Ma la DI è un **principio**, non una funzione di un framework: una classe dichiara ciò che le serve, e qualcun altro glielo consegna. Il **container** è lo strumento che automatizza quella consegna.

In questo articolo vediamo:

- perché la DI esiste (il problema);
- il codice del dominio: `Employee`, `Department`, un'interfaccia di repository e due implementazioni;
- il **container completo**, costruito in tre passi e spiegato metodo per metodo;
- come si configura (`bootstrap.php`) e come si usa (`public/index.php`);
- i test, e perché i dati (`Employee`, `Department`) restano fuori dal container.

Tutto gira su **PHP 8.1+**, senza Composer e senza librerie esterne.

---

## 1. Il problema: chi costruisce chi?

Ecco una classe scritta "alla vecchia maniera":

```php
final class EmployeeReport
{
    private JsonEmployeeRepository $employees;

    public function __construct()
    {
        // la classe sceglie e costruisce da sola la propria dipendenza
        $this->employees = new JsonEmployeeRepository(__DIR__ . '/../../data/employees.json');
    }
}
```

I difetti sono evidenti:

- **non testabile**: per provare il report serve il vero file JSON, non puoi dargli dati finti;
- **accoppiata**: se i dati arrivassero da un database, dovresti modificare il report;
- **dipendenze nascoste**: leggendo la firma del costruttore non capisci cosa serva davvero;
- **valori fissi nel codice**: il percorso del file è scritto dentro la classe.

La Dependency Injection rovescia la prospettiva: la classe **dichiara** ciò che le serve.

```php
final class EmployeeReport
{
    public function __construct(private EmployeeRepositoryInterface $employees) {}
}
```

Fin qui non c'è nessun container: puoi assemblare tutto a mano.

```php
$report = new EmployeeReport(new JsonEmployeeRepository('data/employees.json'));
```

Funziona finché il grafo delle dipendenze è piccolo. Quando un servizio dipende da un altro, che dipende da un terzo, che ha bisogno di una configurazione, il codice di assemblaggio diventa lungo e ripetitivo. Qui entra in gioco il **container**: un oggetto che sa assemblare il grafo al posto tuo.

---

## 2. L'esempio: dipendenti e dipartimenti

Il dominio è volutamente piccolo: 10 dipendenti, ciascuno con una data di nascita e un dipartimento.

| Classe | Tipo | Cosa fa |
|---|---|---|
| `Container` | infrastruttura | costruisce gli oggetti leggendo i costruttori (lo vediamo nel capitolo 7) |
| `EmployeeRepositoryInterface` | contratto | `findAll()` restituisce un elenco di `Employee` |
| `JsonEmployeeRepository` | servizio | implementa il contratto leggendo un file JSON |
| `InMemoryEmployeeRepository` | servizio | implementa il contratto con un elenco in memoria (test e demo) |
| `EmployeeReport` | servizio | riceve il repository e prepara le righe da mostrare |
| `Employee` | dato | nome, data di nascita, dipartimento; metodo `getAge()` |
| `Department` | dato | codice, nome, sede; metodo `getInfo()` |

La distinzione tra **servizi** e **dati** è la chiave dell'articolo:

- i **servizi** *fanno* qualcosa, hanno dipendenze e di solito ne esiste una sola istanza: sono quelli che il container deve assemblare;
- i **dati** *rappresentano* qualcosa: ne esistono tanti, ognuno con valori propri (10 dipendenti, 4 dipartimenti). Li crea chi conosce i valori, qui il repository leggendo il JSON. **Non passano dal container.**

Struttura del progetto:

```
php-di-employees/
├── avvia.bat / test.bat
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

Per caricare le classi non serve Composer: basta un autoloader PSR-4 di poche righe (`App\` → `src/`).

```php
<?php
// Autoloader PSR-4 minimale per il namespace App\ -> src/
// (nessuna dipendenza da Composer)
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

## 3. I dati: il file JSON

Ogni dipendente ha una `dataNascita` e un `department` annidato:

```json
[
  { "id": 1,  "nome": "Giulia",    "cognome": "Rossi",     "dataNascita": "1985-03-15",
    "department": { "codice": "VEN", "nome": "Vendite",              "sede": "Milano"  } },
  ...
]
```

Il file completo contiene 10 dipendenti, distribuiti su quattro dipartimenti (Vendite, Acquisti, Sistemi Informativi, Risorse Umane).

---

## 4. Department ed Employee: le classi di dato

`Department` è un semplice contenitore con un metodo che descrive il dipartimento:

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
        return sprintf('%s [%s] - sede di %s', $this->name, $this->code, $this->location);
    }
}
```

`Employee` contiene la data di nascita e il suo `Department`. L'età si calcola con `DateTimeImmutable::diff()`, che restituisce gli anni *compiuti*: se il compleanno non è ancora arrivato, l'anno corrente non conta.

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
     * Età in anni compiuti alla data indicata (default: oggi).
     * Passare la data rende il metodo testabile.
     */
    public function getAge(?DateTimeImmutable $on = null): int
    {
        $on ??= new DateTimeImmutable('today');

        return $this->birthDate->diff($on)->y;
    }
}
```

`getAge()` accetta una data opzionale. Di default usa oggi, ma nei test si passa una data fissa, così il risultato non dipende dal giorno in cui lanci i test. È la stessa idea della DI applicata a un singolo metodo: ciò che serve (qui, "che giorno è") entra dall'esterno invece di essere nascosto dentro.

Nessuna di queste due classi conosce il container.

---

## 5. L'interfaccia e le sue implementazioni

Il contratto è una riga: chi lo rispetta sa restituire un elenco di dipendenti.

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

La prima implementazione legge il file JSON. Ha una sola dipendenza, il percorso del file:

```php
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
```

Due osservazioni:

1. Il costruttore chiede `string $jsonPath`, **senza valore di default**. Il container non può inventare un percorso: va configurato (lo vedremo nel bootstrap).
2. Il repository **crea** `Employee` e `Department` con `new`. Va bene così: sono dati costruiti a partire da valori letti dal file, non servizi con dipendenze.

La seconda implementazione non usa file: tiene i dipendenti in memoria. Serve nei test e nelle demo.

```php
<?php
declare(strict_types=1);

namespace App\Employee;

/**
 * Seconda implementazione dell'interfaccia: tiene i dipendenti in memoria.
 * Utile nei test e nelle demo, perché non richiede alcun file.
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

Il nome dell'interfaccia descrive il **ruolo** (`EmployeeRepositoryInterface`), quello delle classi la **tecnica** (`Json…`, `InMemory…`). Domani potresti aggiungere un `DatabaseEmployeeRepository` senza cambiare nulla di ciò che usa l'interfaccia.

---

## 6. EmployeeReport: chi usa l'interfaccia

Il report dipende dall'**interfaccia**, non da una classe concreta:

```php
<?php
declare(strict_types=1);

namespace App\Report;

use App\Employee\Employee;
use App\Employee\EmployeeRepositoryInterface;
use DateTimeImmutable;

final class EmployeeReport
{
    // Dipendenza dichiarata sull'INTERFACCIA: il report non sa da dove arrivano i dati
    public function __construct(private EmployeeRepositoryInterface $employees) {}

    /** @return array<int, array{id: int, nome: string, eta: int, dipartimento: string}> */
    public function rows(?DateTimeImmutable $on = null): array
    {
        return array_map(
            static fn(Employee $e) => [
                'id'           => $e->getId(),
                'nome'         => $e->getFullName(),
                'eta'          => $e->getAge($on),
                'dipartimento' => $e->getDepartment()->getInfo(),
            ],
            $this->employees->findAll()
        );
    }

    public function averageAge(?DateTimeImmutable $on = null): float
    {
        $ages = array_column($this->rows($on), 'eta');

        return $ages === [] ? 0.0 : array_sum($ages) / count($ages);
    }
}
```

Qui non c'è nessun `new JsonEmployeeRepository(...)` e nessun percorso di file. `EmployeeReport` dice solo "mi serve qualcuno che sappia elencare i dipendenti" e non sa chi lo farà. Se i dati passassero da JSON a database, questo file non cambierebbe.

Ma a questo punto il codice ha un problema: **nessuno ha ancora deciso quale implementazione consegnare**. È il lavoro del container.

---

## 7. Il container, passo per passo

### 7.1 Primo passo: un registro di factory

La versione più semplice possibile è una mappa *id → funzione che costruisce l'oggetto*:

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
            throw new RuntimeException("Servizio $id non registrato");
        }

        return ($this->factories[$id])($this);
    }
}
```

Uso:

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

$report = $c->get(EmployeeReport::class);   // funziona
```

È già un container. Ma ha due limiti:

1. crea una **nuova istanza a ogni `get()`**, anche quando vorresti un'istanza condivisa (la connessione a un database, il repository con i dati già letti);
2. devi **scrivere a mano ogni factory**, anche per classi banali come `EmployeeReport`, il cui costruttore dice già tutto.

### 7.2 Secondo passo: leggere i costruttori con la Reflection

PHP permette di leggere a runtime la firma di un costruttore, tramite la **Reflection API**:

```php
$reflection  = new ReflectionClass(EmployeeReport::class);
$constructor = $reflection->getConstructor();

foreach ($constructor->getParameters() as $param) {
    echo $param->getName(), ' => ', $param->getType()->getName(), PHP_EOL;
}
// employees => App\Employee\EmployeeRepositoryInterface
```

Il costruttore di `EmployeeReport` dichiara che gli serve un `EmployeeRepositoryInterface`. Il container può quindi:

1. ispezionare il costruttore della classe richiesta;
2. per ogni parametro **tipizzato con una classe o un'interfaccia**, chiamare ricorsivamente `get()` su quel tipo;
3. per i parametri scalari, usare un valore configurato oppure il default;
4. istanziare la classe con gli argomenti risolti (`newInstanceArgs`).

Questo è l'**autowiring**: nessuna factory scritta a mano per le classi concrete.

### 7.3 Il container completo

Prima le due eccezioni, per distinguere "non trovato" dagli altri errori di configurazione:

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

Poi il container vero e proprio, circa 180 righe, in buona parte commenti e righe vuote:

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

    /** @var array<string, object> istanze già create (singleton) */
    private array $instances = [];

    /** @var array<string, array<string, mixed>> valori per parametri: [classe][nomeParametro] */
    private array $parameters = [];

    /** @var array<string, true> id in fase di risoluzione (anti-cicli) */
    private array $resolving = [];

    /**
     * Registra un servizio. $concrete può essere:
     *  - null        → l'id stesso è la classe da costruire
     *  - string      → nome di classe concreta (es. interfaccia → implementazione)
     *  - Closure     → factory personalizzata, riceve il container
     */
    public function bind(string $id, Closure|string|null $concrete = null, bool $shared = false): void
    {
        $this->bindings[$id] = [
            'concrete' => $concrete ?? $id,
            'shared'   => $shared,
        ];

        // un nuovo binding invalida un'eventuale istanza precedente
        unset($this->instances[$id]);
    }

    public function singleton(string $id, Closure|string|null $concrete = null): void
    {
        $this->bind($id, $concrete, shared: true);
    }

    /** Registra direttamente un oggetto già costruito. */
    public function instance(string $id, object $instance): void
    {
        $this->instances[$id] = $instance;
    }

    /**
     * Imposta il valore di un parametro scalare del costruttore di una classe.
     * Il valore può essere una Closure: verrà eseguita al momento della costruzione.
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
            throw new ContainerException("Dipendenza circolare: $chain");
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

        // 1. factory esplicita
        if ($concrete instanceof Closure) {
            return $concrete($this);
        }

        // 2. alias verso un'altra classe (es. interfaccia → implementazione)
        if ($concrete !== $id) {
            return $this->get($concrete);
        }

        // 3. autowiring
        return $this->build($id);
    }

    private function build(string $class): object
    {
        if (!class_exists($class) && !interface_exists($class)) {
            throw new NotFoundException(
                "Impossibile risolvere «{$class}»: non è una classe esistente né un servizio registrato."
            );
        }

        $reflection = new ReflectionClass($class);

        if (!$reflection->isInstantiable()) {
            throw new ContainerException(
                "«{$class}» non è istanziabile (interfaccia o classe astratta senza binding?)."
            );
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

        // a) valore configurato esplicitamente per questa classe/parametro
        if (isset($this->parameters[$class]) && array_key_exists($name, $this->parameters[$class])) {
            $value = $this->parameters[$class][$name];

            return $value instanceof Closure ? $value($this) : $value;
        }

        // b) parametro tipizzato con una classe/interfaccia → risolvi ricorsivamente
        $type = $param->getType();

        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            return $this->get($type->getName());
        }

        // c) valore di default dichiarato nel costruttore
        if ($param->isDefaultValueAvailable()) {
            return $param->getDefaultValue();
        }

        // d) parametro nullable senza default
        if ($type !== null && $type->allowsNull()) {
            return null;
        }

        throw new ContainerException(sprintf(
            'Impossibile risolvere il parametro $%s di %s: configuralo con setParameter().',
            $name,
            $class
        ));
    }
}
```

### 7.4 Come funziona, metodo per metodo

**Le quattro proprietà** sono tutta la memoria del container:

| Proprietà | Contiene | Serve a |
|---|---|---|
| `$bindings` | `id → [concrete, shared]` | ricordare cosa abbinare a cosa |
| `$instances` | `id → oggetto` | restituire sempre la stessa istanza ai singleton |
| `$parameters` | `classe → [nome parametro → valore]` | fornire i valori scalari al costruttore |
| `$resolving` | gli id in costruzione in questo momento | accorgersi delle dipendenze circolari |

**L'interfaccia pubblica:**

| Metodo | Cosa fa |
|---|---|
| `bind($id, $concrete, $shared)` | registra un servizio. `$concrete` può essere `null` (l'id è già la classe), una stringa (un'altra classe: tipicamente interfaccia → implementazione) o una `Closure` (factory scritta a mano) |
| `singleton($id, $concrete)` | come `bind`, ma con `shared: true`: l'istanza viene creata una volta sola |
| `instance($id, $oggetto)` | registra un oggetto già costruito (comodo nei test) |
| `setParameter($classe, $nome, $valore)` | fornisce il valore di un parametro scalare del costruttore; il valore può essere una `Closure`, eseguita solo al momento della costruzione |
| `has($id)` | dice se l'id è conosciuto |
| `get($id)` | restituisce l'oggetto richiesto, costruendolo se serve |

**`get()`** fa quattro cose, in ordine:

1. se esiste già un'istanza condivisa per quell'id, la restituisce;
2. se l'id è **già in costruzione**, significa che A ha bisogno di B e B ha bisogno di A: solleva un errore che mostra l'intera catena;
3. delega a `resolve()`, dentro un `try/finally` che toglie sempre l'id da `$resolving`, anche in caso di errore;
4. se il binding è `shared`, conserva l'oggetto per le prossime richieste.

**`resolve()`** sceglie la strategia, seguendo un ordine preciso:

| Passo | Condizione | Cosa succede |
|---|---|---|
| 1 | il binding è una `Closure` | la esegue, passandole il container |
| 2 | il binding è un'altra classe (`concrete !== id`) | chiama `get()` su quella: così `EmployeeRepositoryInterface` diventa `JsonEmployeeRepository` |
| 3 | nessun binding | autowiring con `build()` |

**`build()`** controlla che la classe esista, che sia istanziabile (un'interfaccia o una classe astratta non lo è) e poi, tramite il costruttore, risolve ogni parametro con `resolveParameter()` e crea l'oggetto con `newInstanceArgs`.

**`resolveParameter()`** decide da dove prendere ogni argomento, con un ordine che è il cuore del container:

| Ordine | Condizione | Valore usato |
|---|---|---|
| a | esiste un `setParameter()` per quella classe e quel nome | il valore configurato (se è una `Closure`, il risultato della sua esecuzione) |
| b | il tipo è una classe o un'interfaccia | `get()` su quel tipo (ricorsione) |
| c | il costruttore dichiara un valore di default | il default |
| d | il tipo ammette `null` | `null` |
| — | nessuno dei precedenti | `ContainerException` che dice quale parametro configurare |

### 7.5 Gli errori sono parlanti

Un buon container non fallisce in modo oscuro. Questi sono i messaggi reali che produce:

```
// Interfaccia senza binding
«App\Employee\EmployeeRepositoryInterface» non è istanziabile (interfaccia o classe astratta senza binding?).

// Parametro scalare non configurato
Impossibile risolvere il parametro $jsonPath di App\Employee\JsonEmployeeRepository: configuralo con setParameter().

// Classe inesistente
Impossibile risolvere «App\Non\Esiste»: non è una classe esistente né un servizio registrato.

// Dipendenza circolare
Dipendenza circolare: CycleA → CycleB → CycleA
```

---

## 8. Il bootstrap: dove si configura tutto

Un unico file in cui descrivi come vanno collegate le parti:

```php
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
```

Sono due istruzioni. Tutto il resto si deduce dai tipi dei costruttori:

| Classe | Va registrata? | Motivo |
|---|---|---|
| `EmployeeRepositoryInterface` | **sì**: `singleton` verso `JsonEmployeeRepository` | un'interfaccia non si può istanziare: il container non può sapere quale implementazione vuoi; e una sola istanza condivisa evita di rileggere il file |
| `JsonEmployeeRepository` | solo il parametro: `setParameter` | il percorso del file è una stringa, il container non può indovinarla |
| `EmployeeReport` | **no** | autowiring: dipende solo dall'interfaccia, già collegata |
| `Employee`, `Department` | **no**, e non devono entrare nel container | sono dati, li crea il repository |

Un dettaglio da ricordare: la condivisione del `singleton` vale **per l'id registrato**. Se chiedi `get(EmployeeRepositoryInterface::class)` ottieni sempre lo stesso oggetto; se chiedi direttamente `get(JsonEmployeeRepository::class)`, che non è registrato, il container ne crea uno nuovo ogni volta. Per questo il codice applicativo chiede sempre l'**interfaccia**, mai la classe concreta.

---

## 9. Cosa succede quando chiedi un `EmployeeReport`

```
get(EmployeeReport)
 └─ nessun binding → autowiring
     └─ parametro $employees: EmployeeRepositoryInterface
         └─ get(EmployeeRepositoryInterface) → singleton, binding verso JsonEmployeeRepository
             └─ get(JsonEmployeeRepository) → nessun binding → autowiring
                 └─ parametro $jsonPath: string → setParameter() → ".../data/employees.json"
```

Il container scende fino in fondo, costruisce gli oggetti dal basso verso l'alto e li consegna. Al secondo `get(EmployeeRepositoryInterface::class)` restituisce l'istanza già creata, quindi il file JSON viene letto una sola volta.

---

## 10. Il front controller

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
    // L'unico punto in cui il codice applicativo "chiede" qualcosa al container
    $report = $container->get(EmployeeReport::class);
    $rows   = $report->rows();
    $avg    = $report->averageAge();
} catch (Throwable $ex) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Errore: ' . $ex->getMessage();
    exit;
}

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <title>Dipendenti - esempio DI container</title>
    <style>
        body  { font-family: system-ui, sans-serif; margin: 2rem auto; max-width: 52rem; padding: 0 1rem; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border-bottom: 1px solid #ccc; padding: .5rem .6rem; text-align: left; }
        th    { background: #f3f3f3; }
        td.n  { text-align: right; }
    </style>
</head>
<body>
    <h1>Dipendenti</h1>
    <table>
        <thead>
            <tr><th>#</th><th>Nome</th><th>Et&agrave;</th><th>Dipartimento</th></tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td class="n"><?= e($r['id']) ?></td>
                <td><?= e($r['nome']) ?></td>
                <td class="n"><?= e($r['eta']) ?></td>
                <td><?= e($r['dipartimento']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p>Et&agrave; media: <strong><?= e(number_format($avg, 1, ',', '.')) ?></strong> anni</p>
</body>
</html>
```

È l'**unico** punto in cui il codice applicativo parla con il container. Chiede l'`EmployeeReport` e da lì in avanti lavora con normali oggetti PHP. Il resto del file è solo la tabella HTML (con `htmlspecialchars` sui valori).

Avvio:

```bash
php -S localhost:8000 -t public
# poi apri: http://localhost:8000/
```

Su Windows basta il doppio click su `avvia.bat`. Risultato (le età dipendono dalla data in cui lanci l'app; qui calcolate all'8 ottobre 2026):

| # | Nome | Età | Dipartimento |
|---:|---|---:|---|
| 1 | Giulia Rossi | 41 | Vendite [VEN] - sede di Milano |
| 2 | Marco Bianchi | 35 | Sistemi Informativi [IT] - sede di Roma |
| 3 | Francesca Verdi | 48 | Acquisti [ACQ] - sede di Torino |
| 4 | Luca Ferrari | 31 | Sistemi Informativi [IT] - sede di Roma |
| 5 | Chiara Esposito | 38 | Risorse Umane [HR] - sede di Bologna |
| 6 | Andrea Romano | 54 | Vendite [VEN] - sede di Milano |
| 7 | Sara Colombo | 26 | Sistemi Informativi [IT] - sede di Roma |
| 8 | Davide Ricci | 43 | Acquisti [ACQ] - sede di Torino |
| 9 | Elena Marino | 33 | Risorse Umane [HR] - sede di Bologna |
| 10 | Paolo Greco | 58 | Vendite [VEN] - sede di Milano |

Età media: **40,7** anni

---

## 11. I test

Il test usa lo stesso `bootstrap.php` della produzione e una data fissa per le età:

```php
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
```

Si lancia con `php tests/smoke.php` o con `test.bat`. Il test numero 6 è quello che mostra il vantaggio dell'interfaccia: si crea un container nuovo, gli si consegna con `instance()` un repository in memoria con **un solo** dipendente, e `EmployeeReport` funziona senza che una riga del report sia cambiata. Niente file, niente configurazione.

---

## 12. Perché Employee e Department non stanno nel container

Un container serve a risolvere **dipendenze**: "per costruire X mi serve un Y". `Employee` non ha dipendenze di questo tipo: ha valori (un nome, una data). Se provi a chiederlo al container:

```php
$container->get(Employee::class);
// ContainerException: Impossibile risolvere il parametro $id di App\Employee\Employee:
// configuralo con setParameter().
```

il container non sa quale id, quale nome, quale data usare, e giustamente si ferma. Una regola pratica:

- se un oggetto ha **collaboratori** (altri servizi), lo costruisce il container;
- se un oggetto ha **valori** (dati di dominio), lo costruisce chi conosce i valori, con `new`: di solito un repository o una factory.

Mescolare le due cose è la causa più comune di container "che non funzionano": si cerca di far costruire al container oggetti che non sono servizi.

---

## 13. Attenzione: il Service Locator

Un container potente porta con sé una tentazione: **iniettare il container stesso** nelle classi e chiamare `$container->get(...)` ovunque.

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

Le dipendenze tornano nascoste (non si leggono più dal costruttore), i test diventano scomodi e il container diventa una dipendenza globale mascherata. La regola pratica: **il container vive solo nella "composition root"**, cioè nel punto di avvio dell'applicazione (qui `bootstrap.php` e `public/index.php`). Tutto il resto riceve i collaboratori già pronti.

---

## 14. Limiti e possibili estensioni

Il container è volutamente piccolo. Ecco cosa non fa, e come si potrebbe estendere:

- **Cache della Reflection**: la Reflection ha un costo. In produzione puoi memorizzare per ogni classe l'elenco dei parametri risolti, in un array statico o in un file generato.
- **Verifica anticipata**: gli errori di configurazione emergono alla prima richiesta, non all'avvio. Un container "compilato" (come quello di Symfony) verifica l'intero grafo prima di servire la prima richiesta.
- **Method injection**: un metodo `call()` che risolve anche gli argomenti di un metodo, utile per le azioni dei controller.
- **Compatibilità PSR-11**: basta far implementare a `Container` la `Psr\Container\ContainerInterface` (metodi `get` e `has`) e alle eccezioni le rispettive interfacce, e il container diventa intercambiabile con qualunque libreria che lo supporti.
- **Alias automatico**: collegare da solo un'interfaccia alla sua implementazione quando ce n'è una sola. Qui è stato lasciato esplicito di proposito: un `bind()` visibile in `bootstrap.php` spiega l'architettura meglio di una convenzione nascosta.

È lo stesso meccanismo che framework come Laravel e Symfony offrono già pronto, con in più cache, compilazione e molte comodità.

---

## Conclusione

Con poche classi emergono i principi principali:

1. I **servizi** dichiarano le dipendenze nel costruttore, tipizzate su **interfacce** quando possibile; il container le assembla.
2. I **dati** (`Employee`, `Department`) restano fuori dal container: li crea chi conosce i valori.
3. Si registra **solo ciò che il container non può dedurre**: le interfacce (`bind`/`singleton`), i parametri scalari (`setParameter`) e le istanze da condividere (`singleton`).
4. Cambiare implementazione, per un test o per una nuova sorgente di dati, significa cambiare **una riga** nel bootstrap.
5. Il container vive nella **composition root**; da lì in poi il codice lavora con oggetti normali.

Costruirne uno, anche solo per gioco, cambia il modo in cui leggi i framework: quando ti segnalano un servizio non cablato o una dipendenza circolare, sai esattamente quale pezzo di logica sta lavorando.

*Per proseguire: aggiungi un `DatabaseEmployeeRepository`, cambia una riga in `bootstrap.php` e osserva che `EmployeeReport` non va toccato.*

---

### Appendice: checklist rapida

- [ ] Servizi e dati sono distinti: solo i servizi passano dal container.
- [ ] Ogni servizio dichiara le dipendenze nel costruttore, tipizzate, preferibilmente su un'interfaccia.
- [ ] Ogni interfaccia ha un `bind()` o `singleton()` verso l'implementazione scelta.
- [ ] I valori scalari (percorsi, chiavi, timeout) si configurano con `setParameter()` in `bootstrap.php`.
- [ ] Le istanze da condividere (repository, client, connessioni) sono `singleton`, e si chiedono sempre tramite l'id registrato.
- [ ] Il container non viene iniettato nei servizi applicativi.
- [ ] Nei test si sostituisce un binding (`instance()` o `bind()`) senza toccare il codice di produzione.
