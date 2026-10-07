<?php
final class Registry implements ArrayAccess
{
    private static $instance;    
    private $vars = array();
    
    // Защищаем от создания через new Singleton
    private function __construct() {}
    // Защищаем от создания через клонирование
    private function __clone() {}
    // Защищаем от создания через unserialize
	public function __wakeup()
	{
		throw new Exception('Cannot unserialize singleton');
	}
    
    // метод для запрета дублирования объекта (Singleton), Возвращает единственный экземпляр класса
    public static function rel()
    {
        if (self::$instance === null) 
        {
            self::$instance = new self;
        }
        return self::$instance;
    }
    
    public function set($key, $var)
    {
        if (isset($this->vars[$key]) == true)
        {
            throw new Exception('Unable to set var `' . $key . '`. Already set.');
        }
        $this->vars[$key] = $var;
        return true;
    }
    
    public function get($key)
    {
        if (isset($this->vars[$key]) == false)
        {
            return null;
        }
        return $this->vars[$key];
    }
    
    public function remove($key) 
    {        
        unset($this->vars[$key]);        
    }
    
    public function offsetExists($offset)
    {
        return isset($this->vars[$offset]);
    }
    
    public function offsetGet($offset)
    {
        return $this->get($offset);
    }
    
    public function offsetSet($offset, $value)
    {
        $this->set($offset, $value);
    }
    
    public function offsetUnset($offset)
    {
        unset($this->vars[$offset]);
    }
    
    // Перегрузка обращения к свойствам объекта в PHP
    public function __get($key)
    {
        if (isset($this->vars[$key]) == false)
        {
            return null;
        }
        return $this->vars[$key];
    }
    
    // Перегрузка обращения к свойствам объекта в PHP
    public function __set($key, $var)
    {
        $this->vars[$key] = $var;
    }
 
    
    // Перегрузка обращения к свойствам объекта в PHP
    public function __isset($key): bool
    {       
		return isset($this->vars[$key]);
    }
    
    
    // Перегрузка обращения к свойствам объекта в PHP
	public function __unset($key): void
	{
		unset($this->vars[$key]);
	}
    
    
    // Метод применяется для вызова несуществующих методов в контексте объекта
    function __call($name, $arguments)
    {
         throw new BadMethodCallException("Метод Registry::$name() не найден\r\n");
    }
    
}