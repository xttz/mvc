<?php

class Admin_Controller_Login extends Controller_Base 
{
    function __construct(protected Admin_Model_Index $model = new Admin_Model_Index())
    {       
        parent::__construct();
    }

    function index()
    {
        $index_template = new Template_New('default', __DIR__ . '/admin');  
        $index_template->css('base', 'css');
        $index_template->js('base', 'js');        

		# включить вывод ошибок
		if (Registry::rel()->debug) 
				$index_template->error_enable();
    
        // отрисовка шаблона
        echo $index_template->view(array('title'=> 'Логин'));            
    }
    
}