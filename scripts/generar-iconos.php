<?php

declare(strict_types=1);

/**
 * Genera los PNG del manifiesto a partir de la misma figura que web/iconos/app.svg.
 *
 * Chrome instala una aplicacion con un icono SVG, pero Android y iOS piden un
 * mapa de bits para la pantalla de inicio. En vez de dejar dos binarios sin
 * origen en el repositorio, se generan aqui: la geometria esta escrita una
 * sola vez y volver a correr esto reproduce exactamente los mismos archivos.
 *
 *   php scripts/generar-iconos.php
 */

$destino = dirname(__DIR__) . '/web/iconos';

foreach ([192, 512] as $lado) {
    $lienzo = imagecreatetruecolor($lado, $lado);
    $fondo = imagecolorallocate($lienzo, 0x1c, 0x19, 0x17); // stone-900
    $blanco = imagecolorallocate($lienzo, 0xff, 0xff, 0xff);
    imagefilledrectangle($lienzo, 0, 0, $lado, $lado, $fondo);

    imageantialias($lienzo, true);
    $centro = $lado / 2;

    // El aro: un circulo blanco y otro del color del fondo encima, porque
    // imagearc con grosor no antialiasea y deja el borde dentado.
    imagefilledellipse($lienzo, (int) $centro, (int) $centro, (int) ($lado * 0.4297), (int) ($lado * 0.4297), $blanco);
    imagefilledellipse($lienzo, (int) $centro, (int) $centro, (int) ($lado * 0.3438), (int) ($lado * 0.3438), $fondo);
    imagefilledellipse($lienzo, (int) $centro, (int) $centro, (int) ($lado * 0.1875), (int) ($lado * 0.1875), $blanco);

    imagepng($lienzo, sprintf('%s/app-%d.png', $destino, $lado));
    imagedestroy($lienzo);
    echo "web/iconos/app-{$lado}.png\n";
}
