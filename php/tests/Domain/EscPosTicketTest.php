<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\EscPos;
use App\Domain\EscPosTicket;
use PHPUnit\Framework\TestCase;

final class EscPosTicketTest extends TestCase
{
    private function pedido(array $cambios = []): array
    {
        return array_merge([
            'order_number' => 'NOR-00007',
            'channel' => 'counter',
            'created_at' => '2026-01-15T20:30:00Z',
            'table_code' => null,
            'subtotal' => '20000.00',
            'tax_total' => '0.00',
            'delivery_fee' => '0.00',
            'discount' => '0.00',
            'tip' => '0.00',
            'total' => '20000.00',
            'items' => [
                ['quantity' => 2, 'name_snapshot' => 'Hamburguesa', 'line_total' => '20000.00', 'modifiers' => [], 'components' => []],
            ],
            'balance' => ['pending' => '0.00', 'is_settled' => true],
        ], $cambios);
    }

    private function sede(): array
    {
        return ['name' => 'Sede Norte', 'address' => 'Calle 1', 'phone' => '3000000000'];
    }

    private function build(array $pedido, array $pagos = [], ?array $fiscal = null, bool $precuenta = false, int $propina = 0): string
    {
        return EscPosTicket::build(
            $pedido,
            $pagos,
            'Burger Demo',
            $this->sede(),
            $fiscal,
            'COP',
            'America/Bogota',
            48,
            $precuenta,
            $propina,
        );
    }

    public function testEmpiezaConElComandoDeInicioYTerminaEnCorte(): void
    {
        $bytes = $this->build($this->pedido());
        $this->assertStringStartsWith(EscPos::INIT, $bytes);
        $this->assertStringEndsWith(EscPos::CUT, $bytes);
    }

    public function testTraeElNombreDelRestauranteYDeLaSede(): void
    {
        $bytes = $this->build($this->pedido());
        $this->assertStringContainsString('Burger Demo', $bytes);
        $this->assertStringContainsString('Sede Norte', $bytes);
        $this->assertStringContainsString('Calle 1', $bytes);
        $this->assertStringContainsString('Tel. 3000000000', $bytes);
    }

    public function testTraeCadaLineaConSuImporte(): void
    {
        $bytes = $this->build($this->pedido());
        $this->assertStringContainsString('2x Hamburguesa', $bytes);
        $this->assertStringContainsString('COP 20.000', $bytes);
        $this->assertStringContainsString('TOTAL', $bytes);
    }

    /** El backend ya calculo los totales: aqui no se suma nada, solo se formatea. */
    public function testNoMuestraFilasEnCero(): void
    {
        $bytes = $this->build($this->pedido());
        $this->assertStringNotContainsString('Domicilio', $bytes);
        $this->assertStringNotContainsString('Descuento', $bytes);
        $this->assertStringNotContainsString('Propina', $bytes);
    }

    public function testMuestraDomicilioDescuentoYPropinaCuandoAplican(): void
    {
        $bytes = $this->build($this->pedido([
            'delivery_fee' => '5000.00',
            'discount' => '1000.00',
            'tip' => '2000.00',
            'total' => '26000.00',
        ]));
        $this->assertStringContainsString('Domicilio', $bytes);
        $this->assertStringContainsString('Descuento', $bytes);
        $this->assertStringContainsString('-COP 1.000', $bytes);
        $this->assertStringContainsString('Propina', $bytes);
    }

    /** El numero autorizado nunca va en una pre-cuenta: con el encima, se leeria como el comprobante que todavia no es. */
    public function testElDocumentoFiscalNoApareceEnUnaPrecuenta(): void
    {
        $fiscal = ['full_number' => 'SETP990000001', 'external_id' => 'cufe-123'];
        $conFiscal = $this->build($this->pedido(), fiscal: $fiscal);
        $precuenta = $this->build($this->pedido(), fiscal: $fiscal, precuenta: true);

        $this->assertStringContainsString('SETP990000001', $conFiscal);
        $this->assertStringNotContainsString('SETP990000001', $precuenta);
        $this->assertStringContainsString('PRE-CUENTA', $precuenta);
        $this->assertStringContainsString('NO ES FACTURA DE VENTA', $precuenta);
    }

    public function testMuestraLosPagosYElSaldoPendiente(): void
    {
        $bytes = $this->build(
            $this->pedido(['balance' => ['pending' => '5000.00', 'is_settled' => false]]),
            pagos: [
                ['method' => 'cash', 'amount' => '15000.00', 'refund_of_payment_id' => null],
            ],
        );
        $this->assertStringContainsString('Efectivo', $bytes);
        $this->assertStringContainsString('COP 15.000', $bytes);
        $this->assertStringContainsString('Falta por pagar', $bytes);
        $this->assertStringContainsString('COP 5.000', $bytes);
    }

    public function testUnReembolsoSaleConSigno(): void
    {
        $bytes = $this->build(
            $this->pedido(),
            pagos: [
                ['method' => 'cash', 'amount' => '20000.00', 'refund_of_payment_id' => null],
                ['method' => 'cash', 'amount' => '20000.00', 'refund_of_payment_id' => 'p1'],
            ],
        );
        $this->assertStringContainsString('Reembolso Efectivo', $bytes);
        $this->assertStringContainsString('-COP 20.000', $bytes);
    }

    public function testLaPropinaSugeridaSoloSaleCuandoSePide(): void
    {
        $sinPropina = $this->build($this->pedido());
        $conPropina = $this->build($this->pedido(), precuenta: true, propina: 200000);

        $this->assertStringNotContainsString('sugerida', $sinPropina);
        $this->assertStringContainsString('Propina sugerida', $conPropina);
        $this->assertStringContainsString('Total con propina', $conPropina);
        $this->assertStringContainsString('COP 2.000', $conPropina);
    }

    public function testLosComponentesDeUnComboSalenDebajoDeLaLinea(): void
    {
        $bytes = $this->build($this->pedido([
            'items' => [[
                'quantity' => 1,
                'name_snapshot' => 'Combo familiar',
                'line_total' => '40000.00',
                'modifiers' => [],
                'components' => [['quantity' => 2, 'name_snapshot' => 'Hamburguesa']],
            ]],
        ]));
        $this->assertStringContainsString('2x Hamburguesa', $bytes);
    }

    /** Sin conocer la impresora real, el texto sale en ASCII: mejor sin tilde que con basura en el papel. */
    public function testElTextoSaleSinTildes(): void
    {
        $bytes = $this->build($this->pedido(['items' => [
            ['quantity' => 1, 'name_snapshot' => 'Limonada de coco', 'line_total' => '5000.00', 'modifiers' => [], 'components' => []],
        ]]));
        $this->assertStringContainsString('Limonada de coco', $bytes);
    }
}
