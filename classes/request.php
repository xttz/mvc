<?php
/**
 * Данные текущего HTTP-запроса. Разбирает всё один раз:
 * метод, путь, части пути, строку запроса и тело.
 * Роутер только интерпретирует готовые части пути.
 */
class Request
{
    public string $method;
    public string $path;       // "/admin/experts/show/5"
    public array  $segments;   // ['admin', 'experts', 'show', '5'], уже раскодированы
    public Map    $query;      // параметры из строки запроса
    public Map    $body;       // тело запроса: форма, JSON, PUT/PATCH
    public array  $args = [];  // части пути после контроллера и действия, заполняет роутер

    public function __construct()
    {
        $this->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $this->path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

        $segments = array_values(array_filter(explode('/', $this->path), 'strlen'));
        # прямое обращение к /index.php/... не должно ломать разбор
        if (($segments[0] ?? '') === 'index.php') {
            array_shift($segments);
        }
        $this->segments = array_map('rawurldecode', $segments);

        $this->query = new Map($_GET);
        $this->body  = new Map($this->parseBody());
    }

    public function isMethod(string $method): bool
    {
        return $this->method === strtoupper($method);
    }

    # значение из тела, а если его там нет, из строки запроса
    # (для фильтров и подобного; в изменяющих действиях читайте только body)
    public function input(string $key, $default = null)
    {
        return $this->body->get($key, $this->query->get($key, $default));
    }

    private function parseBody(): array
    {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

        # JSON в теле (fetch/axios), для любого метода
        if (stripos($contentType, 'application/json') !== false) 
		{
            $data = json_decode(file_get_contents('php://input'), true);
            return is_array($data) ? $data : [];
        }

        # обычный POST: PHP уже всё разобрал
        if ($this->method === 'POST') 
		{
            return $_POST;
        }

        # PUT / PATCH / DELETE с application/x-www-form-urlencoded
        if (in_array($this->method, ['PUT', 'PATCH', 'DELETE'], true)) 
		{
            parse_str(file_get_contents('php://input'), $data);
            return $data;
        }

        return [];
    }
}
