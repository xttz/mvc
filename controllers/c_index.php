<?php

class Controller_Index extends Controller_Base 
{
    function index() 
    {
        # сделать кнопку отправляющую post запрос для основного контроллера       
        $index_template = new Template_New('default');
        $index_template->css('mql', 'css');
        $index_template->js('loader', 'js');
        echo $index_template->view(array('title'=>'Experts'));
    }
}
?>