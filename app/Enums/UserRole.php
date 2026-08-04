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

    public function canRecordCommerceSale(): bool
    {
        return $this->canManageCommerce();
    }

    public function canViewCommerceSales(): bool
    {
        return true;
    }

    public function canViewServiceOrders(): bool
    {
        return true;
    }

    public function canCreateServiceOrders(): bool
    {
        return $this !== self::Viewer;
    }

    public function canManageServiceOrders(): bool
    {
        return $this !== self::Viewer;
    }

    public function canRecordServiceDiagnostics(): bool
    {
        return $this->canManageServiceOrders();
    }

    public function canIssueServiceQuotes(): bool
    {
        return $this->canManageServiceOrders();
    }

    public function canRecordServiceQuoteDecisions(): bool
    {
        return $this->canManageServiceOrders();
    }

    public function canPlanServiceWork(): bool
    {
        return $this->canManageServiceOrders();
    }

    public function canExecuteServiceWork(): bool
    {
        return $this->canManageServiceOrders();
    }

    public function canTransferServiceCustody(): bool
    {
        return $this->canManageServiceOrders();
    }

    public function canPlanServiceParts(): bool
    {
        return $this->canManageServiceOrders();
    }

    public function canRecordServicePartPurchases(): bool
    {
        return $this->canManageServiceOrders();
    }

    public function canConsumeServiceParts(): bool
    {
        return $this->canManageServiceOrders();
    }

    public function canInspectServiceQuality(): bool
    {
        return $this->canManageServiceOrders();
    }

    public function canDeliverServiceOrders(): bool
    {
        return $this->canManageServiceOrders();
    }

    public function canCancelServiceOrders(): bool
    {
        return $this === self::Admin;
    }

    public function canRequestServiceCancellation(): bool
    {
        return $this !== self::Viewer;
    }

    public function canResolveServiceCancellation(): bool
    {
        return $this->canCancelServiceOrders();
    }

    public function canReturnCancelledServiceOrder(): bool
    {
        return $this->canDeliverServiceOrders();
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

    public function canViewInventoryNegativeAuthorizations(): bool
    {
        return $this->canRequestInventoryNegative()
            || $this->canOverrideInventoryNegative();
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

    public function canDraftAnyInventoryMovement(): bool
    {
        foreach (InventoryMovementType::cases() as $type) {
            if ($this->canDraftInventoryMovement($type)) {
                return true;
            }
        }

        return false;
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
