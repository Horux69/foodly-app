<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\TipError;
use App\Domain\TipRules;
use PHPUnit\Framework\TestCase;

final class TipRulesTest extends TestCase
{
    /** Cero no es un caso raro: la propina es voluntaria. */
    public function testCeroEsValido(): void
    {
        TipRules::validate(0, 100_000_00);
        $this->expectNotToPerformAssertions();
    }

    public function testUnaPropinaNormalPasa(): void
    {
        TipRules::validate(10_000_00, 100_000_00);
        $this->expectNotToPerformAssertions();
    }

    public function testNoSeAceptaNegativa(): void
    {
        $this->expectException(TipError::class);
        TipRules::validate(-1, 100_000_00);
    }

    /**
     * Una propina mayor que el pedido casi siempre es un cero de mas, y el
     * momento de descubrirlo no es el arqueo.
     */
    public function testUnaPropinaMayorQueLaVentaSeFrena(): void
    {
        $this->expectException(TipError::class);
        $this->expectExceptionMessageMatches('/revisa el importe/');
        TipRules::validate(200_000_00, 20_000_00);
    }

    public function testJustoElCienPorCientoPasa(): void
    {
        TipRules::validate(20_000_00, 20_000_00);
        $this->expectNotToPerformAssertions();
    }

    public function testLaSugerenciaRedondeaHaciaAbajo(): void
    {
        $this->assertSame(3_333, TipRules::suggestCents(33_333, 10.0));
        $this->assertSame(0, TipRules::suggestCents(100_000_00, 0));
    }
}
