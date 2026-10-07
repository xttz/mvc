<?php
// класс для подключения шаблонов и передачи данных в отображение
Class Template_New 
{
	private $layouts;
	private $blocks = array();
	private $mobile_flag;
	private $dir;
	private $error_flag;
	private $url;
	
	function __construct($layouts, ?string $dir = null) 
	{
		$this->layouts = $layouts;
		$this->mobile_flag = Registry::rel()->mobile;	
		$this->dir = isset($dir) ? $dir . DIRECTORY_SEPARATOR : Registry::rel()->site_path;
		# веб-адрес каталога относительно корня сайта: '' для сайта, '/admin' для админки
		$root = rtrim(Registry::rel()->site_path, '/\\');
		$this->url = rtrim(str_replace('\\', '/', substr(rtrim($this->dir, '/\\'), strlen($root))), '/');		
		return $this;
	}
	
	function error_enable()
	{
	    $this->error_flag = true;
	}
	
	// отображение
	function view(array $data = []) 
	{
	    $path = $this->dir . 'views' . DIRECTORY_SEPARATOR. 'templates' . DIRECTORY_SEPARATOR . $this->layouts . '.php';
		
		if (file_exists($path) == false) 
		{
			trigger_error('Layout `' . $this->layouts . '` does not exist.', E_USER_NOTICE);
			return false;
		}		
		
		# загрузить шаблон
		return $this->render($path, $data);
	}
	
	// отображение
	function block($name, array $data = [], ?string $block_name = null) 
	{
	    # получить путь
	    $content = $this->dir . 'views'  . DIRECTORY_SEPARATOR. 'blocks' . DIRECTORY_SEPARATOR . $name . '.php';
	    # проверить валидность пути
	    if (file_exists($content) == false)
	    {
	        trigger_error ('Template `' . $name . '` does not exist.', E_USER_NOTICE);
	        return false;
	    }
	    # добавить блок в массив
		$key = $block_name ?? $name;
		$this->blocks[$key] = ($this->blocks[$key] ?? '') . $this->render($content, $data);
	}	
	
	// добавить стиль
	function css($name, ?string $block_name = null)
	{
	    # получить путь
	    $css = $this->dir . 'views' . DIRECTORY_SEPARATOR. 'css' . DIRECTORY_SEPARATOR . $name . '.css';
		
	    # проверить валидность пути
	    if (file_exists($css) == false)
	    {
	        trigger_error ('CSS `' . $name . '` does not exist.', E_USER_NOTICE);
	        return false;
	    }
		
		$url  = $this->url . '/views/css/' . rawurlencode($name) . '.css';
		$key = $block_name ?? $name;
	    # добавить блок в массив
	    $this->blocks[$key] = ($this->blocks[$key] ?? '') . '<link rel="stylesheet" href="' . $url . '">';
	}
	
	// добавить скрипт
	function js($name, ?string $block_name = null)
	{
	    # получить путь
	    $js = $this->dir . 'views' . DIRECTORY_SEPARATOR. 'scripts' . DIRECTORY_SEPARATOR . $name . '.js';
	    # проверить валидность пути
	    if (file_exists($js) == false)
	    {
	        trigger_error ('JS `' . $name . '` does not exist.', E_USER_NOTICE);
	        return false;
	    }
		$url  = $this->url . '/views/scripts/' . rawurlencode($name) . '.js';
		$key = $block_name ?? $name;
	    # добавить блок в массив
	    $this->blocks[$key] = ($this->blocks[$key] ?? '') . '<script src="' . $url . '"></script>';
	}
	
	
	function render(string $path, array $data = []) 
	{
	    # создание переменных для шаблонного класса
	    # извлечь элементы ассоциативного массива в переменные. Именами переменных будут служить ключи ассоциативного массива
	    //extract($data);
	    #загрузить шаблон
	    $code = GenHTML($path); 
	    
	    
	    # поиск полей в шаблоне (только метки вида [имя_блока], а не любой текст в скобках)
	    $pattern = '/\[([a-z0-9_]+)\]/i';
	    preg_match_all($pattern, $code, $arr_blocks); //, PREG_PATTERN_ORDER); 
		$replace = [];
	    # поиск совпадений полей сначала во входном массиве а потом в массиве блоков если во входном не было
	    # заодно сразу заменить ключи на field => [field] для упрощение замены
	    foreach (array_unique($arr_blocks[1]) as $key)
	    {
			if ($key === 'error') 
			{
				continue; // заполняется после цикла
			}		
			
	        if( array_key_exists($key, $data))
	        {				
				$replace["[$key]"] = htmlspecialchars((string)$data[$key], ENT_QUOTES, 'UTF-8');
	            //unset($data[$key]);
	        }
	        else if( array_key_exists($key, $this->blocks))
	        {
	            $replace["[$key]"] = $this->blocks[$key];
	        }
	        else 
	        {  # задать пустые значения не найденым полям
	            $replace["[$key]"] = '';
	            # контроль ошибок включен
	            if ($this->error_flag)
	            {
	               $this->blocks['error'] = ($this->blocks['error'] ?? '') . htmlspecialchars("[$key]") . ' block not found<br>';
	            }
	        }
	    }
		
		if (in_array('error', $arr_blocks[1], true)) 
		{
			$replace['[error]'] = isset($this->blocks['error'])	? '<div class="err">' . $this->blocks['error'] . '</div>' : '';
		}		
		
	    return strtr($code, $replace);
	}
}
