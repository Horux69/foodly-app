<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/** Como imprime cada sucursal. Sin fila, el dominio pone los valores por defecto. */
final class PrintProfileRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string, array{width_mm: int, copies: int}> por documento */
    public function forBranch(string $branchId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT document, width_mm, copies FROM print_profiles WHERE branch_id = :branch_id'
        );
        $stmt->execute(['branch_id' => $branchId]);

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[$row['document']] = ['width_mm' => (int) $row['width_mm'], 'copies' => (int) $row['copies']];
        }
        return $result;
    }

    public function upsert(string $branchId, string $document, int $widthMm, int $copies): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO print_profiles (branch_id, document, width_mm, copies)
             VALUES (:branch_id, :document, :width, :copies)
             ON CONFLICT (branch_id, document)
             DO UPDATE SET width_mm = EXCLUDED.width_mm, copies = EXCLUDED.copies'
        );
        $stmt->execute([
            'branch_id' => $branchId,
            'document' => $document,
            'width' => $widthMm,
            'copies' => $copies,
        ]);
    }
}
