<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * En que caja entra un cobro cuando la sede tiene mas de una.
 *
 * Con una sola caja la pregunta no existia: el cobro iba al turno abierto
 * de la sucursal y punto. Con dos, elegir mal —o no elegir— manda la plata
 * al cajon equivocado, y eso no se descubre hasta el arqueo, cuando a una
 * caja le sobra exactamente lo que a la otra le falta.
 *
 * Dos decisiones:
 *
 * - **Con una sola caja abierta no se pregunta nada.** Es el caso de casi
 *   todos los restaurantes y no tiene por que costarles un paso mas. Si el
 *   dispositivo dice en que caja esta, se respeta; si no, hay una sola
 *   respuesta posible.
 * - **Con dos abiertas, sin caja elegida no se cobra.** La alternativa
 *   —dejar el cobro sin turno, que es lo que pasaria solo— seria plata
 *   cobrada que no entra a ningun arqueo y que nadie echa de menos hasta
 *   que cuadra de menos. Mejor un error que se lee.
 */
final class RegisterChoice
{
    private function __construct()
    {
    }

    /**
     * @param array<int, array{id: string, register_id: ?string, register_name: ?string}> $abiertas
     *        los turnos abiertos de la sucursal
     * @param ?string $registerId en que caja dice estar el dispositivo
     * @return ?string el turno al que entra el cobro; null si no hay ninguno abierto
     * @throws RegisterChoiceError cuando hay varias y no se puede elegir
     */
    public static function sessionFor(array $abiertas, ?string $registerId): ?string
    {
        if ($abiertas === []) {
            // Cobrar no exige turno abierto: lo que se cobre sin turno
            // queda fuera del arqueo, que es exactamente lo que significa.
            return null;
        }

        if ($registerId !== null) {
            foreach ($abiertas as $turno) {
                if ($turno['register_id'] === $registerId) {
                    return $turno['id'];
                }
            }
            // La caja existe pero no tiene turno abierto: no se cae al de
            // otra caja, que seria mandar la plata al cajon del vecino.
            return null;
        }

        if (count($abiertas) === 1) {
            return $abiertas[0]['id'];
        }

        $nombres = implode(', ', array_map(
            static fn (array $t) => $t['register_name'] ?? 'la caja de la sucursal',
            $abiertas,
        ));
        throw new RegisterChoiceError(
            "Hay mas de una caja abierta en esta sede ({$nombres}): elige en cual estas cobrando"
        );
    }
}
