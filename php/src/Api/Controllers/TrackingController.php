<?php

declare(strict_types=1);

namespace App\Api\Controllers;

use App\Api\ApiException;
use App\Services\TrackingError;
use App\Services\TrackingService;

/**
 * El seguimiento publico (F9.2): el unico endpoint sin sesion aparte del
 * login.
 *
 * No llama a `Deps::getContext()` a proposito: quien abre este enlace es el
 * cliente, que no tiene usuario en el sistema. Lo que lo protege no es un
 * permiso sino el token —16 bytes al azar— y lo que devuelve el servicio,
 * que es deliberadamente poco.
 */
final class TrackingController
{
    public static function show(array $params): array
    {
        try {
            return TrackingService::track($params['token']);
        } catch (TrackingError $e) {
            // Siempre 404 y siempre el mismo texto: un mensaje distinto para
            // "token mal formado" y para "no existe" le diria a quien esta
            // probando cuando va bien encaminado.
            throw new ApiException(404, $e->getMessage());
        }
    }
}
