<?php

class Controller_Index extends Controller_Base 
{
    function __construct(protected Model_Index $model = new Model_Index())
    {       
        parent::__construct();
    }
    
    function index()
    {  
        $index_template = new Template_New('default');  
        $index_template->css('base', 'css');
        $index_template->js('base', 'js');        

		# включить вывод ошибок
		if (Registry::rel()->debug) 
				$index_template->error_enable();

        // получить тестовые данные
        $data  = $this->model->Test();

        // отрисовка строк таблицы
        $i = 0;
		foreach ($data as $id_i => $text) 
		{
			$index_template->block('tbl_data_row', array(
				'id'    => $id_i,
				'name'  => $text,
				'class' => ($i++ % 2) ? 'even' : 'odd',
			), 
			'data_rows');
		}	        

        // отрисовка блока
        $index_template->block('tbl_data');      
        // отрисовка шаблона
        echo $index_template->view(array('title'=>'Test Page'));       
    }

    function test()
    {
        echo 'test';         
    }    
}