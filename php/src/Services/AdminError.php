<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Security;
use App\Domain\SettingsError;
use App\Domain\TenantSettings;
use App\Models\Branch;
use App\Models\Role;
use App\Models\Table;
use App\Models\TaxRate;
use App\Models\Tenant;
use App\Models\User;
use App\Repositories\BranchRepository;
use App\Repositories\RoleRepository;
use App\Repositories\TableRepository;
use App\Repositories\TaxRateRepository;
use App\Repositories\TenantRepository;
use App\Repositories\UserRepository;
use PDO;

final class AdminError extends \RuntimeException
{
}
