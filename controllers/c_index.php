<?php

class Controller_Index extends Controller_Base 
{
    function __construct(protected Model_Index $model = new Model_Index())
    {       
        parent::__construct();
    }
    
    function index()
    {
        echo 'test';         
    }
