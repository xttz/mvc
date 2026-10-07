<?php
/**
 * Обёртка над массивом параметров (GET, тело запроса).
 * Только для чтения: данные запроса не меняются по ходу работы,
 * а для выборки части параметров есть only() и except().
 */
class Map implements ArrayAccess, IteratorAggregate, Countable
{
    private array $items;

    public function __construct(array $items = [])
    {
        $this->items = $items;
    }

    # значение по ключу или $default, если ключа нет
    public function get(string $key, $default = null)
    {
        return array_key_exists($key, $this->items) ? $this->items[$key] : $default;
    }

    # целое число (для id и подобного)
    public function int(string $key, int $default = 0): int
    {
        $value = filter_var($this->get($key), FILTER_VALIDATE_INT);
        return $value === false ? $default : $value;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->items);
    }

    # весь массив
    public function all(): array
    {
        return $this->items;
    }

    # только перечисленные ключи
    public function only(array $keys): array
    {
        return array_intersect_key($this->items, array_flip($keys));
    }

    # все, кроме перечисленных ключей
    public function except(array $keys): array
    {
        return array_diff_key($this->items, array_flip($keys));
    }

    # $map->pc и isset($map->pc)
    public function __get($key)
    {
        return $this->get((string)$key);
    }

    public function __isset($key): bool
    {
        return isset($this->items[$key]);
    }

    # $map['pc']
    public function offsetExists($offset): bool
    {
        return isset($this->items[$offset]);
    }

    public function offsetGet($offset): mixed
    {
        return $this->get((string)$offset);
    }

    public function offsetSet($offset, $value): void
    {
        throw new LogicException('Map is read-only');
    }

    public function offsetUnset($offset): void
    {
        throw new LogicException('Map is read-only');
    }

    # foreach ($map as $key => $value)
    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->items);
    }

    # count($map)
    public function count(): int
    {
        return count($this->items);
    }
}
