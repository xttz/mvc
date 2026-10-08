<?php

declare(strict_types=1);

/**
 * Реестр (Singleton) для хранения общих объектов и значений приложения.
 *
 * @implements ArrayAccess<string|int, mixed>
 */
final class Registry implements ArrayAccess
{
    private static ?self $instance = null;

    /** @var array<string|int, mixed> */
    private array $vars = [];

    // Защищаем от создания через new
    private function __construct() {}

    // Защищаем от создания через клонирование
    private function __clone() {}

    // Защищаем от создания через unserialize
    public function __wakeup(): void
    {
        throw new LogicException('Cannot unserialize singleton');
    }

    // Возвращает единственный экземпляр класса
    public static function rel(): self
    {
        return self::$instance ??= new self();
    }

    public function set(string|int $key, mixed $var): bool
    {
        if (isset($this->vars[$key])) {
            throw new LogicException("Unable to set var `{$key}`. Already set.");
        }
        $this->vars[$key] = $var;
        return true;
    }

    public function get(string|int $key): mixed
    {
        return $this->vars[$key] ?? null;
    }

    public function has(string|int $key): bool
    {
        return isset($this->vars[$key]);
    }

    public function remove(string|int $key): void
    {
        unset($this->vars[$key]);
    }

    // ---- ArrayAccess: $registry['key'] ----

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->vars[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->vars[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            throw new InvalidArgumentException('Registry key cannot be empty: use $registry[\'key\'] = ...');
        }
        $this->set($offset, $value);
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->vars[$offset]);
    }

    // ---- Магические свойства: $registry->key ----

    public function __get(string $key): mixed
    {
        return $this->vars[$key] ?? null;
    }

    public function __set(string $key, mixed $var): void
    {
        $this->vars[$key] = $var;
    }

    public function __isset(string $key): bool
    {
        return isset($this->vars[$key]);
    }

    public function __unset(string $key): void
    {
        unset($this->vars[$key]);
    }

    // Вызов несуществующего метода
    public function __call(string $name, array $arguments): never
    {
        throw new BadMethodCallException("Метод Registry::{$name}() не найден");
    }
}