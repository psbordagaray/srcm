<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Operator = 'operator';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrador',
            self::Operator => 'Operador',
            self::Viewer => 'Consulta',
        };
    }

    public function canManageCatalog(): bool
    {
        return match ($this) {
            self::Admin,
            self::Operator => true,
            self::Viewer => false,
        };
    }

    public function canManageCommerce(): bool
    {
        return match ($this) {
            self::Admin,
            self::Operator => true,
            self::Viewer => false,
        };
    }

    public function canManageOrganization(): bool
    {
        return $this === self::Admin;
    }

    public function canViewInventory(): bool
    {
        return true;
    }

    public function canViewInventoryAvailability(): bool
    {
        return $this->canViewInventory();
    }

    public function canManageInventoryLocations(): bool
    {
        return $this === self::Admin;
    }

    public function canReceiveInventory(): bool
    {
        return $this !== self::Viewer;
    }

    public function canIssueInventory(): bool
    {
        return $this !== self::Viewer;
    }

    public function canTransferInventory(): bool
    {
        return $this !== self::Viewer;
    }

    public function canProcessInventoryReturns(): bool
    {
        return $this !== self::Viewer;
    }

    public function canAdjustInventory(): bool
    {
        return $this === self::Admin;
    }

    public function canCorrectInventory(): bool
    {
        return $this === self::Admin;
    }

    public function canRebuildInventory(): bool
    {
        return $this === self::Admin;
    }

    public function canRequestInventoryNegative(): bool
    {
        return $this !== self::Viewer;
    }

    public function canOverrideInventoryNegative(): bool
    {
        return $this === self::Admin;
    }

    public function canViewInventoryNegativeIncidents(): bool
    {
        return $this === self::Admin;
    }

    public function canReviewInventoryNegativeIncidents(): bool
    {
        return $this === self::Admin;
    }

    public function canDraftInventoryMovement(
        InventoryMovementType $type
    ): bool {
        return match ($type) {
            InventoryMovementType::Receipt =>
                $this->canReceiveInventory(),
            InventoryMovementType::Issue =>
                $this->canIssueInventory(),
            InventoryMovementType::Transfer =>
                $this->canTransferInventory(),
            InventoryMovementType::CustomerReturn,
            InventoryMovementType::SupplierReturn =>
                $this->canProcessInventoryReturns(),
            InventoryMovementType::InitialBalance,
            InventoryMovementType::PositiveAdjustment,
            InventoryMovementType::NegativeAdjustment =>
                $this->canAdjustInventory(),
            InventoryMovementType::Reversal => false,
        };
    }

    public function canConfirmInventoryMovement(
        InventoryMovementType $type
    ): bool {
        if ($type === InventoryMovementType::Reversal) {
            return $this->canCorrectInventory();
        }

        return $this->canDraftInventoryMovement($type);
    }

    public function canViewAudit(): bool
    {
        return $this === self::Admin;
    }
}
