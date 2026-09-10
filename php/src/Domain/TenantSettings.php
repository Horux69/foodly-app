<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Motor de configuracion por tenant (modulo 9).
 *
 * Traduce el JSON de tenants.settings a una configuracion tipada, con
 * valores por defecto segun el modelo de negocio. Es el UNICO lugar que sabe
 * interpretar ese JSON: el resto de la app pregunta aqui en vez de leer
 * claves sueltas del arreglo.
 */
final class TenantSettings
{
    /** Canales que entiende la plataforma. 'whatsapp' ya existe para cuando
     *  entre el agente conversacional; que este disponible no significa que
     *  un tenant lo tenga activo. */
    public const CHANNELS = ['counter', 'table', 'delivery', 'whatsapp', 'app'];

    /** Claves de DEFAULTS_BY_BUSINESS_TYPE, expuestas para validar entrada (p.ej. --business-type de bin/create_tenant.php). */
    public const BUSINESS_TYPES = ['fast_food', 'table_service', 'delivery'];

    /**
     * Cuanto se sugiere de propina, en porcentaje del subtotal.
     *
     * Es una sugerencia y no una regla: en Colombia la propina es voluntaria
     * y hay que poder quitarla sin discutir. El 10% es la costumbre.
     */
    public const TIP_PERCENT_DEFAULT = 10.0;

    private const DEFAULTS_BY_BUSINESS_TYPE = [
        'fast_food' => ['channels' => ['counter', 'delivery'], 'uses_tables' => false, 'asks_tip' => false],
        'table_service' => ['channels' => ['table'], 'uses_tables' => true, 'asks_tip' => true],
        'delivery' => ['channels' => ['delivery', 'whatsapp'], 'uses_tables' => false, 'asks_tip' => false],
    ];

    private const FALLBACK = 'fast_food';

    /** @param string[] $channels */
    public function __construct(
        public readonly array $channels,
        public readonly bool $usesTables,
        public readonly bool $asksTip,
        /** Porcentaje sugerido de propina. La propina sigue siendo opcional. */
        public readonly float $tipPercent = self::TIP_PERCENT_DEFAULT,
    ) {
    }

    public function allowsChannel(string $channel): bool
    {
        return in_array($channel, $this->channels, true);
    }

    /** @return array{channels: string[], uses_tables: bool, asks_tip: bool} */
    public static function defaultsFor(string $businessType): array
    {
        return self::DEFAULTS_BY_BUSINESS_TYPE[$businessType] ?? self::DEFAULTS_BY_BUSINESS_TYPE[self::FALLBACK];
    }

    /**
     * Valida la forma del JSON antes de guardarlo. Solo revisa las claves
     * presentes: un tenant puede guardar configuracion parcial y el resto se
     * resuelve con los defaults de su business_type.
     */
    public static function validate(array $raw): void
    {
        if (array_key_exists('channels', $raw)) {
            $channels = $raw['channels'];
            if (!is_array($channels) || $channels === []) {
                throw new SettingsError("'channels' debe ser una lista con al menos un canal");
            }
            $unknown = array_values(array_diff($channels, self::CHANNELS));
            if ($unknown !== []) {
                throw new SettingsError(
                    'Canales desconocidos: ' . implode(', ', $unknown) . '. Validos: ' . implode(', ', self::CHANNELS)
                );
            }
        }

        foreach (['uses_tables', 'asks_tip'] as $flag) {
            if (array_key_exists($flag, $raw) && !is_bool($raw[$flag])) {
                throw new SettingsError("'{$flag}' debe ser true o false");
            }
        }

        if (array_key_exists('tip_percent', $raw)) {
            $percent = $raw['tip_percent'];
            if (!is_int($percent) && !is_float($percent)) {
                throw new SettingsError("'tip_percent' debe ser un numero");
            }
            if ($percent < 0 || $percent > 100) {
                throw new SettingsError("'tip_percent' va entre 0 y 100");
            }
        }

        // Coherencia: vender por mesa exige manejar mesas. Sin esto quedaria
        // un tenant que acepta pedidos de mesa pero declara que no tiene mesas.
        if (
            !empty($raw['channels'])
            && in_array('table', $raw['channels'], true)
            && ($raw['uses_tables'] ?? null) === false
        ) {
            throw new SettingsError("El canal 'table' requiere uses_tables en true");
        }
    }

    public static function parse(?array $raw, string $businessType): self
    {
        $data = array_merge(self::defaultsFor($businessType), $raw ?? []);
        return new self(
            array_values($data['channels']),
            (bool) $data['uses_tables'],
            (bool) $data['asks_tip'],
            (float) ($data['tip_percent'] ?? self::TIP_PERCENT_DEFAULT),
        );
    }
}
