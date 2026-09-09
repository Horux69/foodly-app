<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\Slug;
use PHPUnit\Framework\TestCase;

final class SlugTest extends TestCase
{
    public function testMinusculasYGuiones(): void
    {
        $this->assertSame('burger-demo', Slug::from('Burger Demo'));
    }

    /** Tiene que sobrevivir a que lo dicten por teléfono. */
    public function testSinAcentosNiEnies(): void
    {
        $this->assertSame('pizza-rapida-del-nino', Slug::from('Pizza Rápida del Niño'));
    }

    public function testSinSignosNiEspaciosDeSobra(): void
    {
        $this->assertSame('el-fogon', Slug::from('  ¡El Fogón!  '));
    }

    public function testUnNombreSinLetrasNoQuedaVacio(): void
    {
        $this->assertSame('empresa', Slug::from('¿¡!?'));
    }

    public function testSeRecortaAlMaximo(): void
    {
        $this->assertSame(Slug::MAX, strlen(Slug::from(str_repeat('nombre largo ', 20))));
    }

    public function testElPrimeroLibreCuandoElBaseEstaTomado(): void
    {
        $tomados = ['burger-demo' => true, 'burger-demo-2' => true];
        $this->assertSame(
            'burger-demo-3',
            Slug::unique('Burger Demo', static fn (string $s) => isset($tomados[$s]))
        );
    }

    public function testElSufijoNoHaceQueSePaseDelMaximo(): void
    {
        $largo = str_repeat('a', 60);
        $slug = Slug::unique($largo, static fn (string $s) => $s === substr($largo, 0, Slug::MAX));
        $this->assertLessThanOrEqual(Slug::MAX, strlen($slug));
        $this->assertStringEndsWith('-2', $slug);
    }
}
