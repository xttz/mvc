<?php
class Router_Request
{
    private $registry;
    private $path;
    
    function __construct(string $path) 
    {
        $this->registry = Registry::rel();
		$this->setPath($path);
    }
    
    private function setPath($path)
    {
        $path = rtrim($path, '/\\') . DIRECTORY_SEPARATOR;
        
        if (!is_dir($path))
        {
            throw new Exception ('Invalid controller path: `' . $path . '`');
        }
        
        $this->path = $path;
    }

	private function getController()
	{
		# путь без строки запроса: "/web/test/5"
		$path  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
		$parts = array_values(array_filter(explode('/', $path), 'strlen'));

		# прямое обращение к /index.php/... не должно ломать разбор
		if (($parts[0] ?? '') === 'index.php') {
			array_shift($parts);
		}

		$controller  = strtolower($parts[0] ?? 'index');
		$action = strtolower($parts[1] ?? 'index');

		# остальные части адреса, например id: /web/show/5
		$this->registry->args = array_map('rawurldecode', array_slice($parts, 2));

		# только буквы, цифры и _, первый символ буква
		if (!preg_match('/^[a-z][a-z0-9_]*$/', $controller) ||
			!preg_match('/^[a-z][a-z0-9_]*$/', $action)) {
			$this->notFound();
		}

		$file = $this->path . $this->registry->controller_prefix . $controller . '.php';
		if (!is_file($file)) {
			$this->notFound();
		}

		return array('action' => $action, 'contr' => $controller, 'file' => $file);
	}
    
	private function notFound()
	{
		http_response_code(404);
		exit('404 Not Found');
	}	
	
    function delegate()
    {
        // Анализируем путь
        $arr = $this->getController();      
        
        $controller = $arr['contr'];
        $action = $arr['action'];
        $file = $arr['file']; 
        
        // Файл доступен?
        if (is_readable($file) == false)
        {
            $this->notFound();
        }

        // Создаём экземпляр контроллера (ucfirst - переводит первый символ строки в верхний регистр)      
        $class = 'Controller_' . ucfirst($controller);        
        $controller = new $class();
        
        // Действие доступно?
        if (is_callable(array($controller, $action)) == false)
        {
            $this->notFound();
        }
        
        // Выполняем действие
        $controller->$action();
    }
}
?>
