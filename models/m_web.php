<?php
// модель
class Model_Web extends Model_Base 
{
    public function getExpertsFull(): array
    {	
        # получить список советников
        $experts   = $this->getListBase();
        # получить все параметры для id_base
        $computers = $this->getListActive();
        # получить список ПК для id_base
        $params    = $this->getAllParams();	

        foreach ($experts as $id_base => &$expert) 
		{
            $expert['params']    = $this->prepareParams($params[$id_base] ?? []);
            $expert['computers'] = $this->prepareComputers($computers[$id_base] ?? []);
        }
        unset($expert);

        return $experts;		
	}
	
	public function getListActive()
	{
	    $sql = "SELECT id_base, name, enable, priority FROM jf_active act 
                LEFT JOIN jf_computers comp ON act.id_pc = comp.id_pc 
                ORDER BY id_base ASC;";
	    
	    $list =   $this->db->SQL_Select_All($sql, array(), PDO::FETCH_GROUP);

    
	    return $list;
	}	
	
	public function getListBase()
	{
	    $sql = "SELECT id_base, expert, account, symbol FROM jf_base;";	    
	    $list =   $this->db->SQL_Select_All($sql, array(), PDO::FETCH_UNIQUE);	    
	    return $list;
	}
	
	public function getAllParams()
	{
	    $sql = "SELECT id_base, var, val FROM jf_exp_params;";
	    
	    $list =   $this->db->SQL_Select_All($sql, array(), PDO::FETCH_GROUP);
	    
	    return $list;
	}
	
	public function getListParams($id_base)
	{
	    $sql = "SELECT id_base, var, val FROM jf_exp_params
                WHERE id_base = :id_base;";
	    
	    $list =   $this->db->SQL_Select_All($sql, array('id_base' => $id_base));
	    
	    return $list;
	}
	
	
	private function prepareParams(array $rows): array
	{	    
		$result = [];
	    foreach ($rows as $row)
	    {
	        $result[$row['var']] = $row['val'];
	    }  	    
	    return $result;	
	}

    private function prepareComputers(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) 
		{
            $name = $row['name'];
            unset($row['name']);
            $result[$name] = $row;
        }
        return $result;
    }	
}
?>