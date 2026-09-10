<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\OrderStatus;
use App\Domain\StatusConfigError;
use App\Domain\StatusMachine;
use App\Domain\StatusMachineRules;
use App\Domain\StatusTransition;
use App\Domain\TransitionError;
use PHPUnit\Framework\TestCase;

final class StatusMachineRulesTest extends TestCase
{
    private function estado(string $id, string $code, string $cat, bool $inicial = false, bool $final = false): OrderStatus
    {
        return new OrderStatus($id, $code, $cat, isInitial: $inicial, isFinal: $final);
    }

    /** Un flujo minimo pero operable: nace, avanza, cierra, y se puede anular. */
    private function flujoValido(): array
    {
        return [
            [
                $this->estado('1', 'pending', 'new', inicial: true),
                $this->estado('2', 'ready', 'ready'),
                $this->estado('3', 'delivered', 'completed', final: true),
                $this->estado('4', 'cancelled', 'cancelled', final: true),
            ],
            [
                new StatusTransition('1', '2', 'orders.advance_kitchen'),
                new StatusTransition('2', '3', 'orders.advance_kitchen'),
                new StatusTransition('1', '4', 'orders.cancel'),
            ],
        ];
    }

    public function testUnFlujoOperableNoTieneProblemas(): void
    {
        [$estados, $transiciones] = $this->flujoValido();
        $this->assertSame([], StatusMachineRules::problems($estados, $transiciones));
    }

    public function testUnFlujoOperableNoTieneAvisos(): void
    {
        [$estados, $transiciones] = $this->flujoValido();
        $this->assertSame([], StatusMachineRules::warnings($estados, $transiciones));
    }

    // ---------- lo que bloquea ----------

    public function testDetectaQuedarseSinEstadoInicial(): void
    {
        [$estados, $transiciones] = $this->flujoValido();
        $estados[0] = $this->estado('1', 'pending', 'new'); // ya no es inicial

        $problemas = StatusMachineRules::problems($estados, $transiciones);
        $this->assertCount(1, $problemas);
        $this->assertStringContainsString('no se puede crear ningun pedido', $problemas[0]);
    }

    public function testDetectaDosEstadosIniciales(): void
    {
        [$estados, $transiciones] = $this->flujoValido();
        $estados[1] = $this->estado('2', 'ready', 'ready', inicial: true);

        $this->assertStringContainsString('pending, ready', StatusMachineRules::problems($estados, $transiciones)[0]);
    }

    public function testDetectaUnInicialQueTambienEsFinal(): void
    {
        $estados = [$this->estado('1', 'pending', 'new', inicial: true, final: true)];

        $this->assertStringContainsString('naceria cerrado', StatusMachineRules::problems($estados, [])[0]);
    }

    /**
     * El caso que da nombre a la regla: no lo provoca tocar el estado que
     * queda atascado, sino borrar el unico al que llevaba.
     */
    public function testDetectaUnEstadoSinSalida(): void
    {
        [$estados, $transiciones] = $this->flujoValido();
        // Se borra 'delivered' y con el la transicion 2 -> 3: 'ready' se queda
        // sin ninguna salida y no es final.
        unset($estados[2]);
        $transiciones = array_values(array_filter($transiciones, static fn ($t) => $t->toStatusId !== '3'));

        $problemas = StatusMachineRules::problems(array_values($estados), $transiciones);
        $this->assertCount(1, $problemas);
        $this->assertStringContainsString("'ready' no tiene ninguna salida", $problemas[0]);
    }

    public function testUnEstadoFinalNoNecesitaSalida(): void
    {
        $this->assertSame([], StatusMachineRules::problems(
            [
                $this->estado('1', 'pending', 'new', inicial: true),
                $this->estado('2', 'delivered', 'completed', final: true),
            ],
            [new StatusTransition('1', '2', null)]
        ));
    }

    // ---------- no empeorar ----------

    public function testUnaEdicionQueNoRompeNadaPasa(): void
    {
        $this->expectNotToPerformAssertions();
        StatusMachineRules::ensureNotWorse([], []);
    }

    public function testUnaEdicionQueIntroduceUnProblemaSeRechaza(): void
    {
        $this->expectException(StatusConfigError::class);
        $this->expectExceptionMessageMatches("/'ready' no tiene/");
        StatusMachineRules::ensureNotWorse([], ["El estado 'ready' no tiene ninguna salida"]);
    }

    /**
     * Sobre una configuracion ya rota se puede seguir editando: si no, cada
     * paso de la reparacion chocaria con lo que el siguiente iba a resolver y
     * el restaurante quedaria atrapado con un flujo inoperable.
     */
    public function testSobreUnaConfiguracionRotaSePuedeSeguirEditando(): void
    {
        $this->expectNotToPerformAssertions();
        $roto = ["El estado 'ready' no tiene ninguna salida"];
        StatusMachineRules::ensureNotWorse($roto, $roto);
    }

    public function testArreglarUnProblemaPasaAunqueQuedenOtros(): void
    {
        $this->expectNotToPerformAssertions();
        StatusMachineRules::ensureNotWorse(['a', 'b'], ['b']);
    }

    public function testSoloSeReportaLoNuevo(): void
    {
        try {
            StatusMachineRules::ensureNotWorse(['viejo'], ['viejo', 'nuevo']);
            $this->fail('Se esperaba StatusConfigError');
        } catch (StatusConfigError $e) {
            $this->assertSame('nuevo', $e->getMessage());
        }
    }

    /**
     * Por que la regla importa: sin ella, un pedido en un estado sin salida
     * no avanza —no hay transicion— y tampoco esta cerrado.
     */
    public function testUnEstadoSinSalidaDejaElPedidoAtascado(): void
    {
        $maquina = new StatusMachine(
            [$this->estado('1', 'pending', 'new', inicial: true), $this->estado('2', 'limbo', 'kitchen')],
            [new StatusTransition('1', '2', null)]
        );

        $this->expectException(TransitionError::class);
        $maquina->validate('2', '1', ['orders.advance_kitchen']);
    }

    // ---------- lo que solo avisa ----------

    public function testAvisaSiNoHayComoCerrarUnPedido(): void
    {
        $avisos = StatusMachineRules::warnings(
            [$this->estado('1', 'pending', 'new', inicial: true), $this->estado('2', 'cancelled', 'cancelled', final: true)],
            [new StatusTransition('1', '2', null)]
        );
        $this->assertCount(1, $avisos);
        $this->assertStringContainsString('completado', $avisos[0]);
    }

    public function testAvisaSiNoHayComoAnular(): void
    {
        $avisos = StatusMachineRules::warnings(
            [$this->estado('1', 'pending', 'new', inicial: true), $this->estado('2', 'done', 'completed', final: true)],
            [new StatusTransition('1', '2', null)]
        );
        $this->assertCount(1, $avisos);
        $this->assertStringContainsString('anulado', $avisos[0]);
    }

    public function testAvisaDeSalidasEnUnEstadoFinal(): void
    {
        [$estados, $transiciones] = $this->flujoValido();
        $transiciones[] = new StatusTransition('3', '2', null); // desde el final

        $avisos = StatusMachineRules::warnings($estados, $transiciones);
        $this->assertCount(1, $avisos);
        $this->assertStringContainsString('de un estado final no se sale', $avisos[0]);
    }

    public function testAvisaDelEstadoAlQueNadieLlega(): void
    {
        [$estados, $transiciones] = $this->flujoValido();
        $estados[] = $this->estado('5', 'huerfano', 'kitchen');
        $transiciones[] = new StatusTransition('5', '3', null); // sale, pero nadie entra

        $avisos = StatusMachineRules::warnings($estados, $transiciones);
        $this->assertCount(1, $avisos);
        $this->assertStringContainsString("A 'huerfano' no se llega", $avisos[0]);
    }
}
