<?php
// модель
class Model_Params extends Model_Base 
{
	public function getIdPC()
	{
	    // проверить что такой PC есть
	    $sql = 'SELECT * FROM `jf_computers` WHERE name = :namepc;';
	    $pc = $this->db->SQL_Select_One($sql, array('namepc' => $this->registry->request->pc));
	    return $pc['id_pc'];
	}
	
	public function getIdBase()
	{
        // проверить что такой PC есть
        $sql = 'SELECT * FROM `jf_base` WHERE expert = :exp AND symbol = :sym AND account = :acc;';
        $base = $this->db->SQL_Select_One($sql, 
            array(
                'exp' => $this->registry->request->exp, 
                'sym' => $this->registry->request->sym, 
                'acc' => $this->registry->request->acc)
            );
        return $base['id_base'];
	}
	
	public function getParams()
	{
	    $sql = "SELECT * FROM `jf_exp_params` WHERE id_base = :id_base;";
	    $params = $this->db->SQL_Select_All_Obj($sql,
	        array('id_pc' => $this->registry->id_pc, 'id_base' => $this->registry->id_base));
	    
	    return $params;
	}
	
	public function addPC()
	{	
	    $sql = "INSERT INTO jf_computers (name) VALUES (:namepc);";	    
	    $id_pc = $this->db->SQL_InsUpd_One($sql, array('namepc' => $this->registry->request->pc));
	    return $id_pc;
	}
	
	public function addBase()
	{
	    $sql = "INSERT INTO jf_base (expert, symbol, account) VALUES (:exp, :sym, :acc);";
	    $id_base = $this->db->SQL_InsUpd_One($sql, 
	        array(
    	        'exp' => $this->registry->request->exp, 
    	        'sym' => $this->registry->request->sym, 
    	        'acc' => $this->registry->request->acc)
	        );
	    return $id_base;
	}

	public function checkUpdateParams()
	{ 	    
	    $sql = "SELECT COUNT(*)>0 FROM jf_update WHERE
                    id_pc = :id_pc
                AND 
                    id_param IN (SELECT id_param FROM jf_exp_params WHERE id_base = :id_base );";
    
	    $count =   $this->db->SQL_Select_One($sql,
	        array('id_pc' => $this->registry->id_pc, 'id_base' => $this->registry->id_base), 
	        PDO::FETCH_COLUMN);
	    
	    return $count;
	}
	
	public function getUpdateParams()
	{
	    $sql = "SELECT var,val FROM jf_exp_params WHERE 
                    id_param IN (SELECT id_param FROM jf_update WHERE id_pc = :id_pc )
                AND
                    id_base = :id_base;";

	    $params =   $this->db->SQL_Select_All($sql,
	        array('id_pc' => $this->registry->id_pc, 'id_base' => $this->registry->id_base));
	    
	    # привести результаты запроса к виду ключ-значение
	    $params = $this->prepareParam($params);	    
	    
	    return $params;
	}
	
	public function getAllParams()
	{
	    $sql = "SELECT var, val FROM jf_exp_params WHERE id_base = :id_base;";
	    $params = $this->db->SQL_Select_All($sql, array('id_base' => $this->registry->id_base));
	    
	    # привести результаты запроса к виду ключ-значение
	    $params = $this->prepareParam($params);

	    
	    return $params;	    
	}
	
	private function prepareParam($params)
	{	    
	    foreach ($params as $par)
	    {
	        $arr[$par['var']] = $par['val'];
	    }  
	    
	    # изменить параметр enable на значение из jf_active
	    $this->getEnableParam($arr);
	    
	    return $arr;	
	}
	
	private function getEnableParam(&$params)
	{	    
	    # если в массиве есть параметр enable то получить его значение из jf_active
	    if (isset($params['enable']))
	    {	         
	        $sql = "SELECT enable FROM jf_active WHERE id_pc = :id_pc AND id_base = :id_base;";
	        # найти последнее время и id_pc для активного элемента этой id_base
	        $enable = $this->db->SQL_Select_One($sql, 
	            array('id_pc' => $this->registry->id_pc, 'id_base' => $this->registry->id_base), 
	            PDO::FETCH_COLUMN);
	        # заменить значение
	        $params['enable'] = $enable;
	    }
	}	
	
	public function deleteUpdateParams($flag_all = false)
	{
	    $id_pc = $this->registry->id_pc;
	    $id_base = $this->registry->id_base;
	    $sql_pc  = $flag_all ? "" : "id_pc = $id_pc AND";
	    
	    $sql = "DELETE FROM jf_update WHERE $sql_pc
                    id_param IN (SELECT id_param FROM jf_exp_params WHERE id_base = $id_base);";	    

	    $del_cnt = $this->db->SQL_Query($sql);	    
	}	
	
	public function addUpdateParams()
	{
	    $id_pc = $this->registry->id_pc;
	    $id_base = $this->registry->id_base;
	    # запрос на слияние всех параметров по парам id_base с id_pc встречающихся в jf_active кроме текущего
	    $sql = "INSERT INTO jf_update (id_pc, id_param) 
        SELECT id_pc, id_param 
        FROM jf_exp_params, jf_computers 
        WHERE jf_exp_params.id_base = $id_base AND id_pc IN
            (SELECT id_pc FROM jf_active WHERE id_base = $id_base AND id_pc != $id_pc);";	
	    # выполнить запрос
	    $this->db->SQL_Query($sql);
	}
	
	public function addParams()
	{
	    # создать начало запроса
	    $sql = "INSERT INTO jf_exp_params (id_base, var, val) VALUES ";	    
        # добавить параметры из запроса
	    foreach ($this->registry->request->get_arr() as $var => $val)
        {
            $result[] = "(3, '$var', '$val')";
        }  
        # добавить окончание запроса
        $sql .= implode(', ', $result) . " ON DUPLICATE KEY UPDATE val = VALUES(val);";	
	    # выполнить запрос
        $this->db->SQL_Query($sql);
	}
	
	# добавление новой строки в jf_active
	public function addActive()
	{
	    $sql = "SELECT NOT(COUNT(*)) FROM jf_active WHERE id_base = :id_base AND enable = 1;";
	    $no_enable = $this->db->SQL_Select_One($sql, array('id_base' => $this->registry->id_base), PDO::FETCH_COLUMN);
	    
	    $sql = "SELECT MAX(priority) FROM jf_active WHERE id_base = :id_base;";
	    $last_priority = $this->db->SQL_Select_One($sql, array('id_base' => $this->registry->id_base), PDO::FETCH_COLUMN);
	    
	    $sql = "INSERT INTO jf_active (id_pc, id_base, enable, priority, last_active_time)
                VALUES (:id_pc, :id_base, :enable, :priority, now())
	            ON DUPLICATE KEY UPDATE last_active_time = VALUES(last_active_time);";
	    
	    $this->db->SQL_InsUpd_One($sql,
	        array(
	            'id_pc' => $this->registry->id_pc,
	            'id_base' => $this->registry->id_base,
	            'enable' => $no_enable,
	            'priority' => $last_priority+1)
	        );
	}
	
	public function updateActiveTime()
	{
	    # обновить время текущему элементу
	    $sql = "UPDATE jf_active SET last_active_time = now() WHERE id_pc = :id_pc AND id_base = :id_base;";
	    
	    $id_base = $this->db->SQL_InsUpd_One($sql,
	        array('id_pc' => $this->registry->id_pc, 'id_base' => $this->registry->id_base));
	    
	    # проверить таймаут активного элемента
	    $this->checkActive();
	}
	
	public function checkActive(bool $on_exit = false)
	{
	    # найти последнее время приоритет и id_pc для активного элемента этой id_base 
	    $act_el = $this->getEnableIdPC();
	    
	    # проверить что такое существует
	    if($act_el == false || $act_el['timeout'] > 180 || $on_exit)
	    { # элемент с  enable = 1 отсутствует или у него истек таймаут        
	            $this->swapActive($act_el['id_pc']);
	    }
	}
	
	private function swapActive($old_pc_id)
	{
	    #echo "swapActive <br/>";
	    # найти элемент с таким же base_id, наивысшим приоритетом и без превышения таймаута
	    $new_pc_id  = $this->getNextEnableIdPC($old_pc_id)['id_pc'];

	    # получить id_param для enable 
	    $sql = "SELECT id_param FROM jf_exp_params WHERE id_base = :id_base AND var = 'enable';";
	    $id_enable = $this->db->SQL_Select_One($sql, array('id_base' => $this->registry->id_base), PDO::FETCH_COLUMN);
	    
	    if ($new_pc_id)
	    {   #echo "Новый enable: $new_pc_id <br/>";
	        $this->AddEnable($new_pc_id, $id_enable, 1);
	    }

	    if ($old_pc_id)
	    {   #echo "Старый enable: $old_pc_id <br/>";
	        $this->AddEnable($old_pc_id, $id_enable, 0);
	    } 
	}
	
	public function getEnableIdPC()
	{	    
	    # получить id_pc с enable = 1
	    $sql = "SELECT id_pc, TIME_TO_SEC(TIMEDIFF(now(),last_active_time)) timeout, priority FROM jf_active WHERE id_base = :id_base AND enable = 1;";
	    # найти последнее время и id_pc для активного элемента этой id_base
	    $act_el = $this->db->SQL_Select_One($sql, array('id_base' => $this->registry->id_base));
	    return $act_el;
	}
	
	public function getNextEnableIdPC($old_pc_id)
	{
	    $sql_not_old = $old_pc_id ? " id_pc != $old_pc_id AND " : "";
	    # получить id_pc с enable = 1
	    $sql = "SELECT id_pc, priority FROM jf_active WHERE $sql_not_old id_base = :id_base AND  TIME_TO_SEC(TIMEDIFF(now(),last_active_time)) < 180 ORDER BY priority ASC LIMIT 1;";
	    # найти последнее время и id_pc для активного элемента этой id_base
	    $act_el = $this->db->SQL_Select_One($sql, array('id_base' => $this->registry->id_base));
	    return $act_el;
	}
	
	public function AddEnable($id_pc, $id_param, $enable)
	{
	    # получить id_base
	    $id_base = $this->registry->id_base;
	    # обновить jf_active
	    $sql = "UPDATE jf_active SET enable = $enable WHERE id_pc = $id_pc AND id_base = $id_base;";
	    $this->db->SQL_InsUpd_One($sql);
	    
	    # проверить существует ли уже запись в jf_update
	    $sql = "SELECT COUNT(*)>0 FROM jf_update WHERE id_pc = $id_pc AND id_param = $id_param;";
	    $exists = $this->db->SQL_Select_One($sql, array(), PDO::FETCH_COLUMN);
	    
	    #echo "exists: $exists <br/>";
	    
	    if ($exists == 0)
	    {  # не существует	  
	        #echo "INSERT in jf_update <br/>";
	        $sql = "INSERT INTO jf_update (id_pc, id_param) VALUES ($id_pc, $id_param)";
	    }
	    
	    # вставить / обновить
	    $this->db->SQL_InsUpd_One($sql);

	}
}
?>