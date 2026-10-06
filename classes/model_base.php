<?php
abstract class Model_Base 
{
    protected $registry;
    protected $db;
    
    function __construct() 
    {
        $this->registry = Registry::rel();
        $this->db = Registry::rel()->db;
    }
}
?>