<?php
// модель
class Model_Index extends Model_Base 
{
    public function Test(): array
    {			
		$arr = [
			'apple' => 10,
			'banana' => 5,
			'orange' => 8
		];		
        return $arr;		
	}
}
