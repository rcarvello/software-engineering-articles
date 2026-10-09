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
