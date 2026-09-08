<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\SettingsError;
use App\Domain\TenantSettings;
use PHPUnit\Framework\TestCase;

final class TenantSettingsTest extends TestCase
{
    public function testDefaultsPorModeloDeNegocio(): void
    {
        $this->assertFalse(TenantSettings::parse(null, 'fast_food')->usesTables);
        $this->assertTrue(TenantSettings::parse(null, 'table_service')->usesTables);
        $this->assertContains('delivery', TenantSettings::parse(null, 'delivery')->channels);
    }

    public function testBusinessTypeDesconocidoCaeEnFastFood(): void
    {
        $this->assertSame(
            TenantSettings::defaultsFor('fast_food')['channels'],
            TenantSettings::parse(null, 'food_truck')->channels,
        );
    }

    public function testConfiguracionParcialCompletaConDefaults(): void
    {
        $settings = TenantSettings::parse(['asks_tip' => true], 'fast_food');
        $this->assertTrue($settings->asksTip);
        $this->assertSame(['counter', 'delivery'], $settings->channels);
    }

    public function testAllowsChannel(): void
    {
        $settings = TenantSettings::parse(['channels' => ['counter']], 'fast_food');
        $this->assertTrue($settings->allowsChannel('counter'));
        $this->assertFalse($settings->allowsChannel('delivery'));
    }

    public function testValidaCanalDesconocido(): void
    {
        $this->expectException(SettingsError::class);
        TenantSettings::validate(['channels' => ['telepatia']]);
    }

    public function testValidaListaDeCanalesVacia(): void
    {
        $this->expectException(SettingsError::class);
        TenantSettings::validate(['channels' => []]);
    }

    public function testValidaTipoDeFlag(): void
    {
        $this->expectException(SettingsError::class);
        TenantSettings::validate(['asks_tip' => 'si']);
    }

    public function testCanalMesaExigeUsesTables(): void
    {
        $this->expectException(SettingsError::class);
        TenantSettings::validate(['channels' => ['table'], 'uses_tables' => false]);
    }

    public function testCanalMesaConUsesTablesEnTrueNoLevanta(): void
    {
        TenantSettings::validate(['channels' => ['table'], 'uses_tables' => true]);
        $this->addToAssertionCount(1);
    }

    public function testConfiguracionValidaNoLevanta(): void
    {
        TenantSettings::validate(['channels' => ['counter', 'whatsapp'], 'uses_tables' => false, 'asks_tip' => true]);
        $this->addToAssertionCount(1);
    }
}
