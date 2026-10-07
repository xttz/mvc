<?php
// модель
class Model_Index extends Model_Base 
{
    public function Test(): array
    {			
		$arr = [
			10  => 'apple',
			5  => 'banana',
			8  => 'orange'
		];		
        return $arr;		
	}
}
