<?php

declare(strict_types=1);

namespace App\Api;

/**
 * Front controller a mano: sin framework, una tabla de rutas con {param}
 * convertido a grupo con nombre. Equivalente PHP puro de las @router.get/post
 * de FastAPI en app/api/v1/*.py.
 */
final class Router
{
    /** @var array<int, array{method:string, pattern:string, regex:string, handler:callable}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        $regex = preg_replace('#\{([a-zA-Z_]+)\}#', '(?P<$1>[^/]+)', $pattern);
        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => $pattern,
            'regex' => '#^' . $regex . '$#',
            'handler' => $handler,
        ];
    }

    public function get(string $pattern, callable $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    public function patch(string $pattern, callable $handler): void
    {
        $this->add('PATCH', $pattern, $handler);
    }

    public function put(string $pattern, callable $handler): void
    {
        $this->add('PUT', $pattern, $handler);
    }

    /**
     * Despacha la peticion actual. Devuelve [statusCode, body] — body ya es
     * un arreglo listo para json_encode, nunca una excepcion sin capturar:
     * eso lo resuelve el front controller antes de llamar aqui.
     *
     * @return array{0:int, 1:mixed}
     */
    public function dispatch(string $method, string $path): array
    {
        $method = strtoupper($method);
        $allowedForPath = [];

        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $matches)) {
                continue;
            }
            $allowedForPath[] = $route['method'];
            if ($route['method'] !== $method) {
                continue;
            }

            $params = array_filter($matches, fn ($k) => is_string($k), ARRAY_FILTER_USE_KEY);
            $result = ($route['handler'])($params);
            if ($result instanceof JsonResponse) {
                return [$result->status, $result->data];
            }
            return [200, $result];
        }

        if ($allowedForPath !== []) {
            throw new ApiException(405, 'Metodo no permitido para esta ruta');
        }
        throw new ApiException(404, 'Ruta no encontrada');
    }
}
