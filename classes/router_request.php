<?php
/**
 * Выбирает контроллер и действие по частям пути из Request.
 * Адрес сам не разбирает: всё нужное уже лежит в $request->segments.
 *
 *   /web/test/5          → Controller_Web::test(),            args = ['5']
 *   /admin/experts/edit/3 → Admin_Controller_Experts::edit(),  args = ['3']
 */
class Router_Request
{
    private Registry $registry;
    private array $paths = [];   // область => каталог контроллеров

    private const AREAS = [
        'site'  => 'Controller_',
        'admin' => 'Admin_Controller_',
    ];

    public function __construct(string $path, ?string $adminPath = null)
    {
        $this->registry = Registry::rel();
        $this->setPath('site', $path);

        if ($adminPath !== null) 
        {
            $this->setPath('admin', $adminPath);
        }
    }

    private function setPath(string $area, string $path): void
    {
        $path = rtrim($path, '/\\') . DIRECTORY_SEPARATOR;

        if (!is_dir($path)) 
        {
            throw new Exception('Invalid controller path: `' . $path . '`');
        }

        $this->paths[$area] = $path;
    }

    private function resolve(): array
    {
        $request = $this->registry->request;
        $parts   = $request->segments;
        $area    = 'site';

        if (isset($this->paths['admin']) && ($parts[0] ?? '') === 'admin') 
        {
            array_shift($parts);
            $area = 'admin';
        }

        $controller = strtolower($parts[0] ?? 'index');
        $action     = strtolower($parts[1] ?? 'index');
        $request->args = array_slice($parts, 2);

        # только буквы, цифры и _, первый символ буква
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $controller) || !preg_match('/^[a-z][a-z0-9_]*$/', $action)) 
        {
            $this->notFound();
        }

        $file = $this->paths[$area] . $this->registry->controller_prefix . $controller . '.php';
        if (!is_file($file)) 
        {
            $this->notFound();
        }

        return [
            'area'       => $area,
            'controller' => $controller,
            'action'     => $action,
            'class'      => self::AREAS[$area] . ucfirst($controller),
        ];
    }

    public function delegate(): void
    {
        $route = $this->resolve();

        if ($route['area'] === 'admin') 
        {
            $this->requireAdmin($route['controller']);
        }

        if (!class_exists($route['class'])) 
        {
            $this->notFound();
        }

        $controller = new $route['class']();
        $action     = $route['action'];

        if (!is_callable([$controller, $action])) 
        {
            $this->notFound();
        }

        $controller->$action();
    }

    # вход обязателен для всех контроллеров админки, кроме login
    private function requireAdmin(string $controller): void
    {
        if (session_status() === PHP_SESSION_NONE) 
        {
            session_start();
        }

        if ($controller !== 'login' && empty($_SESSION['admin_id'])) 
        {
            header('Location: /admin/login/');
            exit;
        }
    }

    private function notFound(): void
    {
        http_response_code(404);
        exit('404 Not Found');
    }
}
