<?php

/**
 * Простой роутер: регистрация маршрутов вида
 *   Route::get('/admin/lessons/{id}', 'AdminController@lesson')
 * и диспетчеризация по HTTP-методу + пути.
 */
class Router
{
    private array $routes = [];
    private string $basePath = '/';

    public function get(string $pattern, string $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, string $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    public function options(string $pattern, string $handler): void
    {
        $this->add('OPTIONS', $pattern, $handler);
    }

    private function add(string $method, string $pattern, string $handler): void
    {
        $this->routes[] = [
            'method'  => $method,
            'pattern' => $pattern,
            'handler' => $handler,
        ];
    }

    /**
     * Разбирает реальный путь из REQUEST_URI, отбрасывая query-строку.
     */
    public function currentPath(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        // удаляем базовый префикс, если есть
        if ($this->basePath !== '/' && str_starts_with($path, $this->basePath)) {
            $path = substr($path, strlen($this->basePath));
        }
        // нормализуем
        $path = '/' . trim($path, '/');
        return $path === '/' || $path === '' ? '/' : $path;
    }

    public function dispatch(string $method, string $path): mixed
    {
        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }
            $params = $this->match($route['pattern'], $path);
            if ($params === null) {
                continue;
            }
            // Вызываем контроллер
            [$controllerClass, $action] = explode('@', $route['handler']);
            $controller = new $controllerClass();
            return $controller->{$action}($params);
        }

        http_response_code(404);
        return 'Страница не найдена (404).';
    }

    /**
     * Матчит паттерн (/foo/{id}) с прокачанным пути.
     * Возвращает массив параметров (позиционный) или null.
     */
    private function match(string $pattern, string $path): ?array
    {
        $pat = preg_replace('#\{[\w]+\}#', '([^/]+)', trim($pattern, '/'));
        $regex = '#^/?' . $pat . '/?$#';

        if (!preg_match($regex, trim($path, '/'), $m)) {
            return null;
        }
        array_shift($m); // убрать полное совпадение
        return $m;
    }
}