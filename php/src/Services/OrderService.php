<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Domain\BranchSchedule;
use App\Domain\ComboRules;
use App\Domain\DeliveryError;
use App\Domain\DiscountError;
use App\Domain\DiscountRules;
use App\Domain\DeliveryRules;
use App\Domain\DeliveryZoneRules;
use App\Domain\LineInput;
use App\Domain\LineResult;
use App\Domain\MenuPricing;
use App\Domain\ModifierGroupConstraint;
use App\Domain\ModifierValidation;
use App\Domain\ModifierValidationError;
use App\Domain\OrderEditError;
use App\Domain\OrderEditRules;
use App\Domain\OrderTotals;
use App\Domain\OrderTotalsCalculator;
use App\Domain\OrderTotalsError;
use App\Domain\PaymentBalance;
use App\Domain\ScheduleWindow;
use App\Domain\TenantSettings;
use App\Models\Branch;
use App\Models\MenuItem;
use App\Models\Modifier;
use App\Models\DeliveryZone;
use App\Models\Order;
use App\Models\OrderItem;
use App\Repositories\BranchRepository;
use App\Repositories\CustomerRepository;
use App\Repositories\DiscountReasonRepository;
use App\Repositories\DeliveryRepository;
use App\Repositories\MenuRepository;
use App\Repositories\OrderRepository;
use App\Repositories\OrderStatusRepository;
use App\Repositories\PaymentRepository;
use App\Repositories\TableRepository;
use App\Repositories\TenantRepository;

/**
 * Toma de pedidos: valida disponibilidad y modificadores, congela precios y
 * calcula totales. Un solo lugar orquesta todo esto; el resto de la app
 * (incluido el futuro agente de WhatsApp) debe pasar por aqui, nunca repetir
 * esta logica.
 */
final class OrderService
{
    private static function checkBranchOpen(Branch $branch, string $channel): void
    {
        $schedules = (new BranchRepository(Database::app()))->getSchedules($branch->id);
        if ($schedules === []) {
            // Sin horarios configurados no restringe: evita bloquear
            // tenants que todavia no terminaron de configurarse.
            return;
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone($branch->timezone));
        // branch_schedules se siembra con la convencion de Python
        // (Monday=0..Sunday=6); PHP da 1..7 con format('N'), de ahi el -1.
        $weekday = (int) $now->format('N') - 1;

        $windows = array_map(
            static fn ($s) => new ScheduleWindow($s->weekday, $s->opensAt, $s->closesAt, $s->channel, $s->isActive),
            $schedules,
        );

        if (!BranchSchedule::isBranchOpen($weekday, $now->format('H:i:s'), $channel, $windows)) {
            throw new OrderError('La sucursal esta cerrada para este canal en este momento');
        }
    }

    /**
     * Resuelve precio efectivo, valida disponibilidad y modificadores, y deja
     * cada linea lista para que el dominio calcule. Lo comparten la creacion
     * del pedido y la previsualizacion de totales.
     *
     * @param OrderLineInput[] $items
     * @return ResolvedLine[]
     */
    private static function resolveLines(string $tenantId, string $branchId, array $items): array
    {
        $repo = new MenuRepository(Database::app());
        $resolved = [];

        foreach ($items as $line) {
            $item = $repo->getItemWithModifiers($tenantId, $line->menuItemId);
            if ($item === null || $item->isArchived) {
                throw new OrderError("El producto {$line->menuItemId} no existe");
            }

            $override = $repo->getBranchOverride($branchId, $item->id);
            $effective = MenuPricing::resolveEffectiveMenuItem(
                $item->basePriceCents,
                $item->isAvailable,
                $override?->priceCents,
                $override?->isAvailable,
            );
            if (!$effective->isAvailable) {
                throw new OrderError("'{$item->name}' no esta disponible en esta sucursal");
            }

            $modifiersById = [];
            foreach ($item->modifierGroups as $group) {
                foreach ($group->modifiers as $modifier) {
                    $modifiersById[$modifier->id] = $modifier;
                }
            }

            $selectedIds = array_unique($line->modifierIds);
            $unknown = array_diff($selectedIds, array_keys($modifiersById));
            if ($unknown !== []) {
                throw new OrderError("'{$item->name}' no tiene los modificadores " . implode(', ', $unknown));
            }

            foreach ($item->modifierGroups as $group) {
                $groupModifierIds = array_map(static fn ($m) => $m->id, $group->modifiers);
                $selectedInGroup = array_intersect($selectedIds, $groupModifierIds);
                $constraint = new ModifierGroupConstraint(
                    $group->id,
                    $group->name,
                    $group->minSelect,
                    $group->maxSelect,
                    $group->isRequired,
                );
                try {
                    ModifierValidation::validateSelection($constraint, count($selectedInGroup));
                } catch (ModifierValidationError $e) {
                    throw new OrderError("'{$item->name}': {$e->getMessage()}");
                }
            }

            $selectedModifiers = [];
            foreach ($selectedIds as $modifierId) {
                $modifier = $modifiersById[$modifierId];
                if (!$modifier->isAvailable) {
                    throw new OrderError("El modificador '{$modifier->name}' no esta disponible");
                }
                $selectedModifiers[] = $modifier;
            }

            $lineInput = new LineInput(
                quantity: $line->quantity,
                unitPriceCents: $effective->priceCents,
                taxRate: (float) ($item->taxRate ?? 0),
                taxIncludedInPrice: $item->taxIncludedInPrice,
                modifierDeltasCents: array_map(static fn ($m) => $m->priceDeltaCents, $selectedModifiers),
            );

            $resolved[] = new ResolvedLine(
                $item,
                $line,
                $selectedModifiers,
                $lineInput,
                // Un combo se vende como una sola linea al precio del
                // paquete: lo que lleva no entra en la aritmetica, solo se
                // congela para que la cocina sepa que preparar.
                $repo->componentsOf($item->id),
            );
        }

        return $resolved;
    }

    /** @param OrderLineInput[] $items */
    public static function createOrder(
        string $tenantId,
        string $branchId,
        ?string $createdBy,
        string $channel,
        array $items,
        ?string $customerPhone = null,
        ?string $customerName = null,
        ?string $tableCode = null,
        ?string $notes = null,
        ?string $idempotencyKey = null,
        int $deliveryFeeCents = 0,
        int $discountCents = 0,
        int $tipCents = 0,
        ?DeliveryInput $delivery = null,
        ?string $discountReasonId = null,
    ): Order {
        $pdo = Database::app();
        $orders = new OrderRepository($pdo);

        if ($idempotencyKey !== null) {
            $existing = $orders->getByIdempotencyKey($tenantId, $idempotencyKey);
            if ($existing !== null) {
                return $existing;
            }
        }

        if ($items === []) {
            throw new OrderError('El pedido necesita al menos un producto');
        }

        $tenant = (new TenantRepository($pdo))->get($tenantId);
        if ($tenant === null) {
            throw new OrderError('El tenant no existe');
        }

        // Modulo 9: lo que el restaurante puede vender y como sale de su
        // configuracion, no de condicionales por tenant en el codigo.
        $settings = TenantSettings::parse($tenant->settings, $tenant->businessType);
        if (!$settings->allowsChannel($channel)) {
            throw new OrderError(
                "El canal '{$channel}' no esta habilitado. Activos: " . implode(', ', $settings->channels)
            );
        }
        if ($tableCode !== null && !$settings->usesTables) {
            throw new OrderError('Este restaurante no maneja mesas');
        }
        if ($tipCents !== 0 && !$settings->asksTip) {
            throw new OrderError('Este restaurante no recibe propina');
        }

        $branch = (new BranchRepository($pdo))->get($tenantId, $branchId);
        if ($branch === null) {
            throw new OrderError('La sucursal no existe para este tenant');
        }

        self::checkBranchOpen($branch, $channel);

        $initialStatus = (new OrderStatusRepository($pdo))->getInitial($tenantId);
        if ($initialStatus === null) {
            throw new OrderError('El tenant no tiene un estado inicial de pedido configurado');
        }

        $customerId = null;
        if ($customerPhone !== null && $customerPhone !== '') {
            $customerId = (new CustomerRepository($pdo))
                ->getOrCreateByPhone($tenantId, $customerPhone, $customerName)->id;
        }

        $tableId = null;
        if ($tableCode !== null && $tableCode !== '') {
            $table = (new TableRepository($pdo))->getByCode($branchId, $tableCode);
            if ($table === null) {
                throw new OrderError("La mesa '{$tableCode}' no existe en esta sucursal");
            }
            $tableId = $table->id;
        }

        // La tarifa de domicilio la pone el restaurante en su zona, no quien
        // pide: si viene una zona, su tarifa manda sobre lo que llegue en el
        // cuerpo de la peticion.
        $zone = self::resolveZone($tenantId, $delivery?->zoneId);
        if ($zone !== null) {
            $deliveryFeeCents = $zone->feeCents;
        }

        $resolvedLines = self::resolveLines($tenantId, $branchId, $items);

        try {
            $totals = OrderTotalsCalculator::computeTotals(
                array_map(static fn (ResolvedLine $l) => $l->lineInput, $resolvedLines),
                $deliveryFeeCents,
                $discountCents,
                $tipCents,
            );
        } catch (OrderTotalsError $e) {
            throw new OrderError($e->getMessage());
        }

        // El descuento no es un numero mas del cuerpo: necesita motivo y cabe
        // en el tope del rol de quien lo aplica (F7.2). El permiso lo exige
        // el controlador; esto es lo que no depende de la pantalla.
        if ($discountCents > 0) {
            self::validarDescuento($tenantId, $createdBy, $discountCents, $discountReasonId, $totals->subtotalCents);
        }

        // Se valida con los totales ya calculados y antes de escribir nada: el
        // minimo mira el subtotal, no el total (ver Domain\DeliveryRules).
        if ($zone !== null) {
            try {
                DeliveryRules::validateMinimum(
                    $totals->subtotalCents,
                    new DeliveryZoneRules($zone->name, $zone->feeCents, $zone->minOrderCents, $zone->estMinutes),
                );
            } catch (DeliveryError $e) {
                throw new OrderError($e->getMessage());
            }
        }

        $orderId = $orders->create(
            $tenantId,
            $branchId,
            $initialStatus->id,
            $orders->nextOrderNumber($branchId),
            $channel,
            $customerId,
            $tableId,
            $createdBy,
            $idempotencyKey,
            $notes,
            $totals->subtotalCents,
            $totals->taxTotalCents,
            $totals->deliveryFeeCents,
            $totals->discountCents,
            $totals->tipCents,
            $totals->totalCents,
            $discountCents > 0 ? $discountReasonId : null,
            $discountCents > 0 ? $createdBy : null,
        );

        foreach ($resolvedLines as $index => $resolved) {
            self::guardarLinea($orders, $orderId, $resolved, $totals->lines[$index]);
        }

        if ($delivery !== null) {
            (new DeliveryRepository($pdo))->createInfo(
                $orderId,
                $delivery->address,
                $zone?->id,
                $delivery->lat,
                $delivery->lng,
            );
        }

        $orders->addStatusHistory($orderId, $initialStatus->id, $createdBy, 'Pedido creado');

        return $orders->getById($tenantId, $orderId);
    }

    /**
     * Escribe una linea resuelta con su precio, sus modificadores y —si es
     * un combo— lo que llevaba. Lo comparten crear un pedido y agregarle
     * lineas despues: las dos congelan igual.
     */
    private static function guardarLinea(
        OrderRepository $orders,
        string $orderId,
        ResolvedLine $resolved,
        LineResult $lineResult,
    ): string {
        $orderItemId = $orders->addItem(
            $orderId,
            $resolved->item->id,
            $resolved->item->name,
            $resolved->line->quantity,
            $resolved->lineInput->unitPriceCents,
            $resolved->item->taxRate ?? '0',
            $lineResult->taxAmountCents,
            $lineResult->lineTotalCents,
            $resolved->line->notes,
        );
        foreach ($resolved->modifiers as $modifier) {
            $orders->addItemModifier($orderItemId, $modifier->id, $modifier->name, $modifier->priceDeltaCents);
        }
        if ($resolved->components !== []) {
            // Dos combos son dos de cada cosa: multiplica el dominio, no la
            // pantalla ni la consulta.
            $orders->addItemComponents(
                $orderItemId,
                ComboRules::expand($resolved->components, $resolved->line->quantity),
            );
        }
        return $orderItemId;
    }

    /**
     * Lo que ya vale cada linea del pedido, tal como quedo congelada.
     *
     * @param OrderItem[] $items
     * @return LineResult[]
     */
    private static function resultadosCongelados(array $items): array
    {
        return array_map(
            static fn (OrderItem $i) => new LineResult($i->lineTotalCents, $i->taxAmountCents),
            $items,
        );
    }

    /**
     * Abre la edicion de un pedido: lo bloquea, comprueba que todavia se
     * pueda tocar y lo devuelve.
     *
     * El bloqueo es el mismo FOR UPDATE con el que se avanza un estado: dos
     * cajeros editando la misma cuenta leerian el mismo total y el segundo
     * pisaria al primero.
     */
    private static function abrirParaEditar(OrderRepository $orders, string $tenantId, string $orderId): Order
    {
        $order = $orders->getByIdForUpdate($tenantId, $orderId);
        if ($order === null) {
            throw new OrderError('Pedido no encontrado');
        }
        if ($order->status === null) {
            throw new OrderError('El pedido no tiene estado');
        }
        try {
            OrderEditRules::ensureEditable($order->status->category);
        } catch (OrderEditError $e) {
            throw new OrderError($e->getMessage());
        }
        return $order;
    }

    /**
     * Guarda los totales que resultan de la edicion y deja el rastro.
     *
     * @param LineResult[] $resultados lo que vale cada linea que queda
     */
    private static function cerrarEdicion(
        OrderRepository $orders,
        Order $order,
        array $resultados,
        string $nota,
        ?string $userId,
        ?int $descuentoCents = null,
    ): Order {
        $totals = OrderTotalsCalculator::totalsFromLines(
            $resultados,
            $order->deliveryFeeCents,
            // El descuento recien aplicado todavia no esta en $order, que se
            // leyo antes de escribirlo.
            $descuentoCents ?? $order->discountCents,
            $order->tipCents,
        );

        try {
            // El pedido no puede terminar valiendo menos de lo que ya se
            // cobro: eso dejaria plata sin venta que la respalde.
            OrderEditRules::ensureCubreLoCobrado(
                $totals->totalCents,
                PaymentService::getBalanceForOrder($order)->netPaidCents,
            );
        } catch (OrderEditError $e) {
            throw new OrderError($e->getMessage());
        }

        $orders->updateTotals($order->id, $totals->subtotalCents, $totals->taxTotalCents, $totals->totalCents);
        // La bitacora del pedido es la que ya se lee en el detalle: el cambio
        // queda en la misma linea de tiempo que los estados, con su autor.
        $orders->addStatusHistory($order->id, $order->statusId, $userId, mb_substr($nota, 0, 255));

        return $orders->getById($order->tenantId, $order->id);
    }

    /**
     * Comprueba un descuento contra el motivo, la venta y el tope del rol.
     *
     * @return string el nombre del motivo, para la bitacora
     */
    private static function validarDescuento(
        string $tenantId,
        ?string $userId,
        int $discountCents,
        ?string $reasonId,
        int $subtotalCents,
    ): string {
        $reasons = new DiscountReasonRepository(Database::app());

        // Sin usuario no hay rol y no hay tope: es el caso de un cliente de
        // la API sin sesion, y ahi el control es el permiso, no el rol.
        $tope = $userId === null ? null : $reasons->maxPercentForUser($tenantId, $userId);

        try {
            DiscountRules::validate($discountCents, $subtotalCents, $reasonId, $tope);
        } catch (DiscountError $e) {
            throw new OrderError($e->getMessage());
        }

        $nombre = $reasons->nameOf($tenantId, (string) $reasonId);
        if ($nombre === null) {
            throw new OrderError('Ese motivo de descuento no existe o esta desactivado');
        }
        return $nombre;
    }

    /**
     * Aplica —o quita— el descuento de un pedido abierto.
     *
     * Un importe de cero lo quita, y entonces no hace falta motivo: dejar de
     * regalar plata no necesita justificacion. El total se rehace con el
     * mismo camino que cualquier otra edicion.
     */
    public static function applyDiscount(
        string $tenantId,
        string $orderId,
        int $discountCents,
        ?string $reasonId,
        ?string $userId = null,
    ): Order {
        $orders = new OrderRepository(Database::app());
        $order = self::abrirParaEditar($orders, $tenantId, $orderId);

        if ($discountCents === 0) {
            $orders->setDiscount($order->id, 0, null, null);
            return self::cerrarEdicion(
                $orders,
                $order,
                self::resultadosCongelados($order->items),
                'Quito el descuento',
                $userId,
                descuentoCents: 0,
            );
        }

        $motivo = self::validarDescuento($tenantId, $userId, $discountCents, $reasonId, $order->subtotalCents);

        $orders->setDiscount($order->id, $discountCents, $reasonId, $userId);

        return self::cerrarEdicion(
            $orders,
            $order,
            self::resultadosCongelados($order->items),
            DiscountRules::describe($discountCents, $order->subtotalCents, $motivo),
            $userId,
            descuentoCents: $discountCents,
        );
    }

    /**
     * Agrega lineas a un pedido abierto.
     *
     * Las nuevas congelan el precio de hoy —el mismo camino que el alta, con
     * su disponibilidad y sus modificadores validados—; las que ya estaban
     * conservan el suyo. No se vuelve a comprobar el horario de la sucursal:
     * el pedido ya se tomo, y cerrar la cocina no puede dejar una mesa sin
     * poder pedir el postre.
     *
     * @param OrderLineInput[] $items
     */
    public static function addLines(string $tenantId, string $orderId, array $items, ?string $userId = null): Order
    {
        if ($items === []) {
            throw new OrderError('No hay nada que agregar');
        }

        $pdo = Database::app();
        $orders = new OrderRepository($pdo);
        $order = self::abrirParaEditar($orders, $tenantId, $orderId);

        $resueltas = self::resolveLines($tenantId, $order->branchId, $items);
        $nuevas = array_map(
            static fn (ResolvedLine $r) => OrderTotalsCalculator::computeLine($r->lineInput),
            $resueltas,
        );

        foreach ($resueltas as $index => $resuelta) {
            self::guardarLinea($orders, $order->id, $resuelta, $nuevas[$index]);
        }

        $agregado = implode(', ', array_map(
            static fn (ResolvedLine $r) => "{$r->line->quantity}x {$r->item->name}",
            $resueltas,
        ));

        return self::cerrarEdicion(
            $orders,
            $order,
            [...self::resultadosCongelados($order->items), ...$nuevas],
            "Agrego {$agregado}",
            $userId,
        );
    }

    /** Quita una linea del pedido. */
    public static function removeLine(
        string $tenantId,
        string $orderId,
        string $orderItemId,
        ?string $userId = null,
    ): Order {
        $pdo = Database::app();
        $orders = new OrderRepository($pdo);
        $order = self::abrirParaEditar($orders, $tenantId, $orderId);

        $quitada = null;
        $quedan = [];
        foreach ($order->items as $item) {
            if ($item->id === $orderItemId) {
                $quitada = $item;
            } else {
                $quedan[] = $item;
            }
        }
        // Se busca dentro de las lineas del pedido y no por id suelto: una de
        // otro pedido —o de otra empresa— no existe desde aqui.
        if ($quitada === null) {
            throw new OrderError('Esa linea no pertenece a este pedido');
        }

        try {
            OrderEditRules::ensureQuedanLineas(count($quedan));
        } catch (OrderEditError $e) {
            throw new OrderError($e->getMessage());
        }

        $orders->removeItem($quitada->id);

        return self::cerrarEdicion(
            $orders,
            $order,
            self::resultadosCongelados($quedan),
            "Quito {$quitada->quantity}x {$quitada->nameSnapshot}",
            $userId,
        );
    }

    /**
     * Cambia cuantas unidades lleva una linea.
     *
     * El precio unitario congelado no se toca: se recalcula lo que depende de
     * la cantidad —el impuesto y el total de la linea— con el mismo dominio
     * que uso el alta.
     */
    public static function setLineQuantity(
        string $tenantId,
        string $orderId,
        string $orderItemId,
        int $quantity,
        ?string $userId = null,
    ): Order {
        try {
            OrderEditRules::ensureCantidad($quantity);
        } catch (OrderEditError $e) {
            throw new OrderError($e->getMessage());
        }

        $pdo = Database::app();
        $orders = new OrderRepository($pdo);
        $order = self::abrirParaEditar($orders, $tenantId, $orderId);

        $linea = null;
        foreach ($order->items as $item) {
            if ($item->id === $orderItemId) {
                $linea = $item;
            }
        }
        if ($linea === null) {
            throw new OrderError('Esa linea no pertenece a este pedido');
        }
        if ($linea->quantity === $quantity) {
            return $orders->getById($tenantId, $order->id);
        }

        // El impuesto se recalcula con la tarifa que quedo congelada en la
        // linea, no con la de hoy: la tarifa del producto pudo cambiar
        // despues de la venta y el pedido ya se emitio con la suya.
        //
        // Si el impuesto iba dentro del precio tampoco esta guardado, pero se
        // deduce de la propia fila sin adivinar: el total de la linea es el
        // bruto cuando va incluido, y el bruto mas el impuesto cuando se
        // suma aparte.
        $deltas = array_map(static fn ($m) => $m->priceDeltaCents, $linea->modifiers);
        $bruto = ($linea->unitPriceCents + (int) array_sum($deltas)) * $linea->quantity;

        $resultado = OrderTotalsCalculator::computeLine(new LineInput(
            quantity: $quantity,
            unitPriceCents: $linea->unitPriceCents,
            taxRate: (float) $linea->taxRate,
            taxIncludedInPrice: $linea->lineTotalCents === $bruto,
            modifierDeltasCents: $deltas,
        ));

        $orders->setItemQuantity($linea->id, $quantity, $resultado->taxAmountCents, $resultado->lineTotalCents);
        $orders->rescaleItemComponents($linea->id, $linea->quantity, $quantity);

        $resultados = [];
        foreach ($order->items as $item) {
            $resultados[] = $item->id === $linea->id
                ? $resultado
                : new LineResult($item->lineTotalCents, $item->taxAmountCents);
        }

        return self::cerrarEdicion(
            $orders,
            $order,
            $resultados,
            "Cambio {$linea->nameSnapshot} de {$linea->quantity} a {$quantity}",
            $userId,
        );
    }

    /**
     * Totales de un pedido que aun no existe.
     *
     * Existe para que el frontend muestre el total sin recalcularlo: la
     * aritmetica sigue viviendo solo en Domain\OrderTotalsCalculator. No
     * valida canal ni horario, que hacen a si el pedido se puede vender y no
     * a cuanto cuesta.
     *
     * @param OrderLineInput[] $items
     */
    public static function previewTotals(
        string $tenantId,
        string $branchId,
        array $items,
        int $deliveryFeeCents = 0,
        int $discountCents = 0,
        int $tipCents = 0,
        ?string $zoneId = null,
    ): OrderPreview {
        if ($items === []) {
            throw new OrderError('El pedido necesita al menos un producto');
        }

        // Misma cascada que al crear: con zona, la tarifa la pone la zona y
        // lo que venga en el cuerpo se ignora. Si aqui se mostrara el importe
        // tecleado, la caja le diria al cliente un total que el pedido no va
        // a tener.
        $zone = self::resolveZone($tenantId, $zoneId);
        if ($zone !== null) {
            $deliveryFeeCents = $zone->feeCents;
        }

        $resolvedLines = self::resolveLines($tenantId, $branchId, $items);

        try {
            $totals = OrderTotalsCalculator::computeTotals(
                array_map(static fn (ResolvedLine $l) => $l->lineInput, $resolvedLines),
                $deliveryFeeCents,
                $discountCents,
                $tipCents,
            );
        } catch (OrderTotalsError $e) {
            throw new OrderError($e->getMessage());
        }

        // El minimo se avisa, no se rechaza: previsualizar es preguntar
        // cuanto cuesta, y el pedido todavia puede crecer. Quien lo rechaza
        // es createOrder, con la misma regla y el mismo mensaje.
        return new OrderPreview($totals, $zone, self::minimumWarning($totals->subtotalCents, $zone));
    }

    /** La zona del pedido, validada contra el tenant. */
    private static function resolveZone(string $tenantId, ?string $zoneId): ?DeliveryZone
    {
        if ($zoneId === null) {
            return null;
        }
        $zone = (new DeliveryRepository(Database::app()))->getZone($tenantId, $zoneId);
        if ($zone === null) {
            throw new OrderError('La zona de domicilio no existe para este tenant');
        }
        if (!$zone->isActive) {
            throw new OrderError("La zona '{$zone->name}' no esta activa");
        }
        return $zone;
    }

    /** El aviso lo redacta el dominio: la comparacion vive en un solo sitio. */
    private static function minimumWarning(int $subtotalCents, ?DeliveryZone $zone): ?string
    {
        if ($zone === null) {
            return null;
        }
        try {
            DeliveryRules::validateMinimum(
                $subtotalCents,
                new DeliveryZoneRules($zone->name, $zone->feeCents, $zone->minOrderCents, $zone->estMinutes),
            );
            return null;
        } catch (DeliveryError $e) {
            return $e->getMessage();
        }
    }

    public static function getOrder(string $tenantId, string $orderId): ?Order
    {
        return (new OrderRepository(Database::app()))->getById($tenantId, $orderId);
    }

    /**
     * La bitacora del pedido.
     *
     * Pasa por el pedido y no directo a la tabla para no responder la
     * historia de un pedido de otra empresa: el WHERE por tenant lo hace
     * getById, y RLS respalda por debajo.
     *
     * @return \App\Models\OrderStatusEvent[]
     */
    public static function statusHistory(string $tenantId, string $orderId): array
    {
        $pdo = Database::app();
        $order = (new OrderRepository($pdo))->getById($tenantId, $orderId);
        if ($order === null) {
            throw new OrderError('Pedido no encontrado');
        }
        return (new OrderRepository($pdo))->statusHistory($order->id);
    }

    /**
     * Una pagina de la lista de pedidos, con el saldo de cada uno resuelto
     * de una vez.
     *
     * El saldo va aqui y no en una peticion por pedido: la pantalla pedia
     * uno por fila, hasta 51 llamadas para pintarse. La resta sigue siendo
     * de Domain\PaymentBalance, que es la unica fuente de verdad sobre si un
     * pedido esta saldado; lo que cambia es cuantas veces se va a la base.
     */
    public static function listOrders(string $tenantId, string $branchId, OrderListFilters $filters): OrderListPage
    {
        $pdo = Database::app();
        $orders = (new OrderRepository($pdo))->listForBranch($tenantId, $branchId, $filters);

        // El repositorio devuelve una fila de mas justamente para esto: si
        // llego, hay pagina siguiente y el cursor sale del ultimo que si se
        // muestra.
        $hayMas = count($orders) > $filters->limit;
        $orders = array_slice($orders, 0, $filters->limit);
        $nextCursor = $hayMas && $orders !== [] ? OrderCursor::encode($orders[count($orders) - 1]) : null;

        $orderIds = array_map(static fn ($o) => $o->id, $orders);
        $paid = (new PaymentRepository($pdo))->paidTotalsForOrders($orderIds);

        $balances = [];
        foreach ($orders as $order) {
            $movimientos = $paid[$order->id] ?? ['paid' => 0, 'refunded' => 0];
            $balances[$order->id] = PaymentBalance::compute(
                $order->totalCents,
                [$movimientos['paid']],
                [$movimientos['refunded']],
            );
        }

        // La maquina de estados se arma una vez para toda la pagina, no una
        // por pedido: es la misma para todo el tenant.
        $nextStatuses = [];
        if ($filters->withNextStatuses && $orders !== []) {
            [$machine, $byId] = OrderStatusService::buildMachine($tenantId);
            foreach ($orders as $order) {
                $nextStatuses[$order->id] = array_map(
                    static fn ($s) => $byId[$s->id],
                    $machine->allowedFrom($order->statusId),
                );
            }
        }

        return new OrderListPage(
            $orders,
            $balances,
            $nextCursor,
            (new DeliveryRepository($pdo))->getForOrders($orderIds),
            $nextStatuses,
        );
    }
}
