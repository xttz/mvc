<?php
abstract class Controller_Base 
{
    protected $registry;
    
    function __construct()
    {
        $this->registry = Registry::rel();
    }
    
    abstract function index();
}
