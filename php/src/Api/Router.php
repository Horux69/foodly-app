<?php

declare(strict_types=1);

namespace App\Api;

/**
 * Front controller a mano: sin framework, una tabla de rutas con {param}
 * convertido a grupo con nombre. Equivalente PHP puro de las @router.get/post
 * de FastAPI en app/api/v1/*.py.
 *
 * Un parametro se puede declarar tipado como {branch_id:uuid}. FastAPI
 * validaba eso solo con anotar `branch_id: uuid.UUID` y devolvia 422 ante
 * basura; aca se declara en la ruta para que sea visible en la tabla y no
 * haya que acordarse de validarlo en cada controlador. Sin esa validacion,
 * el UUID malformado llegaba hasta Postgres y volvia como un 500 con el
 * error de SQL adentro.
 */
final class Router
{
    /** @var array<int, array{method:string, regex:string, types:array<string,string>, handler:callable}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        $types = [];
        $regex = preg_replace_callback(
            '#\{([a-zA-Z_]+)(?::([a-z]+))?\}#',
            static function (array $m) use (&$types): string {
                if (isset($m[2]) && $m[2] !== '') {
                    $types[$m[1]] = $m[2];
                }
                return '(?P<' . $m[1] . '>[^/]+)';
            },
            $pattern,
        );

        $this->routes[] = [
            'method' => strtoupper($method),
            'regex' => '#^' . $regex . '$#',
            'types' => $types,
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
     * @param array<string, string> $params
     * @param array<string, string> $types
     */
    private static function validateParams(array $params, array $types): void
    {
        foreach ($types as $name => $type) {
            if ($type === 'uuid' && !Request::isUuid($params[$name] ?? '')) {
                throw new ApiException(422, "'{$name}' debe ser un UUID valido");
            }
        }
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
        // Tolerante a la barra final, como el redirect_slashes de FastAPI.
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }
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
            self::validateParams($params, $route['types']);

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
