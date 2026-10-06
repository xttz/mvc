<?php
Class Controller_Web Extends Controller_Base 
{
   
    function __construct()
    {       
        parent::__construct();
        $this->model = new Model_Web();
    }
    
    function index()
    {
		$experts  = $this->model->getExpertsFull();		
        
        # отрисовка шаблона
        $test_template = new Template_New('mql_experts_tpl'); 
		# включить вывод ошибок
		if (Registry::rel()->debug) 
				$test_template->error_enable();
				
        $test_template->css('mql', 'css');
        //$test_template->js('loader', 'js');    

        $i = 0;
		foreach ($experts  as $id_base => $exp) 
		{
			$test_template->block('mql_tbl_experts_row', array(
				'id'    => $id_base,
				'name'  => $exp['expert'],
				'class' => ($i++ % 2) ? 'even' : 'odd',
			), 
			'exp_rows');
		}		
        $test_template->block('mql_tbl_experts');
		
        echo $test_template->view(array('title'=>'Experts'));
        
        
        echo '<pre>';
        print_r($experts );
        echo '</pre>';
        
    }
    
    function test()
    {
        echo 'test';        
    }
}
?>