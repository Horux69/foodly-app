<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\ComboError;
use App\Domain\ComboRules;
use PHPUnit\Framework\TestCase;

final class ComboRulesTest extends TestCase
{
    /** @return array<int, array{item_id: string, quantity: int}> */
    private function partes(string ...$ids): array
    {
        return array_map(static fn (string $id) => ['item_id' => $id, 'quantity' => 1], $ids);
    }

    public function testUnComboDeTresProductos(): void
    {
        $this->expectNotToPerformAssertions();
        ComboRules::validate('combo', $this->partes('hamburguesa', 'papas', 'gaseosa'), []);
    }

    public function testUnoSoloNoEsUnCombo(): void
    {
        $this->expectException(ComboError::class);
        $this->expectExceptionMessageMatches('/al menos dos/');
        ComboRules::validate('combo', $this->partes('hamburguesa'), []);
    }

    public function testNoSeLlevaASiMismo(): void
    {
        $this->expectException(ComboError::class);
        $this->expectExceptionMessageMatches('/a si mismo/');
        ComboRules::validate('combo', $this->partes('combo', 'papas'), []);
    }

    /** El ciclo indirecto: A lleva B, y B ya llevaba A. */
    public function testNoSeLlevaASiMismoAtravesDeOtro(): void
    {
        $this->expectException(ComboError::class);
        $this->expectExceptionMessageMatches('/a traves de otro/');
        ComboRules::validate(
            'A',
            $this->partes('B', 'papas'),
            ['B' => ['A', 'gaseosa']],
        );
    }

    public function testUnComboDentroDeOtroSinCicloSiVale(): void
    {
        $this->expectNotToPerformAssertions();
        ComboRules::validate(
            'familiar',
            $this->partes('combo-simple', 'gaseosa-grande'),
            ['combo-simple' => ['hamburguesa', 'papas']],
        );
    }

    public function testRechazaCantidadCero(): void
    {
        $this->expectException(ComboError::class);
        ComboRules::validate('combo', [
            ['item_id' => 'papas', 'quantity' => 0],
            ['item_id' => 'gaseosa', 'quantity' => 1],
        ], []);
    }

    /** Dos combos pedidos son dos de cada cosa que llevan. */
    public function testExpandirMultiplicaPorLoPedido(): void
    {
        $this->assertSame(
            [
                ['name' => 'Hamburguesa', 'quantity' => 2],
                ['name' => 'Papas', 'quantity' => 4],
            ],
            ComboRules::expand(
                [['name' => 'Hamburguesa', 'quantity' => 1], ['name' => 'Papas', 'quantity' => 2]],
                2
            )
        );
    }
}
