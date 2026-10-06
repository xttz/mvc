<?php
class Map implements ArrayAccess
{
    private $arr;
    
    // Защищаем от создания через new Singleton
    public function __construct(&$arr) 
    {
        $this->arr = &$arr;
    }
    // Защищаем от создания через клонирование
    private function __clone() {}
    // Защищаем от создания через unserialize
    private function __wakeup() {}
    
    
    public function set($key, $var)
    {
        if (isset($this->arr[$key]) == true)
        {
            throw new Exception('Unable to set var `' . $key . '`. Already set.');
        }
        $this->arr[$key] = $var;
        return $this;
    }
    
    public function get($key)
    {
        if (isset($this->arr[$key]) == false)
        {
            return null;
        }
        return $this->arr[$key];
    }
    
    public function remove($key) 
    {        
        unset($this->arr[$key]);  
        return $this;
    }
    
    public function offsetExists($offset)
    {
        return isset($this->arr[$offset]);
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
        unset($this->arr[$offset]);
    }
    
    // Перегрузка обращения к свойствам объекта в PHP
    public function __get($key)
    {
        if (isset($this->arr[$key]) == false)
        {
            return null;
        }
        return $this->arr[$key];
    }
    
    // Перегрузка обращения к свойствам объекта в PHP
    public function __set($key, $var)
    {
        $this->arr[$key] = $var;
        return $this;
    }
    
    // Перегрузка удаления по ключу
    public function unset($key)
    {
        $this->remove($key);
        return $this;
    }
    
    // Перегрузка удаления по массиву ключей
    public function unset_arr(array $arr_key)
    {
        foreach ($arr_key as $key) 
        {
            $this->remove($key);
        }
        return $this;
    }
    
    // Перегрузка обращения к свойствам объекта в PHP
    public function add($key)
    {
        $this->remove($key);
        return $this;
    }
    
    // Перегрузка обращения к массиву для циклов
    public function get_arr()
    {
        return $this->arr;
    }
    
    /*
    // Перегрузка обращения к свойствам объекта в PHP
    public function __isset($key)
    {
        return true;
    }
    
    
    // Перегрузка обращения к свойствам объекта в PHP
    public function __unset($key)
    {
        return true;
    }
    */
   
    // Метод применяется для вызова несуществующих методов в контексте объекта
    function __call($name, $arguments)
    {
        echo "Магический метод, вызываемый при перегрузке метода ссылкой на объект\r\n";
    }
    
}

?>