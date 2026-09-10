<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\PaymentBalance;
use App\Domain\StatusChangeError;
use App\Domain\StatusChangeRules;
use PHPUnit\Framework\TestCase;

final class StatusChangeRulesTest extends TestCase
{
    private function saldo(int $total, int $cobrado, int $devuelto = 0): PaymentBalance
    {
        return PaymentBalance::compute($total, [$cobrado], [$devuelto]);
    }

    // ---------- completar ----------

    public function testUnPedidoSaldadoSePuedeCompletar(): void
    {
        StatusChangeRules::ensureCanEnter('completed', $this->saldo(5_000_000, 5_000_000), null);
        $this->expectNotToPerformAssertions();
    }

    public function testUnPedidoSinSaldarNoSePuedeCompletar(): void
    {
        $this->expectException(StatusChangeError::class);
        $this->expectExceptionMessage('faltan 20000.00');
        StatusChangeRules::ensureCanEnter('completed', $this->saldo(5_000_000, 3_000_000), null);
    }

    public function testCompletarNoExigeMotivo(): void
    {
        StatusChangeRules::ensureCanEnter('completed', $this->saldo(5_000_000, 5_000_000), null);
        $this->expectNotToPerformAssertions();
    }

    // ---------- anular ----------

    public function testUnPedidoSinCobrosSeAnulaConMotivo(): void
    {
        StatusChangeRules::ensureCanEnter('cancelled', $this->saldo(5_000_000, 0), 'El cliente no contesto');
        $this->expectNotToPerformAssertions();
    }

    /** La decisión de negocio de F2.4. */
    public function testUnPedidoConPlataEncimaNoSeAnula(): void
    {
        $this->expectException(StatusChangeError::class);
        $this->expectExceptionMessage('tiene 30000.00 cobrados: reembolsa antes de anularlo');
        StatusChangeRules::ensureCanEnter('cancelled', $this->saldo(5_000_000, 3_000_000), 'Me equivoque');
    }

    public function testUnCobroParcialTambienBloqueaLaAnulacion(): void
    {
        // Aunque falte por cobrar, lo que ya entró hay que devolverlo.
        $this->expectException(StatusChangeError::class);
        StatusChangeRules::ensureCanEnter('cancelled', $this->saldo(5_000_000, 100), 'Motivo');
    }

    public function testSiYaSeReembolsoTodoElPedidoSeAnula(): void
    {
        // Es el camino que la regla obliga a recorrer: devolver y después anular.
        StatusChangeRules::ensureCanEnter(
            'cancelled',
            $this->saldo(5_000_000, 3_000_000, 3_000_000),
            'Devuelto y anulado',
        );
        $this->expectNotToPerformAssertions();
    }

    public function testUnReembolsoParcialNoAlcanzaParaAnular(): void
    {
        $this->expectException(StatusChangeError::class);
        $this->expectExceptionMessage('tiene 10000.00 cobrados');
        StatusChangeRules::ensureCanEnter(
            'cancelled',
            $this->saldo(5_000_000, 3_000_000, 2_000_000),
            'Motivo',
        );
    }

    public function testAnularExigeMotivo(): void
    {
        $this->expectException(StatusChangeError::class);
        $this->expectExceptionMessage('hay que decir el motivo');
        StatusChangeRules::ensureCanEnter('cancelled', $this->saldo(5_000_000, 0), null);
    }

    public function testUnMotivoEnBlancoNoEsUnMotivo(): void
    {
        $this->expectException(StatusChangeError::class);
        StatusChangeRules::ensureCanEnter('cancelled', $this->saldo(5_000_000, 0), '   ');
    }

    /** El dinero se revisa antes que el motivo: es el problema más grave de los dos. */
    public function testConPlataEncimaYSinMotivoSeAvisaDelDinero(): void
    {
        $this->expectException(StatusChangeError::class);
        $this->expectExceptionMessage('reembolsa antes de anularlo');
        StatusChangeRules::ensureCanEnter('cancelled', $this->saldo(5_000_000, 3_000_000), null);
    }

    // ---------- el resto de categorías ----------

    public function testLasDemasCategoriasNoExigenNada(): void
    {
        foreach (['new', 'kitchen', 'ready', 'in_transit'] as $categoria) {
            StatusChangeRules::ensureCanEnter($categoria, $this->saldo(5_000_000, 0), null);
        }
        $this->expectNotToPerformAssertions();
    }
}
