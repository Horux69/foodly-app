<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Los tiempos de una cuenta y cuando cada uno sale a la cocina.
 *
 * En una mesa se pide todo junto pero no se cocina todo junto. Sin tiempos,
 * en una mesa de ocho los postres salen con las entradas —o el mesero toma
 * tres pedidos distintos para la misma mesa y despues hay que cobrarlos
 * juntos, que es peor—.
 *
 * Tres decisiones viven aqui:
 *
 * - **El tiempo es de la linea, no del pedido.** La misma cuenta lleva
 *   lineas de varios tiempos; el pedido es uno solo y se cobra una vez.
 * - **El primero se marcha solo al tomar el pedido.** Si esperara a que
 *   alguien lo marchara, un mesero que no conociera la funcion dejaria la
 *   comida sin pedir a la cocina y el error se descubriria cuando el cliente
 *   preguntara. Los demas esperan: para eso existen.
 * - **El nombre es configuracion, el numero es el dato.** "Entradas" o
 *   "Primer tiempo" lo decide cada restaurante (`tenants.settings.courses`);
 *   la linea guarda el numero, asi que renombrar un tiempo no reescribe lo
 *   ya vendido.
 */
final class Courses
{
    private function __construct()
    {
    }

    /**
     * Cuantos tiempos admite esta configuracion.
     *
     * Sin tiempos configurados hay uno solo —el pedido entero— y la pantalla
     * ni lo menciona: es el caso de casi todos los restaurantes.
     *
     * @param string[] $nombres
     */
    public static function cuantos(array $nombres): int
    {
        return max(1, count($nombres));
    }

    /** @param string[] $nombres @throws CourseError */
    public static function ensureValid(int $curso, array $nombres): void
    {
        $tope = self::cuantos($nombres);
        if ($curso < 1 || $curso > $tope) {
            throw new CourseError(
                $tope === 1
                    ? 'Este restaurante no maneja tiempos: todo el pedido va junto'
                    : "El tiempo {$curso} no existe: este restaurante tiene {$tope}"
            );
        }
    }

    /**
     * Como se llama un tiempo.
     *
     * Con un nombre configurado, ese; si no —una linea vieja de un tiempo
     * que el restaurante quito— se dice el numero, que sigue siendo cierto.
     *
     * @param string[] $nombres
     */
    public static function label(int $curso, array $nombres): string
    {
        return $nombres[$curso - 1] ?? "Tiempo {$curso}";
    }

    /**
     * Cual se marcha al tomar el pedido: el primero que tenga lineas.
     *
     * El primero y no el numero 1: si el mesero solo tomo fuertes y postres,
     * los fuertes tienen que salir ya.
     *
     * @param int[] $cursos los tiempos de las lineas del pedido
     */
    public static function primero(array $cursos): int
    {
        return $cursos === [] ? 1 : min($cursos);
    }

    /**
     * Marchar es mandar a la cocina, y eso solo tiene sentido mientras la
     * cuenta siga viva. La ventana es la misma que la del mesero a cargo y
     * mas ancha que la de editar productos: con las entradas ya servidas
     * —el pedido en categoria 'ready'— es justo cuando se marchan los
     * fuertes.
     *
     * @throws CourseError
     */
    public static function ensureMarchable(string $categoria): void
    {
        if (in_array($categoria, ['completed', 'cancelled'], true)) {
            throw new CourseError('Esta cuenta ya esta cerrada: no se le puede marchar nada a la cocina');
        }
    }

    /**
     * Los tiempos que todavia no se marcharon, en orden.
     *
     * El tablero de cocina los dice: un pedido al que le falta el postre no
     * esta terminado, y sin avisarlo la cocina lo da por despachado.
     *
     * @param array<int, array{course: int, fired: bool}> $lineas
     * @return int[]
     */
    public static function pendientes(array $lineas): array
    {
        $cursos = [];
        foreach ($lineas as $linea) {
            if (!$linea['fired']) {
                $cursos[$linea['course']] = true;
            }
        }
        $lista = array_keys($cursos);
        sort($lista);
        return $lista;
    }
}
