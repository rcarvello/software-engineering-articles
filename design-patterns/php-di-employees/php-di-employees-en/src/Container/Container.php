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
