<?php
class Request
{
    public $method;
    public $path;
    public $query;  // параметры из строки запроса
    public $body;   // тело запроса: форма, JSON, PUT/PATCH
    public $args = [];  // части адреса после контроллера и действия, заполняет роутер

    function __construct()
    {
        $this->method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $this->path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
        $this->query  = new Map($_GET);
        $this->body   = new Map($this->parseBody());
    }

    private function parseBody(): array
    {
		$contentType = $_SERVER['CONTENT_TYPE'] ?? '';

		// JSON в теле (fetch/axios часто шлют именно так), для любого метода
		if (stripos($contentType, 'application/json') !== false) 
		{
			$data = json_decode(file_get_contents('php://input'), true);
			return is_array($data) ? $data : [];
		}

		// обычный POST: PHP уже всё разобрал
		if ($this->method === 'POST')  
		{
			return $_POST;
		}

		// PUT / PATCH / DELETE с application/x-www-form-urlencoded
		if (in_array($this->method, ['PUT', 'PATCH', 'DELETE'], true)) 
		{
			parse_str(file_get_contents('php://input'), $data);
			return $data;
		}

		return [];
    }
}
?>