<?php
class Controller_Params extends Controller_Base
{
    protected $model;
    
    function __construct()
    {       
        parent::__construct();
        $this->model = new Model_Params();         
        $this->registry['id_pc'] = $this->model->getIdPC();
        $this->registry['id_base'] = $this->model->getIdBase();
    }
    
    function index() 
    {
        echo 'Default index of the `members` controllers';
    }

    function check() 
    { 
        $answer = 1;
        $state_act = 1;
        
        if(empty($this->registry['id_pc']))
        {
            # создать новую запись
            $this->registry->id_pc = $this->model->addPC();
            # вставить запись в таблицу активности
            $state_act = 0;
        }        
       
        if(empty($this->registry['id_base']))
        {            
            # создать новую запись
            $this->registry->id_base = $this->model->addBase();
            # вставить запись в таблицу активности
            $state_act = 0;
            # отправить 0
            $answer = 0;
        }
        
        # проверить был ли такой советник ранее
        if ($state_act == 1)
        {
            # добавить время последней активности
            $this->model->updateActiveTime();
        }
        else 
        {
            # вставить запись в таблицу активности
            $this->model->addActive();
        }
        # отправить статус
        echo $answer;        
    }
    
    function upd()
    {
        $upd_cnt = $this->model->checkUpdateParams();
        # добавить время последней активности
        $this->model->updateActiveTime();
        echo $upd_cnt;
    }
    
    function get()
    {    
        #echo 'get request <br/>';
        
        if ($this->registry->request->upd == 'all')
        {
            #echo 'get_all <br/>';
            $params = $this->model->getAllParams(); 
        }
        else 
        {
            #echo 'get <br/>';
            $params = $this->model->getUpdateParams();    
        }

        # удалить полученые параметры
        $this->model->deleteUpdateParams();
        
        # преобразовать массив в строку параметров разделенную \n
        foreach ($params as $key => $value) 
        {
            $res .= "$key=$value\n";
        }
        
        echo  $res;        
        #vardump($params);
    }

    function exit()
    {
        #echo 'exit request <br/>';
        # получить текущий активный соыветник
        $current_enable = $this->model->getEnableIdPC(); 
        # проверить что текущий советник был основным
        if ($current_enable['id_pc'] == $this->registry->id_pc)
        {
            # вызвать функцию замены с флагом $on_exit = true
            $this->model->checkActive(true);            
        }
        # удалить параметры для закрываемого советника
        $this->model->deleteUpdateParams(); 
    }    
    
    function set()
    {
        #echo 'set request <br/>';
        # убрать системные параметры из массива запроса
        $this->registry->request->unset_arr(array('pc','acc','sym','exp'));        
        # создать / обновить список параметров
        $this->model->addParams();        
        # удалить параметры для всех советников из jf_update
        $this->model->deleteUpdateParams(true);
        # добавить для активных ПК параметры к обновлению в jf_update   
        $this->model->addUpdateParams();  
        # обновить активность для текущего советника
        $this->model->updateActiveTime();
    }
}
?>