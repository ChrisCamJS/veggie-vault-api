<?php
namespace App;

class Router {
    protected $routes = [];

    public function add($method, $route, $controller, $action) {
        $this->routes[] = [
            'method' => $method,
            'route' => $route,
            'controller' => $controller,
            'action' => $action
        ];
    }

    public function dispatch($uri, $method) {
        // Strip query strings and trailing slashes logic here...
        foreach ($this->routes as $route) {
            if ($route['method'] === $method && $route['route'] === $uri) {
                $controllerName = "App\\Controllers\\" . $route['controller'];
                $controller = new $controllerName();
                $action = $route['action'];
                return $controller->$action();
            }
        }
        http_response_code(404);
        echo json_encode(["message" => "Endpoint not found"]);
    }
}