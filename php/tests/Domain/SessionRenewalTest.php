<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\SessionError;
use App\Domain\SessionRenewal;
use PHPUnit\Framework\TestCase;

final class SessionRenewalTest extends TestCase
{
    private const AHORA = 1_760_000_000;

    public function testUnTurnoLargoSePuedeSeguirRenovando(): void
    {
        $this->expectNotToPerformAssertions();
        // Doce horas desde que escribio la contrasena: sigue en su turno.
        SessionRenewal::ensureRenewable(self::AHORA - 12 * 3600, self::AHORA);
    }

    public function testPasadoElLimiteHayQueVolverAEntrar(): void
    {
        $this->expectException(SessionError::class);
        SessionRenewal::ensureRenewable(self::AHORA - 25 * 3600, self::AHORA);
    }

    public function testJustoEnElLimiteYaNo(): void
    {
        $this->expectException(SessionError::class);
        SessionRenewal::ensureRenewable(self::AHORA - 24 * 3600, self::AHORA);
    }

    /**
     * El limite se mide desde que la persona escribio su contrasena y no
     * desde la emision del ultimo token: si se midiera desde ahi, cada
     * renovacion correria el limite y no seria un limite.
     */
    public function testRenovarNoCorreElLimite(): void
    {
        $authTime = self::AHORA - 23 * 3600;
        $this->expectNotToPerformAssertions();
        SessionRenewal::ensureRenewable($authTime, self::AHORA);

        // Una hora despues, con el mismo auth_time arrastrado, ya no.
        try {
            SessionRenewal::ensureRenewable($authTime, self::AHORA + 3600);
            $this->fail('Se esperaba SessionError');
        } catch (SessionError) {
            // esperado
        }
    }
}
