<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Domain\PaymentBalance;
use App\Models\Order;
use App\Models\Payment;
use App\Repositories\OrderRepository;
use App\Repositories\PaymentRepository;

final class PaymentError extends \RuntimeException
{
}
