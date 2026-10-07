<?php

class Sql
{
    private $link;
    // конструктор класса
    public function __construct()
    {      
        // загрузить параметры для подключенния к базе данных
        include_once "config_db.php";
        // задать параметры БД
        $dsn = "mysql:host={$config['db_host']};dbname={$config['db_name']};charset={$config['db_charset']}";
        $opt = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        
        try 
        {
            // создать обьект базы данных
            $this->link = new PDO($dsn, $config['db_user'], $config['db_pass'], $opt);
            //echo('Подключение к БД успешно');
        } 
        catch (PDOException $e) 
        {   // полключение не установлено 
            die('Подключение не удалось: ' . $e->getMessage());
        }
    }    
    
    // Запрос к базе данных
    public function SQL_Query(string $query, array $params = [])
    {
        $sth = $this->link->prepare($query);
        $result = $sth->execute($params);
        return $sth;
    }
    
    // Получение одной записи из набора
    public function SQL_Get_One($sth, $type = PDO::FETCH_ASSOC)
    {
        $obj = $sth->fetch($type);
        return $obj;
    }
    
    // Выборка одной записи
    public function SQL_Select_One(string $query, array $params = [], $type = PDO::FETCH_ASSOC)
    {
        $sth = $this->link->prepare($query);
        $result = $sth->execute($params);
        $obj = $sth->fetch($type);
        return $obj;
    }
    
    // Выборка всех записей таблицы
    public function SQL_Select_All(string $query, array $params = [], $type = PDO::FETCH_ASSOC)
    {
        $sth = $this->link->prepare($query);
        $result = $sth->execute($params);
        $arr = $sth->fetchAll($type);
        return $arr;
    }  
    
    // Выборка всех записей таблицы в виде обьекта
    public function SQL_Select_All_Obj(string $query, array $params = [], string $class='stdClass') 
    {
        $sth = $this->link->prepare($query);
        $result = $sth->execute($params);
        $obj = $sth->fetchAll(PDO::FETCH_CLASS, $class); 
        //$obj = $sth->fetchAll(PDO::FETCH_CLASS|PDO::FETCH_UNIQUE, 'stdClass'); 
        //$obj = $sth->fetchAll(PDO::FETCH_CLASS|PDO::FETCH_GROUP, 'stdClass'); 
        return $obj;
    }
  
    // функция получения данных
    public function SQL_Select_Map_Group(string $query, array $params = [])
    {
        $sth = $this->link->prepare($query);
        $result = $sth->execute($params);
        $arr = $sth->fetchAll(PDO::FETCH_GROUP);
        //$obj = $sth->fetchAll(PDO::FETCH_CLASS|PDO::FETCH_GROUP, 'stdClass');
        return $arr;
    }
    
    // функция обновления или добавления данных
    public function SQL_InsUpd_One(string $query, array $params = [])
    {
        $sth = $this->link->prepare($query);
        $result = $sth->execute($params);
        return $this->link->lastInsertId();
    }

};	 
