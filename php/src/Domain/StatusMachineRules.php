<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Que la maquina de estados de un tenant se pueda operar.
 *
 * StatusMachine responde "esta transicion vale"; esta clase responde la
 * pregunta de antes: si la configuracion entera deja trabajar al restaurante.
 * Es la parte peligrosa de dejar editar los estados desde la web, porque los
 * errores no se ven al guardar sino cuando alguien intenta vender:
 *
 * - Sin estado inicial no se puede crear ningun pedido.
 * - Un estado no final sin ninguna salida es un pedido atascado para siempre:
 *   no avanza, y como no es final tampoco se puede anular ni completar.
 *
 * Por eso se valida la configuracion resultante y no cada edicion suelta: lo
 * que deja un estado sin salida no suele ser tocarlo a el, sino borrar el
 * unico estado al que llevaba.
 */
final class StatusMachineRules
{
    /**
     * Lo que impide operar. Se devuelven en vez de lanzarse porque hay que
     * poder compararlos antes y despues de una edicion — ver ensureNotWorse.
     *
     * @param OrderStatus[] $statuses
     * @param StatusTransition[] $transitions
     * @return string[]
     */
    public static function problems(array $statuses, array $transitions): array
    {
        if ($statuses === []) {
            return ['El restaurante necesita al menos un estado de pedido'];
        }

        $problemas = [];

        $iniciales = array_values(array_filter($statuses, static fn (OrderStatus $s) => $s->isInitial));
        if ($iniciales === []) {
            $problemas[] = 'Ningun estado queda como inicial: sin el no se puede crear ningun pedido';
        } elseif (count($iniciales) > 1) {
            $codigos = implode(', ', array_map(static fn (OrderStatus $s) => $s->code, $iniciales));
            $problemas[] = "Solo un estado puede ser el inicial, y quedan varios: {$codigos}";
        } elseif ($iniciales[0]->isFinal) {
            $problemas[] = "El estado inicial '{$iniciales[0]->code}' no puede ser final: todo pedido naceria cerrado";
        }

        $salidas = self::salidasPorEstado($transitions);
        foreach ($statuses as $status) {
            if (!$status->isFinal && !isset($salidas[$status->id])) {
                $problemas[] = "El estado '{$status->code}' no tiene ninguna salida: un pedido que llegue ahi se queda "
                    . 'atascado. Dale una transicion, o marcalo como final.';
            }
        }

        return $problemas;
    }

    /**
     * Una edicion no puede introducir un problema nuevo.
     *
     * Se compara contra los que ya habia en vez de exigir una configuracion
     * impecable, porque si no una rota no se podria arreglar: cada paso de la
     * reparacion se rechazaria por los problemas que el paso siguiente iba a
     * resolver, y el restaurante quedaria atrapado. Asi, arreglar siempre se
     * puede y romper no.
     *
     * @param string[] $antes
     * @param string[] $despues
     */
    public static function ensureNotWorse(array $antes, array $despues): void
    {
        $nuevos = array_values(array_diff($despues, $antes));
        if ($nuevos !== []) {
            throw new StatusConfigError(implode(' ', $nuevos));
        }
    }

    /**
     * Lo que no impide operar pero casi seguro esta mal.
     *
     * Se devuelven en vez de lanzarse porque son estados intermedios legitimos
     * mientras alguien esta armando su flujo, y bloquear a mitad de camino
     * obligaria a adivinar el orden correcto de las ediciones.
     *
     * @param OrderStatus[] $statuses
     * @param StatusTransition[] $transitions
     * @return string[]
     */
    public static function warnings(array $statuses, array $transitions): array
    {
        $avisos = [];
        $categorias = array_map(static fn (OrderStatus $s) => $s->category, $statuses);

        if (!in_array('completed', $categorias, true)) {
            $avisos[] = 'No hay ningun estado de categoria "completado": los pedidos no se van a poder cerrar, '
                . 'y los reportes de venta cuentan pedidos completados.';
        }
        if (!in_array('cancelled', $categorias, true)) {
            $avisos[] = 'No hay ningun estado de categoria "anulado": no se va a poder anular un pedido.';
        }

        $salidas = self::salidasPorEstado($transitions);
        foreach ($statuses as $status) {
            if ($status->isFinal && isset($salidas[$status->id])) {
                $avisos[] = "El estado '{$status->code}' es final y tiene salidas configuradas: no se van a usar, "
                    . 'porque de un estado final no se sale.';
            }
        }

        foreach (self::inalcanzables($statuses, $transitions) as $code) {
            $avisos[] = "A '{$code}' no se llega desde el estado inicial: ningun pedido va a pasar por ahi.";
        }

        return $avisos;
    }

    /**
     * Los estados a los que no se llega desde el inicial siguiendo transiciones.
     *
     * @param OrderStatus[] $statuses
     * @param StatusTransition[] $transitions
     * @return string[] codigos
     */
    private static function inalcanzables(array $statuses, array $transitions): array
    {
        $inicial = null;
        foreach ($statuses as $status) {
            if ($status->isInitial) {
                $inicial = $status;
                break;
            }
        }
        if ($inicial === null) {
            return [];
        }

        $destinos = [];
        foreach ($transitions as $t) {
            $destinos[$t->fromStatusId][] = $t->toStatusId;
        }

        $vistos = [$inicial->id => true];
        $pendientes = [$inicial->id];
        while ($pendientes !== []) {
            $actual = array_pop($pendientes);
            foreach ($destinos[$actual] ?? [] as $siguiente) {
                if (!isset($vistos[$siguiente])) {
                    $vistos[$siguiente] = true;
                    $pendientes[] = $siguiente;
                }
            }
        }

        $sueltos = [];
        foreach ($statuses as $status) {
            if (!isset($vistos[$status->id])) {
                $sueltos[] = $status->code;
            }
        }
        return $sueltos;
    }

    /**
     * @param StatusTransition[] $transitions
     * @return array<string, true> ids de estado que tienen al menos una salida
     */
    private static function salidasPorEstado(array $transitions): array
    {
        $salidas = [];
        foreach ($transitions as $t) {
            $salidas[$t->fromStatusId] = true;
        }
        return $salidas;
    }
}
