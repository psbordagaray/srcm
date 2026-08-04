<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var list<string> */
    private const CORE_TRIGGERS = [
        'srv_orders_guard_update',
        'srv_work_items_guard_insert',
        'srv_work_items_guard_update',
        'srv_work_items_guard_delete',
        'srv_part_req_guard_insert',
        'srv_part_req_guard_update',
        'srv_part_req_guard_delete',
        'srv_warranty_block_cancel_insert',
        'srv_warranty_claims_guard_insert',
        'srv_warranty_claims_guard_update',
        'srv_warranty_claims_guard_delete',
        'srv_warranty_claim_hist_guard_insert',
        'srv_warranty_claim_hist_guard_update',
        'srv_warranty_claim_hist_guard_delete',
        'srv_warranty_res_guard_insert',
        'srv_warranty_res_guard_update',
        'srv_warranty_res_guard_delete',
        'srv_warranty_return_guard_insert',
        'srv_warranty_return_guard_update',
        'srv_warranty_return_guard_delete',
    ];

    public function up(): void
    {
        $this->dropTriggers();

        if (DB::getDriverName() === 'sqlite') {
            $this->createSqliteTriggers();

            return;
        }

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->createMysqlTriggers();

            return;
        }

        throw new LogicException(
            'La integridad de reclamos de garantía no está implementada para '
                .DB::getDriverName().'.'
        );
    }

    public function down(): void
    {
        $this->dropTriggers();

        if (DB::getDriverName() === 'sqlite') {
            $this->restoreSqlitePreWarrantyTriggers();

            return;
        }

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->restoreMysqlPreWarrantyTriggers();

            return;
        }

        throw new LogicException(
            'La restauración previa a reclamos de garantía no está '
                .'implementada para '.DB::getDriverName().'.'
        );
    }

    private function dropTriggers(): void
    {
        foreach (self::CORE_TRIGGERS as $trigger) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
        }
    }

    private function createSqliteTriggers(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER srv_orders_guard_update
BEFORE UPDATE ON service_orders
WHEN OLD.organization_id <> NEW.organization_id
    OR OLD.public_id <> NEW.public_id
    OR OLD.order_number <> NEW.order_number
    OR OLD.service_asset_id <> NEW.service_asset_id
    OR OLD.customer_business_party_id IS NOT NEW.customer_business_party_id
    OR OLD.owner_business_party_id IS NOT NEW.owner_business_party_id
    OR OLD.intake_location_id <> NEW.intake_location_id
    OR OLD.created_by_user_id <> NEW.created_by_user_id
    OR OLD.received_at <> NEW.received_at
    OR OLD.promised_at IS NOT NEW.promised_at
    OR OLD.idempotency_key <> NEW.idempotency_key
    OR OLD.metadata IS NOT NEW.metadata
    OR (
        OLD.status <> NEW.status
        AND (
            NOT (
                (OLD.status = 'received' AND NEW.status = 'diagnosing')
                OR (
                    OLD.status = 'received'
                    AND NEW.status = 'in_progress'
                    AND EXISTS (
                        SELECT 1
                        FROM service_warranty_claims claim
                        INNER JOIN service_warranty_claim_resolutions resolution
                            ON resolution.organization_id = claim.organization_id
                            AND resolution.service_warranty_claim_id = claim.id
                        WHERE claim.organization_id = NEW.organization_id
                            AND claim.corrective_service_order_id = NEW.id
                            AND claim.status = 'in_corrective_work'
                            AND resolution.outcome IN (
                                'accepted',
                                'partially_accepted'
                            )
                    )
                )
                OR (
                    OLD.status = 'received'
                    AND NEW.status = 'ready_for_return'
                    AND EXISTS (
                        SELECT 1
                        FROM service_warranty_claims claim
                        INNER JOIN service_warranty_claim_resolutions resolution
                            ON resolution.organization_id = claim.organization_id
                            AND resolution.service_warranty_claim_id = claim.id
                        WHERE claim.organization_id = NEW.organization_id
                            AND claim.corrective_service_order_id = NEW.id
                            AND claim.status = 'ready_for_return'
                            AND resolution.outcome = 'rejected'
                    )
                )
                OR (OLD.status = 'diagnosing' AND NEW.status = 'awaiting_approval')
                OR (OLD.status = 'awaiting_approval' AND NEW.status = 'in_progress')
                OR (OLD.status = 'awaiting_approval' AND NEW.status = 'diagnosing')
                OR (OLD.status = 'in_progress' AND NEW.status = 'with_external_provider')
                OR (OLD.status = 'with_external_provider' AND NEW.status = 'in_progress')
                OR (OLD.status = 'in_progress' AND NEW.status = 'quality_control')
                OR (OLD.status = 'in_progress' AND NEW.status = 'diagnosing')
                OR (OLD.status = 'in_progress' AND NEW.status = 'awaiting_parts')
                OR (OLD.status = 'awaiting_parts' AND NEW.status = 'in_progress')
                OR (OLD.status = 'quality_control' AND NEW.status = 'in_progress')
                OR (OLD.status = 'quality_control' AND NEW.status = 'ready_for_delivery')
                OR (OLD.status = 'ready_for_delivery' AND NEW.status = 'delivered')
                OR (
                    OLD.status NOT IN (
                        'delivered',
                        'cancellation_pending',
                        'ready_for_return',
                        'cancelled'
                    )
                    AND NEW.status = 'cancellation_pending'
                    AND NOT EXISTS (
                        SELECT 1
                        FROM service_warranty_claims claim
                        WHERE claim.organization_id = NEW.organization_id
                            AND claim.corrective_service_order_id = NEW.id
                    )
                )
                OR (OLD.status = 'cancellation_pending' AND NEW.status = 'ready_for_return')
                OR (OLD.status = 'ready_for_return' AND NEW.status = 'cancelled')
            )
            OR NOT EXISTS (
                SELECT 1
                FROM service_order_status_histories history
                WHERE history.organization_id = NEW.organization_id
                    AND history.service_order_id = NEW.id
                    AND history.from_status = OLD.status
                    AND history.to_status = NEW.status
                    AND history.id = (
                        SELECT MAX(latest.id)
                        FROM service_order_status_histories latest
                        WHERE latest.organization_id = NEW.organization_id
                            AND latest.service_order_id = NEW.id
                    )
            )
            OR (
                OLD.status = 'quality_control'
                AND NEW.status = 'ready_for_delivery'
                AND NOT EXISTS (
                    SELECT 1
                    FROM service_quality_inspections quality
                    WHERE quality.organization_id = NEW.organization_id
                        AND quality.service_order_id = NEW.id
                        AND quality.outcome = 'approved'
                        AND quality.id = (
                            SELECT MAX(latest.id)
                            FROM service_quality_inspections latest
                            WHERE latest.organization_id = NEW.organization_id
                                AND latest.service_order_id = NEW.id
                        )
                )
            )
            OR (
                OLD.status = 'quality_control'
                AND NEW.status = 'in_progress'
                AND NOT EXISTS (
                    SELECT 1
                    FROM service_quality_inspections quality
                    WHERE quality.organization_id = NEW.organization_id
                        AND quality.service_order_id = NEW.id
                        AND quality.outcome = 'rework_required'
                        AND quality.id = (
                            SELECT MAX(latest.id)
                            FROM service_quality_inspections latest
                            WHERE latest.organization_id = NEW.organization_id
                                AND latest.service_order_id = NEW.id
                        )
                )
            )
            OR (
                OLD.status = 'ready_for_delivery'
                AND NEW.status = 'delivered'
                AND (
                    NOT EXISTS (
                        SELECT 1
                        FROM service_deliveries delivery
                        WHERE delivery.organization_id = NEW.organization_id
                            AND delivery.service_order_id = NEW.id
                    )
                    OR EXISTS (
                        SELECT 1
                        FROM service_work_reports report
                        INNER JOIN service_work_items work
                            ON work.id = report.service_work_item_id
                            AND work.organization_id = report.organization_id
                        WHERE work.organization_id = NEW.organization_id
                            AND work.service_order_id = NEW.id
                            AND report.outcome = 'completed'
                            AND report.warranty_days > 0
                            AND NOT EXISTS (
                                SELECT 1
                                FROM service_warranty_grants warranty
                                WHERE warranty.organization_id = report.organization_id
                                    AND warranty.service_work_report_id = report.id
                            )
                    )
                    OR EXISTS (
                        SELECT 1
                        FROM service_warranty_claims claim
                        WHERE claim.organization_id = NEW.organization_id
                            AND claim.corrective_service_order_id = NEW.id
                            AND claim.status <> 'closed'
                    )
                )
            )
            OR (
                NEW.status = 'cancellation_pending'
                AND NOT EXISTS (
                    SELECT 1
                    FROM service_cancellation_requests request
                    WHERE request.organization_id = NEW.organization_id
                        AND request.service_order_id = NEW.id
                        AND request.order_status_snapshot = OLD.status
                )
            )
            OR (
                OLD.status = 'cancellation_pending'
                AND NEW.status = 'ready_for_return'
                AND NOT EXISTS (
                    SELECT 1
                    FROM service_cancellation_resolutions resolution
                    INNER JOIN service_cancellation_requests request
                        ON request.id = resolution.service_cancellation_request_id
                        AND request.organization_id = resolution.organization_id
                    WHERE request.organization_id = NEW.organization_id
                        AND request.service_order_id = NEW.id
                )
            )
            OR (
                OLD.status = 'ready_for_return'
                AND NEW.status = 'cancelled'
                AND NOT EXISTS (
                    SELECT 1
                    FROM service_cancellation_returns cancellation_return
                    WHERE cancellation_return.organization_id = NEW.organization_id
                        AND cancellation_return.service_order_id = NEW.id
                    UNION ALL
                    SELECT 1
                    FROM service_warranty_claim_returns warranty_return
                    WHERE warranty_return.organization_id = NEW.organization_id
                        AND warranty_return.corrective_service_order_id = NEW.id
                )
            )
        )
    )
BEGIN
    SELECT RAISE(ABORT, 'La transición de la orden no es válida o carece de evidencia.');
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER srv_work_items_guard_insert
BEFORE INSERT ON service_work_items
WHEN NEW.status <> 'planned'
    OR NEW.execution_mode NOT IN ('internal', 'external')
    OR (
        NEW.execution_mode = 'internal'
        AND (
            NEW.assigned_user_id IS NULL
            OR NEW.provider_business_party_id IS NOT NULL
            OR NOT EXISTS (
                SELECT 1
                FROM organization_memberships membership
                WHERE membership.organization_id = NEW.organization_id
                    AND membership.user_id = NEW.assigned_user_id
                    AND membership.active = 1
            )
        )
    )
    OR (
        NEW.execution_mode = 'external'
        AND (
            NEW.provider_business_party_id IS NULL
            OR NEW.assigned_user_id IS NOT NULL
        )
    )
    OR (
        (NEW.service_quote_option_id IS NULL)
        = (NEW.service_warranty_claim_resolution_id IS NULL)
    )
    OR (
        NEW.service_quote_option_id IS NOT NULL
        AND NOT EXISTS (
            SELECT 1
            FROM service_quote_options quote_option
            INNER JOIN service_quotes quote
                ON quote.id = quote_option.service_quote_id
                AND quote.organization_id = quote_option.organization_id
            INNER JOIN service_quote_decisions decision
                ON decision.service_quote_id = quote.id
                AND decision.organization_id = quote.organization_id
                AND decision.service_quote_option_id = quote_option.id
            WHERE quote_option.id = NEW.service_quote_option_id
                AND quote_option.organization_id = NEW.organization_id
                AND quote.service_order_id = NEW.service_order_id
                AND decision.decision = 'approved'
        )
    )
    OR (
        NEW.service_warranty_claim_resolution_id IS NOT NULL
        AND NOT EXISTS (
            SELECT 1
            FROM service_warranty_claim_resolutions resolution
            INNER JOIN service_warranty_claims claim
                ON claim.organization_id = resolution.organization_id
                AND claim.id = resolution.service_warranty_claim_id
            INNER JOIN service_orders service_order
                ON service_order.organization_id = claim.organization_id
                AND service_order.id = claim.corrective_service_order_id
            WHERE resolution.organization_id = NEW.organization_id
                AND resolution.id = NEW.service_warranty_claim_resolution_id
                AND claim.corrective_service_order_id = NEW.service_order_id
                AND claim.status = 'in_corrective_work'
                AND resolution.outcome IN ('accepted', 'partially_accepted')
                AND service_order.status = 'in_progress'
        )
    )
BEGIN
    SELECT RAISE(ABORT, 'El trabajo no coincide con su autorización o responsable.');
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER srv_work_items_guard_update
BEFORE UPDATE ON service_work_items
WHEN OLD.organization_id <> NEW.organization_id
    OR OLD.service_order_id <> NEW.service_order_id
    OR OLD.service_quote_option_id IS NOT NEW.service_quote_option_id
    OR OLD.service_warranty_claim_resolution_id
        IS NOT NEW.service_warranty_claim_resolution_id
    OR OLD.sequence <> NEW.sequence
    OR OLD.title <> NEW.title
    OR OLD.description <> NEW.description
    OR OLD.execution_mode <> NEW.execution_mode
    OR OLD.provider_business_party_id IS NOT NEW.provider_business_party_id
    OR OLD.assigned_user_id IS NOT NEW.assigned_user_id
    OR OLD.created_by_user_id <> NEW.created_by_user_id
    OR OLD.planned_at <> NEW.planned_at
    OR OLD.idempotency_key <> NEW.idempotency_key
    OR OLD.fingerprint <> NEW.fingerprint
    OR (
        OLD.status <> NEW.status
        AND (
            NOT (
                (OLD.status = 'planned' AND NEW.status = 'in_progress')
                OR (OLD.status = 'planned' AND NEW.status = 'with_provider')
                OR (OLD.status = 'with_provider' AND NEW.status = 'in_progress')
                OR (OLD.status = 'in_progress' AND NEW.status = 'completed')
                OR (OLD.status = 'in_progress' AND NEW.status = 'unresolved')
                OR (
                    OLD.status IN ('planned', 'in_progress', 'with_provider')
                    AND NEW.status = 'cancelled'
                    AND EXISTS (
                        SELECT 1
                        FROM service_orders orders
                        INNER JOIN service_cancellation_requests request
                            ON request.service_order_id = orders.id
                            AND request.organization_id = orders.organization_id
                        WHERE orders.id = NEW.service_order_id
                            AND orders.organization_id = NEW.organization_id
                            AND orders.status = 'cancellation_pending'
                    )
                )
            )
            OR NOT EXISTS (
                SELECT 1
                FROM service_work_status_histories history
                WHERE history.organization_id = NEW.organization_id
                    AND history.service_work_item_id = NEW.id
                    AND history.from_status = OLD.status
                    AND history.to_status = NEW.status
                    AND history.id = (
                        SELECT MAX(latest.id)
                        FROM service_work_status_histories latest
                        WHERE latest.organization_id = NEW.organization_id
                            AND latest.service_work_item_id = NEW.id
                    )
            )
        )
    )
BEGIN
    SELECT RAISE(ABORT, 'El trabajo es inmutable o su transición carece de historia.');
END
SQL);

        $this->sqliteImmutable(
            'srv_work_items',
            'service_work_items',
            update: false
        );

        DB::unprepared(<<<'SQL'
CREATE TRIGGER srv_part_req_guard_insert
BEFORE INSERT ON service_part_requirements
WHEN NEW.source NOT IN ('stock', 'direct_purchase')
    OR NEW.condition NOT IN ('new', 'used', 'refurbished', 'damaged', 'display')
    OR NEW.required_quantity <= 0
    OR (
        (NEW.service_quote_line_id IS NULL)
        = (NEW.service_warranty_claim_resolution_id IS NULL)
    )
    OR (
        NEW.service_quote_line_id IS NOT NULL
        AND NOT EXISTS (
            SELECT 1
            FROM service_work_items work_item
            INNER JOIN service_orders service_order
                ON service_order.organization_id = work_item.organization_id
                AND service_order.id = work_item.service_order_id
            INNER JOIN service_quote_lines quote_line
                ON quote_line.organization_id = work_item.organization_id
                AND quote_line.service_quote_option_id = work_item.service_quote_option_id
            INNER JOIN catalog_products product
                ON product.id = NEW.catalog_product_id
            WHERE work_item.organization_id = NEW.organization_id
                AND work_item.id = NEW.service_work_item_id
                AND service_order.id = NEW.service_order_id
                AND service_order.status IN ('in_progress', 'awaiting_parts')
                AND work_item.status IN ('planned', 'in_progress')
                AND quote_line.id = NEW.service_quote_line_id
                AND quote_line.line_type = 'part'
                AND quote_line.quantity = NEW.required_quantity
                AND product.active = 1
                AND product.base_unit_code = NEW.base_unit_code
                AND NEW.required_quantity = ROUND(
                    NEW.required_quantity,
                    product.quantity_scale
                )
        )
    )
    OR (
        NEW.service_warranty_claim_resolution_id IS NOT NULL
        AND NOT EXISTS (
            SELECT 1
            FROM service_work_items work_item
            INNER JOIN service_orders service_order
                ON service_order.organization_id = work_item.organization_id
                AND service_order.id = work_item.service_order_id
            INNER JOIN service_warranty_claim_resolutions resolution
                ON resolution.organization_id = work_item.organization_id
                AND resolution.id = work_item.service_warranty_claim_resolution_id
            INNER JOIN service_warranty_claims claim
                ON claim.organization_id = resolution.organization_id
                AND claim.id = resolution.service_warranty_claim_id
            INNER JOIN catalog_products product
                ON product.id = NEW.catalog_product_id
            WHERE work_item.organization_id = NEW.organization_id
                AND work_item.id = NEW.service_work_item_id
                AND service_order.id = NEW.service_order_id
                AND service_order.status IN ('in_progress', 'awaiting_parts')
                AND work_item.status IN ('planned', 'in_progress')
                AND work_item.service_quote_option_id IS NULL
                AND resolution.id = NEW.service_warranty_claim_resolution_id
                AND resolution.outcome IN ('accepted', 'partially_accepted')
                AND claim.corrective_service_order_id = service_order.id
                AND claim.status = 'in_corrective_work'
                AND product.active = 1
                AND product.base_unit_code = NEW.base_unit_code
                AND NEW.required_quantity = ROUND(
                    NEW.required_quantity,
                    product.quantity_scale
                )
        )
    )
BEGIN
    SELECT RAISE(ABORT, 'El repuesto no coincide con su autorización.');
END
SQL);

        $this->sqliteImmutable(
            'srv_part_req',
            'service_part_requirements'
        );

        DB::unprepared(<<<'SQL'
CREATE TRIGGER srv_warranty_block_cancel_insert
BEFORE INSERT ON service_cancellation_requests
WHEN EXISTS (
    SELECT 1
    FROM service_warranty_claims claim
    WHERE claim.organization_id = NEW.organization_id
        AND claim.corrective_service_order_id = NEW.service_order_id
)
BEGIN
    SELECT RAISE(ABORT, 'Una orden correctiva de garantía no admite cancelación genérica.');
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER srv_warranty_claims_guard_insert
BEFORE INSERT ON service_warranty_claims
WHEN TRIM(NEW.public_id) = ''
    OR TRIM(NEW.claimant_name) = ''
    OR TRIM(NEW.channel) = ''
    OR TRIM(NEW.reported_issue) = ''
    OR TRIM(NEW.reentry_condition_notes) = ''
    OR TRIM(NEW.accessories_snapshot) = ''
    OR TRIM(NEW.idempotency_key) = ''
    OR LENGTH(NEW.fingerprint) <> 64
    OR NEW.status <> 'pending_review'
    OR NEW.open_warranty_grant_id IS NOT NEW.service_warranty_grant_id
    OR NEW.closed_at IS NOT NULL
    OR julianday(NEW.received_at) < julianday(NEW.claimed_at)
    OR NOT EXISTS (
        SELECT 1
        FROM service_warranty_grants warranty
        INNER JOIN service_deliveries delivery
            ON delivery.organization_id = warranty.organization_id
            AND delivery.id = warranty.service_delivery_id
        INNER JOIN service_orders original_order
            ON original_order.organization_id = delivery.organization_id
            AND original_order.id = delivery.service_order_id
        INNER JOIN service_orders corrective_order
            ON corrective_order.organization_id = original_order.organization_id
            AND corrective_order.id = NEW.corrective_service_order_id
        INNER JOIN service_order_intakes corrective_intake
            ON corrective_intake.organization_id = corrective_order.organization_id
            AND corrective_intake.service_order_id = corrective_order.id
        WHERE warranty.organization_id = NEW.organization_id
            AND warranty.id = NEW.service_warranty_grant_id
            AND warranty.id = NEW.open_warranty_grant_id
            AND delivery.id = NEW.original_service_delivery_id
            AND original_order.id = NEW.original_service_order_id
            AND original_order.status = 'delivered'
            AND corrective_order.status = 'received'
            AND corrective_order.id <> original_order.id
            AND corrective_order.service_asset_id = original_order.service_asset_id
            AND julianday(NEW.claimed_at) >= julianday(warranty.starts_at)
            AND NEW.warranty_status_at_claim = CASE
                WHEN julianday(NEW.claimed_at) <= julianday(warranty.expires_at)
                    THEN 'active'
                ELSE 'expired'
            END
    )
    OR (
        NEW.claimant_business_party_id IS NOT NULL
        AND NOT EXISTS (
            SELECT 1
            FROM business_parties party
            WHERE party.organization_id = NEW.organization_id
                AND party.id = NEW.claimant_business_party_id
        )
    )
    OR NOT EXISTS (
        SELECT 1
        FROM inventory_locations location
        WHERE location.organization_id = NEW.organization_id
            AND location.id = NEW.intake_location_id
            AND location.active = 1
    )
BEGIN
    SELECT RAISE(ABORT, 'El reclamo de garantía no coincide con la entrega original.');
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER srv_warranty_claims_guard_update
BEFORE UPDATE ON service_warranty_claims
WHEN OLD.organization_id <> NEW.organization_id
    OR OLD.public_id <> NEW.public_id
    OR OLD.service_warranty_grant_id <> NEW.service_warranty_grant_id
    OR OLD.original_service_order_id <> NEW.original_service_order_id
    OR OLD.original_service_delivery_id <> NEW.original_service_delivery_id
    OR OLD.corrective_service_order_id <> NEW.corrective_service_order_id
    OR OLD.claimant_business_party_id IS NOT NEW.claimant_business_party_id
    OR OLD.claimant_name <> NEW.claimant_name
    OR OLD.channel <> NEW.channel
    OR OLD.customer_reference IS NOT NEW.customer_reference
    OR OLD.reported_issue <> NEW.reported_issue
    OR OLD.reentry_condition_notes <> NEW.reentry_condition_notes
    OR OLD.accessories_snapshot <> NEW.accessories_snapshot
    OR OLD.warranty_status_at_claim <> NEW.warranty_status_at_claim
    OR OLD.claimed_at <> NEW.claimed_at
    OR OLD.received_at <> NEW.received_at
    OR OLD.received_by_user_id <> NEW.received_by_user_id
    OR OLD.intake_location_id <> NEW.intake_location_id
    OR OLD.idempotency_key <> NEW.idempotency_key
    OR OLD.fingerprint <> NEW.fingerprint
    OR (
        OLD.status <> NEW.status
        AND (
            NOT (
                (OLD.status = 'pending_review' AND NEW.status IN (
                    'accepted',
                    'partially_accepted',
                    'rejected'
                ))
                OR (OLD.status IN ('accepted', 'partially_accepted')
                    AND NEW.status = 'in_corrective_work')
                OR (OLD.status = 'rejected'
                    AND NEW.status = 'ready_for_return')
                OR (OLD.status IN ('in_corrective_work', 'ready_for_return')
                    AND NEW.status = 'closed')
            )
            OR NOT EXISTS (
                SELECT 1
                FROM service_warranty_claim_status_histories history
                WHERE history.organization_id = NEW.organization_id
                    AND history.service_warranty_claim_id = NEW.id
                    AND history.from_status = OLD.status
                    AND history.to_status = NEW.status
                    AND history.id = (
                        SELECT MAX(latest.id)
                        FROM service_warranty_claim_status_histories latest
                        WHERE latest.organization_id = NEW.organization_id
                            AND latest.service_warranty_claim_id = NEW.id
                    )
            )
        )
    )
    OR (
        NEW.status = 'closed'
        AND (
            NEW.open_warranty_grant_id IS NOT NULL
            OR NEW.closed_at IS NULL
            OR (
                OLD.status = 'in_corrective_work'
                AND NOT EXISTS (
                    SELECT 1
                    FROM service_deliveries delivery
                    WHERE delivery.organization_id = NEW.organization_id
                        AND delivery.service_order_id = NEW.corrective_service_order_id
                )
            )
            OR (
                OLD.status = 'ready_for_return'
                AND NOT EXISTS (
                    SELECT 1
                    FROM service_warranty_claim_returns warranty_return
                    WHERE warranty_return.organization_id = NEW.organization_id
                        AND warranty_return.service_warranty_claim_id = NEW.id
                )
            )
        )
    )
    OR (
        NEW.status <> 'closed'
        AND (
            NEW.open_warranty_grant_id IS NOT NEW.service_warranty_grant_id
            OR NEW.closed_at IS NOT NULL
        )
    )
    OR (
        NEW.status IN ('accepted', 'partially_accepted', 'in_corrective_work')
        AND NOT EXISTS (
            SELECT 1
            FROM service_warranty_claim_resolutions resolution
            WHERE resolution.organization_id = NEW.organization_id
                AND resolution.service_warranty_claim_id = NEW.id
                AND resolution.outcome IN ('accepted', 'partially_accepted')
        )
    )
    OR (
        NEW.status IN ('rejected', 'ready_for_return')
        AND NOT EXISTS (
            SELECT 1
            FROM service_warranty_claim_resolutions resolution
            WHERE resolution.organization_id = NEW.organization_id
                AND resolution.service_warranty_claim_id = NEW.id
                AND resolution.outcome = 'rejected'
        )
    )
BEGIN
    SELECT RAISE(ABORT, 'El reclamo es inmutable o su transición carece de evidencia.');
END
SQL);

        $this->sqliteImmutable(
            'srv_warranty_claims',
            'service_warranty_claims',
            update: false
        );

        DB::unprepared(<<<'SQL'
CREATE TRIGGER srv_warranty_claim_hist_guard_insert
BEFORE INSERT ON service_warranty_claim_status_histories
WHEN NEW.to_status NOT IN (
        'pending_review',
        'accepted',
        'partially_accepted',
        'rejected',
        'in_corrective_work',
        'ready_for_return',
        'closed'
    )
    OR TRIM(NEW.reason) = ''
    OR TRIM(NEW.idempotency_key) = ''
    OR LENGTH(NEW.fingerprint) <> 64
    OR NOT EXISTS (
        SELECT 1
        FROM service_warranty_claims claim
        WHERE claim.organization_id = NEW.organization_id
            AND claim.id = NEW.service_warranty_claim_id
            AND (
                (
                    NEW.from_status IS NULL
                    AND NEW.to_status = 'pending_review'
                    AND claim.status = 'pending_review'
                    AND NOT EXISTS (
                        SELECT 1
                        FROM service_warranty_claim_status_histories existing
                        WHERE existing.organization_id = NEW.organization_id
                            AND existing.service_warranty_claim_id = NEW.service_warranty_claim_id
                    )
                )
                OR (
                    NEW.from_status = claim.status
                    AND (
                        (NEW.from_status = 'pending_review'
                            AND NEW.to_status IN (
                                'accepted',
                                'partially_accepted',
                                'rejected'
                            ))
                        OR (NEW.from_status IN (
                                'accepted',
                                'partially_accepted'
                            )
                            AND NEW.to_status = 'in_corrective_work')
                        OR (NEW.from_status = 'rejected'
                            AND NEW.to_status = 'ready_for_return')
                        OR (NEW.from_status IN (
                                'in_corrective_work',
                                'ready_for_return'
                            )
                            AND NEW.to_status = 'closed')
                    )
                )
            )
    )
BEGIN
    SELECT RAISE(ABORT, 'La historia del reclamo no coincide con su estado.');
END
SQL);

        $this->sqliteImmutable(
            'srv_warranty_claim_hist',
            'service_warranty_claim_status_histories'
        );

        DB::unprepared(<<<'SQL'
CREATE TRIGGER srv_warranty_res_guard_insert
BEFORE INSERT ON service_warranty_claim_resolutions
WHEN NEW.outcome NOT IN ('accepted', 'partially_accepted', 'rejected')
    OR TRIM(NEW.technical_basis) = ''
    OR TRIM(NEW.idempotency_key) = ''
    OR LENGTH(NEW.fingerprint) <> 64
    OR (
        NEW.outcome = 'accepted'
        AND (
            NEW.covered_scope IS NULL
            OR TRIM(NEW.covered_scope) = ''
            OR NEW.excluded_scope IS NOT NULL
        )
    )
    OR (
        NEW.outcome = 'partially_accepted'
        AND (
            NEW.covered_scope IS NULL
            OR TRIM(NEW.covered_scope) = ''
            OR NEW.excluded_scope IS NULL
            OR TRIM(NEW.excluded_scope) = ''
        )
    )
    OR (
        NEW.outcome = 'rejected'
        AND (
            NEW.covered_scope IS NOT NULL
            OR NEW.excluded_scope IS NULL
            OR TRIM(NEW.excluded_scope) = ''
        )
    )
    OR NOT EXISTS (
        SELECT 1
        FROM service_warranty_claims claim
        INNER JOIN service_warranty_grants warranty
            ON warranty.organization_id = claim.organization_id
            AND warranty.id = claim.service_warranty_grant_id
        INNER JOIN organization_memberships membership
            ON membership.organization_id = claim.organization_id
            AND membership.user_id = NEW.resolved_by_user_id
            AND membership.active = 1
            AND membership.role = 'admin'
        WHERE claim.organization_id = NEW.organization_id
            AND claim.id = NEW.service_warranty_claim_id
            AND claim.status = 'pending_review'
            AND NEW.warranty_status_at_resolution = CASE
                WHEN julianday(NEW.resolved_at) <= julianday(warranty.expires_at)
                    THEN 'active'
                ELSE 'expired'
            END
            AND (
                (
                    claim.warranty_status_at_claim = 'expired'
                    AND NEW.outcome IN ('accepted', 'partially_accepted')
                    AND NEW.administrative_exception = 1
                    AND NEW.exception_reason IS NOT NULL
                    AND TRIM(NEW.exception_reason) <> ''
                )
                OR (
                    NOT (
                        claim.warranty_status_at_claim = 'expired'
                        AND NEW.outcome IN ('accepted', 'partially_accepted')
                    )
                    AND NEW.administrative_exception = 0
                    AND NEW.exception_reason IS NULL
                )
            )
    )
BEGIN
    SELECT RAISE(ABORT, 'La resolución de garantía no es válida.');
END
SQL);

        $this->sqliteImmutable(
            'srv_warranty_res',
            'service_warranty_claim_resolutions'
        );

        DB::unprepared(<<<'SQL'
CREATE TRIGGER srv_warranty_return_guard_insert
BEFORE INSERT ON service_warranty_claim_returns
WHEN TRIM(NEW.recipient_name) = ''
    OR TRIM(NEW.condition_notes) = ''
    OR TRIM(NEW.accessories_snapshot) = ''
    OR TRIM(NEW.idempotency_key) = ''
    OR LENGTH(NEW.fingerprint) <> 64
    OR NOT EXISTS (
        SELECT 1
        FROM service_warranty_claims claim
        INNER JOIN service_warranty_claim_resolutions resolution
            ON resolution.organization_id = claim.organization_id
            AND resolution.service_warranty_claim_id = claim.id
        INNER JOIN service_orders corrective_order
            ON corrective_order.organization_id = claim.organization_id
            AND corrective_order.id = claim.corrective_service_order_id
        INNER JOIN service_custody_events custody
            ON custody.organization_id = claim.organization_id
            AND custody.id = NEW.service_custody_event_id
        INNER JOIN organization_memberships membership
            ON membership.organization_id = claim.organization_id
            AND membership.user_id = NEW.returned_by_user_id
            AND membership.active = 1
            AND membership.role IN ('admin', 'operator')
        WHERE claim.organization_id = NEW.organization_id
            AND claim.id = NEW.service_warranty_claim_id
            AND claim.status = 'ready_for_return'
            AND resolution.id = NEW.service_warranty_claim_resolution_id
            AND resolution.outcome = 'rejected'
            AND corrective_order.id = NEW.corrective_service_order_id
            AND corrective_order.status = 'ready_for_return'
            AND custody.service_order_id = corrective_order.id
            AND custody.event_type = 'warranty_returned'
    )
    OR (
        NEW.recipient_business_party_id IS NOT NULL
        AND NOT EXISTS (
            SELECT 1
            FROM business_parties party
            WHERE party.organization_id = NEW.organization_id
                AND party.id = NEW.recipient_business_party_id
        )
    )
BEGIN
    SELECT RAISE(ABORT, 'La devolución de garantía no es válida.');
END
SQL);

        $this->sqliteImmutable(
            'srv_warranty_return',
            'service_warranty_claim_returns'
        );
    }

    private function createMysqlTriggers(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER srv_orders_guard_update
BEFORE UPDATE ON service_orders
FOR EACH ROW
BEGIN
    IF OLD.organization_id <> NEW.organization_id
        OR OLD.public_id <> NEW.public_id
        OR OLD.order_number <> NEW.order_number
        OR OLD.service_asset_id <> NEW.service_asset_id
        OR NOT (OLD.customer_business_party_id <=> NEW.customer_business_party_id)
        OR NOT (OLD.owner_business_party_id <=> NEW.owner_business_party_id)
        OR OLD.intake_location_id <> NEW.intake_location_id
        OR OLD.created_by_user_id <> NEW.created_by_user_id
        OR OLD.received_at <> NEW.received_at
        OR NOT (OLD.promised_at <=> NEW.promised_at)
        OR OLD.idempotency_key <> NEW.idempotency_key
        OR NOT (OLD.metadata <=> NEW.metadata)
        OR (
            OLD.status <> NEW.status
            AND (
                NOT (
                    (OLD.status = 'received' AND NEW.status = 'diagnosing')
                    OR (
                        OLD.status = 'received'
                        AND NEW.status = 'in_progress'
                        AND EXISTS (
                            SELECT 1
                            FROM service_warranty_claims claim
                            INNER JOIN service_warranty_claim_resolutions resolution
                                ON resolution.organization_id = claim.organization_id
                                AND resolution.service_warranty_claim_id = claim.id
                            WHERE claim.organization_id = NEW.organization_id
                                AND claim.corrective_service_order_id = NEW.id
                                AND claim.status = 'in_corrective_work'
                                AND resolution.outcome IN (
                                    'accepted',
                                    'partially_accepted'
                                )
                        )
                    )
                    OR (
                        OLD.status = 'received'
                        AND NEW.status = 'ready_for_return'
                        AND EXISTS (
                            SELECT 1
                            FROM service_warranty_claims claim
                            INNER JOIN service_warranty_claim_resolutions resolution
                                ON resolution.organization_id = claim.organization_id
                                AND resolution.service_warranty_claim_id = claim.id
                            WHERE claim.organization_id = NEW.organization_id
                                AND claim.corrective_service_order_id = NEW.id
                                AND claim.status = 'ready_for_return'
                                AND resolution.outcome = 'rejected'
                        )
                    )
                    OR (OLD.status = 'diagnosing' AND NEW.status = 'awaiting_approval')
                    OR (OLD.status = 'awaiting_approval' AND NEW.status = 'in_progress')
                    OR (OLD.status = 'awaiting_approval' AND NEW.status = 'diagnosing')
                    OR (OLD.status = 'in_progress' AND NEW.status = 'with_external_provider')
                    OR (OLD.status = 'with_external_provider' AND NEW.status = 'in_progress')
                    OR (OLD.status = 'in_progress' AND NEW.status = 'quality_control')
                    OR (OLD.status = 'in_progress' AND NEW.status = 'diagnosing')
                    OR (OLD.status = 'in_progress' AND NEW.status = 'awaiting_parts')
                    OR (OLD.status = 'awaiting_parts' AND NEW.status = 'in_progress')
                    OR (OLD.status = 'quality_control' AND NEW.status = 'in_progress')
                    OR (OLD.status = 'quality_control' AND NEW.status = 'ready_for_delivery')
                    OR (OLD.status = 'ready_for_delivery' AND NEW.status = 'delivered')
                    OR (
                        OLD.status NOT IN (
                            'delivered',
                            'cancellation_pending',
                            'ready_for_return',
                            'cancelled'
                        )
                        AND NEW.status = 'cancellation_pending'
                        AND NOT EXISTS (
                            SELECT 1
                            FROM service_warranty_claims claim
                            WHERE claim.organization_id = NEW.organization_id
                                AND claim.corrective_service_order_id = NEW.id
                        )
                    )
                    OR (OLD.status = 'cancellation_pending' AND NEW.status = 'ready_for_return')
                    OR (OLD.status = 'ready_for_return' AND NEW.status = 'cancelled')
                )
                OR NOT EXISTS (
                    SELECT 1
                    FROM service_order_status_histories history
                    WHERE history.organization_id = NEW.organization_id
                        AND history.service_order_id = NEW.id
                        AND history.from_status = OLD.status
                        AND history.to_status = NEW.status
                        AND history.id = (
                            SELECT MAX(latest.id)
                            FROM service_order_status_histories latest
                            WHERE latest.organization_id = NEW.organization_id
                                AND latest.service_order_id = NEW.id
                        )
                )
                OR (
                    OLD.status = 'quality_control'
                    AND NEW.status = 'ready_for_delivery'
                    AND NOT EXISTS (
                        SELECT 1
                        FROM service_quality_inspections quality
                        WHERE quality.organization_id = NEW.organization_id
                            AND quality.service_order_id = NEW.id
                            AND quality.outcome = 'approved'
                            AND quality.id = (
                                SELECT MAX(latest.id)
                                FROM service_quality_inspections latest
                                WHERE latest.organization_id = NEW.organization_id
                                    AND latest.service_order_id = NEW.id
                            )
                    )
                )
                OR (
                    OLD.status = 'quality_control'
                    AND NEW.status = 'in_progress'
                    AND NOT EXISTS (
                        SELECT 1
                        FROM service_quality_inspections quality
                        WHERE quality.organization_id = NEW.organization_id
                            AND quality.service_order_id = NEW.id
                            AND quality.outcome = 'rework_required'
                            AND quality.id = (
                                SELECT MAX(latest.id)
                                FROM service_quality_inspections latest
                                WHERE latest.organization_id = NEW.organization_id
                                    AND latest.service_order_id = NEW.id
                            )
                    )
                )
                OR (
                    OLD.status = 'ready_for_delivery'
                    AND NEW.status = 'delivered'
                    AND (
                        NOT EXISTS (
                            SELECT 1
                            FROM service_deliveries delivery
                            WHERE delivery.organization_id = NEW.organization_id
                                AND delivery.service_order_id = NEW.id
                        )
                        OR EXISTS (
                            SELECT 1
                            FROM service_work_reports report
                            INNER JOIN service_work_items work
                                ON work.id = report.service_work_item_id
                                AND work.organization_id = report.organization_id
                            WHERE work.organization_id = NEW.organization_id
                                AND work.service_order_id = NEW.id
                                AND report.outcome = 'completed'
                                AND report.warranty_days > 0
                                AND NOT EXISTS (
                                    SELECT 1
                                    FROM service_warranty_grants warranty
                                    WHERE warranty.organization_id = report.organization_id
                                        AND warranty.service_work_report_id = report.id
                                )
                        )
                        OR EXISTS (
                            SELECT 1
                            FROM service_warranty_claims claim
                            WHERE claim.organization_id = NEW.organization_id
                                AND claim.corrective_service_order_id = NEW.id
                                AND claim.status <> 'closed'
                        )
                    )
                )
                OR (
                    NEW.status = 'cancellation_pending'
                    AND NOT EXISTS (
                        SELECT 1
                        FROM service_cancellation_requests request
                        WHERE request.organization_id = NEW.organization_id
                            AND request.service_order_id = NEW.id
                            AND request.order_status_snapshot = OLD.status
                    )
                )
                OR (
                    OLD.status = 'cancellation_pending'
                    AND NEW.status = 'ready_for_return'
                    AND NOT EXISTS (
                        SELECT 1
                        FROM service_cancellation_resolutions resolution
                        INNER JOIN service_cancellation_requests request
                            ON request.id = resolution.service_cancellation_request_id
                            AND request.organization_id = resolution.organization_id
                        WHERE request.organization_id = NEW.organization_id
                            AND request.service_order_id = NEW.id
                    )
                )
                OR (
                    OLD.status = 'ready_for_return'
                    AND NEW.status = 'cancelled'
                    AND NOT EXISTS (
                        SELECT 1
                        FROM service_cancellation_returns cancellation_return
                        WHERE cancellation_return.organization_id = NEW.organization_id
                            AND cancellation_return.service_order_id = NEW.id
                        UNION ALL
                        SELECT 1
                        FROM service_warranty_claim_returns warranty_return
                        WHERE warranty_return.organization_id = NEW.organization_id
                            AND warranty_return.corrective_service_order_id = NEW.id
                    )
                )
            )
        ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'La transición de la orden no es válida o carece de evidencia.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER srv_work_items_guard_insert
BEFORE INSERT ON service_work_items
FOR EACH ROW
BEGIN
    IF NEW.status <> 'planned'
        OR NEW.execution_mode NOT IN ('internal', 'external')
        OR (
            NEW.execution_mode = 'internal'
            AND (
                NEW.assigned_user_id IS NULL
                OR NEW.provider_business_party_id IS NOT NULL
                OR NOT EXISTS (
                    SELECT 1
                    FROM organization_memberships membership
                    WHERE membership.organization_id = NEW.organization_id
                        AND membership.user_id = NEW.assigned_user_id
                        AND membership.active = 1
                )
            )
        )
        OR (
            NEW.execution_mode = 'external'
            AND (
                NEW.provider_business_party_id IS NULL
                OR NEW.assigned_user_id IS NOT NULL
            )
        )
        OR (
            (NEW.service_quote_option_id IS NULL)
            = (NEW.service_warranty_claim_resolution_id IS NULL)
        )
        OR (
            NEW.service_quote_option_id IS NOT NULL
            AND NOT EXISTS (
                SELECT 1
                FROM service_quote_options quote_option
                INNER JOIN service_quotes quote
                    ON quote.id = quote_option.service_quote_id
                    AND quote.organization_id = quote_option.organization_id
                INNER JOIN service_quote_decisions decision
                    ON decision.service_quote_id = quote.id
                    AND decision.organization_id = quote.organization_id
                    AND decision.service_quote_option_id = quote_option.id
                WHERE quote_option.id = NEW.service_quote_option_id
                    AND quote_option.organization_id = NEW.organization_id
                    AND quote.service_order_id = NEW.service_order_id
                    AND decision.decision = 'approved'
            )
        )
        OR (
            NEW.service_warranty_claim_resolution_id IS NOT NULL
            AND NOT EXISTS (
                SELECT 1
                FROM service_warranty_claim_resolutions resolution
                INNER JOIN service_warranty_claims claim
                    ON claim.organization_id = resolution.organization_id
                    AND claim.id = resolution.service_warranty_claim_id
                INNER JOIN service_orders service_order
                    ON service_order.organization_id = claim.organization_id
                    AND service_order.id = claim.corrective_service_order_id
                WHERE resolution.organization_id = NEW.organization_id
                    AND resolution.id = NEW.service_warranty_claim_resolution_id
                    AND claim.corrective_service_order_id = NEW.service_order_id
                    AND claim.status = 'in_corrective_work'
                    AND resolution.outcome IN ('accepted', 'partially_accepted')
                    AND service_order.status = 'in_progress'
            )
        ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'El trabajo no coincide con su autorización o responsable.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER srv_work_items_guard_update
BEFORE UPDATE ON service_work_items
FOR EACH ROW
BEGIN
    IF OLD.organization_id <> NEW.organization_id
        OR OLD.service_order_id <> NEW.service_order_id
        OR NOT (OLD.service_quote_option_id <=> NEW.service_quote_option_id)
        OR NOT (
            OLD.service_warranty_claim_resolution_id
            <=> NEW.service_warranty_claim_resolution_id
        )
        OR OLD.sequence <> NEW.sequence
        OR OLD.title <> NEW.title
        OR OLD.description <> NEW.description
        OR OLD.execution_mode <> NEW.execution_mode
        OR NOT (
            OLD.provider_business_party_id
            <=> NEW.provider_business_party_id
        )
        OR NOT (OLD.assigned_user_id <=> NEW.assigned_user_id)
        OR OLD.created_by_user_id <> NEW.created_by_user_id
        OR OLD.planned_at <> NEW.planned_at
        OR OLD.idempotency_key <> NEW.idempotency_key
        OR OLD.fingerprint <> NEW.fingerprint
        OR (
            OLD.status <> NEW.status
            AND (
                NOT (
                    (OLD.status = 'planned' AND NEW.status = 'in_progress')
                    OR (OLD.status = 'planned' AND NEW.status = 'with_provider')
                    OR (OLD.status = 'with_provider' AND NEW.status = 'in_progress')
                    OR (OLD.status = 'in_progress' AND NEW.status = 'completed')
                    OR (OLD.status = 'in_progress' AND NEW.status = 'unresolved')
                    OR (
                        OLD.status IN ('planned', 'in_progress', 'with_provider')
                        AND NEW.status = 'cancelled'
                        AND EXISTS (
                            SELECT 1
                            FROM service_orders orders
                            INNER JOIN service_cancellation_requests request
                                ON request.service_order_id = orders.id
                                AND request.organization_id = orders.organization_id
                            WHERE orders.id = NEW.service_order_id
                                AND orders.organization_id = NEW.organization_id
                                AND orders.status = 'cancellation_pending'
                        )
                    )
                )
                OR NOT EXISTS (
                    SELECT 1
                    FROM service_work_status_histories history
                    WHERE history.organization_id = NEW.organization_id
                        AND history.service_work_item_id = NEW.id
                        AND history.from_status = OLD.status
                        AND history.to_status = NEW.status
                        AND history.id = (
                            SELECT MAX(latest.id)
                            FROM service_work_status_histories latest
                            WHERE latest.organization_id = NEW.organization_id
                                AND latest.service_work_item_id = NEW.id
                        )
                )
            )
        ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'El trabajo es inmutable o su transición carece de historia.';
    END IF;
END
SQL);

        $this->mysqlImmutable(
            'srv_work_items',
            'service_work_items',
            update: false
        );

        DB::unprepared(<<<'SQL'
CREATE TRIGGER srv_part_req_guard_insert
BEFORE INSERT ON service_part_requirements
FOR EACH ROW
BEGIN
    IF NEW.source NOT IN ('stock', 'direct_purchase')
        OR NEW.condition NOT IN ('new', 'used', 'refurbished', 'damaged', 'display')
        OR NEW.required_quantity <= 0
        OR (
            (NEW.service_quote_line_id IS NULL)
            = (NEW.service_warranty_claim_resolution_id IS NULL)
        )
        OR (
            NEW.service_quote_line_id IS NOT NULL
            AND NOT EXISTS (
                SELECT 1
                FROM service_work_items work_item
                INNER JOIN service_orders service_order
                    ON service_order.organization_id = work_item.organization_id
                    AND service_order.id = work_item.service_order_id
                INNER JOIN service_quote_lines quote_line
                    ON quote_line.organization_id = work_item.organization_id
                    AND quote_line.service_quote_option_id = work_item.service_quote_option_id
                INNER JOIN catalog_products product
                    ON product.id = NEW.catalog_product_id
                WHERE work_item.organization_id = NEW.organization_id
                    AND work_item.id = NEW.service_work_item_id
                    AND service_order.id = NEW.service_order_id
                    AND service_order.status IN ('in_progress', 'awaiting_parts')
                    AND work_item.status IN ('planned', 'in_progress')
                    AND quote_line.id = NEW.service_quote_line_id
                    AND quote_line.line_type = 'part'
                    AND quote_line.quantity = NEW.required_quantity
                    AND product.active = 1
                    AND product.base_unit_code = NEW.base_unit_code
                    AND NEW.required_quantity = ROUND(
                        NEW.required_quantity,
                        product.quantity_scale
                    )
            )
        )
        OR (
            NEW.service_warranty_claim_resolution_id IS NOT NULL
            AND NOT EXISTS (
                SELECT 1
                FROM service_work_items work_item
                INNER JOIN service_orders service_order
                    ON service_order.organization_id = work_item.organization_id
                    AND service_order.id = work_item.service_order_id
                INNER JOIN service_warranty_claim_resolutions resolution
                    ON resolution.organization_id = work_item.organization_id
                    AND resolution.id = work_item.service_warranty_claim_resolution_id
                INNER JOIN service_warranty_claims claim
                    ON claim.organization_id = resolution.organization_id
                    AND claim.id = resolution.service_warranty_claim_id
                INNER JOIN catalog_products product
                    ON product.id = NEW.catalog_product_id
                WHERE work_item.organization_id = NEW.organization_id
                    AND work_item.id = NEW.service_work_item_id
                    AND service_order.id = NEW.service_order_id
                    AND service_order.status IN ('in_progress', 'awaiting_parts')
                    AND work_item.status IN ('planned', 'in_progress')
                    AND work_item.service_quote_option_id IS NULL
                    AND resolution.id = NEW.service_warranty_claim_resolution_id
                    AND resolution.outcome IN ('accepted', 'partially_accepted')
                    AND claim.corrective_service_order_id = service_order.id
                    AND claim.status = 'in_corrective_work'
                    AND product.active = 1
                    AND product.base_unit_code = NEW.base_unit_code
                    AND NEW.required_quantity = ROUND(
                        NEW.required_quantity,
                        product.quantity_scale
                    )
            )
        ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'El repuesto no coincide con su autorización.';
    END IF;
END
SQL);

        $this->mysqlImmutable(
            'srv_part_req',
            'service_part_requirements'
        );

        DB::unprepared(<<<'SQL'
CREATE TRIGGER srv_warranty_block_cancel_insert
BEFORE INSERT ON service_cancellation_requests
FOR EACH ROW
BEGIN
    IF EXISTS (
        SELECT 1
        FROM service_warranty_claims claim
        WHERE claim.organization_id = NEW.organization_id
            AND claim.corrective_service_order_id = NEW.service_order_id
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Una orden correctiva de garantía no admite cancelación genérica.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER srv_warranty_claims_guard_insert
BEFORE INSERT ON service_warranty_claims
FOR EACH ROW
BEGIN
    IF TRIM(NEW.public_id) = ''
        OR TRIM(NEW.claimant_name) = ''
        OR TRIM(NEW.channel) = ''
        OR TRIM(NEW.reported_issue) = ''
        OR TRIM(NEW.reentry_condition_notes) = ''
        OR TRIM(NEW.accessories_snapshot) = ''
        OR TRIM(NEW.idempotency_key) = ''
        OR CHAR_LENGTH(NEW.fingerprint) <> 64
        OR NEW.status <> 'pending_review'
        OR NOT (NEW.open_warranty_grant_id <=> NEW.service_warranty_grant_id)
        OR NEW.closed_at IS NOT NULL
        OR NEW.received_at < NEW.claimed_at
        OR NOT EXISTS (
            SELECT 1
            FROM service_warranty_grants warranty
            INNER JOIN service_deliveries delivery
                ON delivery.organization_id = warranty.organization_id
                AND delivery.id = warranty.service_delivery_id
            INNER JOIN service_orders original_order
                ON original_order.organization_id = delivery.organization_id
                AND original_order.id = delivery.service_order_id
            INNER JOIN service_orders corrective_order
                ON corrective_order.organization_id = original_order.organization_id
                AND corrective_order.id = NEW.corrective_service_order_id
            INNER JOIN service_order_intakes corrective_intake
                ON corrective_intake.organization_id = corrective_order.organization_id
                AND corrective_intake.service_order_id = corrective_order.id
            WHERE warranty.organization_id = NEW.organization_id
                AND warranty.id = NEW.service_warranty_grant_id
                AND warranty.id = NEW.open_warranty_grant_id
                AND delivery.id = NEW.original_service_delivery_id
                AND original_order.id = NEW.original_service_order_id
                AND original_order.status = 'delivered'
                AND corrective_order.status = 'received'
                AND corrective_order.id <> original_order.id
                AND corrective_order.service_asset_id = original_order.service_asset_id
                AND NEW.claimed_at >= warranty.starts_at
                AND NEW.warranty_status_at_claim = CASE
                    WHEN NEW.claimed_at <= warranty.expires_at
                        THEN 'active'
                    ELSE 'expired'
                END
        )
        OR (
            NEW.claimant_business_party_id IS NOT NULL
            AND NOT EXISTS (
                SELECT 1
                FROM business_parties party
                WHERE party.organization_id = NEW.organization_id
                    AND party.id = NEW.claimant_business_party_id
            )
        )
        OR NOT EXISTS (
            SELECT 1
            FROM inventory_locations location
            WHERE location.organization_id = NEW.organization_id
                AND location.id = NEW.intake_location_id
                AND location.active = 1
        ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'El reclamo de garantía no coincide con la entrega original.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER srv_warranty_claims_guard_update
BEFORE UPDATE ON service_warranty_claims
FOR EACH ROW
BEGIN
    IF OLD.organization_id <> NEW.organization_id
        OR OLD.public_id <> NEW.public_id
        OR OLD.service_warranty_grant_id <> NEW.service_warranty_grant_id
        OR OLD.original_service_order_id <> NEW.original_service_order_id
        OR OLD.original_service_delivery_id <> NEW.original_service_delivery_id
        OR OLD.corrective_service_order_id <> NEW.corrective_service_order_id
        OR NOT (
            OLD.claimant_business_party_id
            <=> NEW.claimant_business_party_id
        )
        OR OLD.claimant_name <> NEW.claimant_name
        OR OLD.channel <> NEW.channel
        OR NOT (OLD.customer_reference <=> NEW.customer_reference)
        OR OLD.reported_issue <> NEW.reported_issue
        OR OLD.reentry_condition_notes <> NEW.reentry_condition_notes
        OR OLD.accessories_snapshot <> NEW.accessories_snapshot
        OR OLD.warranty_status_at_claim <> NEW.warranty_status_at_claim
        OR OLD.claimed_at <> NEW.claimed_at
        OR OLD.received_at <> NEW.received_at
        OR OLD.received_by_user_id <> NEW.received_by_user_id
        OR OLD.intake_location_id <> NEW.intake_location_id
        OR OLD.idempotency_key <> NEW.idempotency_key
        OR OLD.fingerprint <> NEW.fingerprint
        OR (
            OLD.status <> NEW.status
            AND (
                NOT (
                    (OLD.status = 'pending_review' AND NEW.status IN (
                        'accepted',
                        'partially_accepted',
                        'rejected'
                    ))
                    OR (OLD.status IN ('accepted', 'partially_accepted')
                        AND NEW.status = 'in_corrective_work')
                    OR (OLD.status = 'rejected'
                        AND NEW.status = 'ready_for_return')
                    OR (OLD.status IN ('in_corrective_work', 'ready_for_return')
                        AND NEW.status = 'closed')
                )
                OR NOT EXISTS (
                    SELECT 1
                    FROM service_warranty_claim_status_histories history
                    WHERE history.organization_id = NEW.organization_id
                        AND history.service_warranty_claim_id = NEW.id
                        AND history.from_status = OLD.status
                        AND history.to_status = NEW.status
                        AND history.id = (
                            SELECT MAX(latest.id)
                            FROM service_warranty_claim_status_histories latest
                            WHERE latest.organization_id = NEW.organization_id
                                AND latest.service_warranty_claim_id = NEW.id
                        )
                )
            )
        )
        OR (
            NEW.status = 'closed'
            AND (
                NEW.open_warranty_grant_id IS NOT NULL
                OR NEW.closed_at IS NULL
                OR (
                    OLD.status = 'in_corrective_work'
                    AND NOT EXISTS (
                        SELECT 1
                        FROM service_deliveries delivery
                        WHERE delivery.organization_id = NEW.organization_id
                            AND delivery.service_order_id = NEW.corrective_service_order_id
                    )
                )
                OR (
                    OLD.status = 'ready_for_return'
                    AND NOT EXISTS (
                        SELECT 1
                        FROM service_warranty_claim_returns warranty_return
                        WHERE warranty_return.organization_id = NEW.organization_id
                            AND warranty_return.service_warranty_claim_id = NEW.id
                    )
                )
            )
        )
        OR (
            NEW.status <> 'closed'
            AND (
                NOT (
                    NEW.open_warranty_grant_id
                    <=> NEW.service_warranty_grant_id
                )
                OR NEW.closed_at IS NOT NULL
            )
        )
        OR (
            NEW.status IN ('accepted', 'partially_accepted', 'in_corrective_work')
            AND NOT EXISTS (
                SELECT 1
                FROM service_warranty_claim_resolutions resolution
                WHERE resolution.organization_id = NEW.organization_id
                    AND resolution.service_warranty_claim_id = NEW.id
                    AND resolution.outcome IN ('accepted', 'partially_accepted')
            )
        )
        OR (
            NEW.status IN ('rejected', 'ready_for_return')
            AND NOT EXISTS (
                SELECT 1
                FROM service_warranty_claim_resolutions resolution
                WHERE resolution.organization_id = NEW.organization_id
                    AND resolution.service_warranty_claim_id = NEW.id
                    AND resolution.outcome = 'rejected'
            )
        ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'El reclamo es inmutable o su transición carece de evidencia.';
    END IF;
END
SQL);

        $this->mysqlImmutable(
            'srv_warranty_claims',
            'service_warranty_claims',
            update: false
        );

        DB::unprepared(<<<'SQL'
CREATE TRIGGER srv_warranty_claim_hist_guard_insert
BEFORE INSERT ON service_warranty_claim_status_histories
FOR EACH ROW
BEGIN
    IF NEW.to_status NOT IN (
            'pending_review',
            'accepted',
            'partially_accepted',
            'rejected',
            'in_corrective_work',
            'ready_for_return',
            'closed'
        )
        OR TRIM(NEW.reason) = ''
        OR TRIM(NEW.idempotency_key) = ''
        OR CHAR_LENGTH(NEW.fingerprint) <> 64
        OR NOT EXISTS (
            SELECT 1
            FROM service_warranty_claims claim
            WHERE claim.organization_id = NEW.organization_id
                AND claim.id = NEW.service_warranty_claim_id
                AND (
                    (
                        NEW.from_status IS NULL
                        AND NEW.to_status = 'pending_review'
                        AND claim.status = 'pending_review'
                        AND NOT EXISTS (
                            SELECT 1
                            FROM service_warranty_claim_status_histories existing
                            WHERE existing.organization_id = NEW.organization_id
                                AND existing.service_warranty_claim_id = NEW.service_warranty_claim_id
                        )
                    )
                    OR (
                        NEW.from_status = claim.status
                        AND (
                            (NEW.from_status = 'pending_review'
                                AND NEW.to_status IN (
                                    'accepted',
                                    'partially_accepted',
                                    'rejected'
                                ))
                            OR (NEW.from_status IN (
                                    'accepted',
                                    'partially_accepted'
                                )
                                AND NEW.to_status = 'in_corrective_work')
                            OR (NEW.from_status = 'rejected'
                                AND NEW.to_status = 'ready_for_return')
                            OR (NEW.from_status IN (
                                    'in_corrective_work',
                                    'ready_for_return'
                                )
                                AND NEW.to_status = 'closed')
                        )
                    )
                )
        ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'La historia del reclamo no coincide con su estado.';
    END IF;
END
SQL);

        $this->mysqlImmutable(
            'srv_warranty_claim_hist',
            'service_warranty_claim_status_histories'
        );

        DB::unprepared(<<<'SQL'
CREATE TRIGGER srv_warranty_res_guard_insert
BEFORE INSERT ON service_warranty_claim_resolutions
FOR EACH ROW
BEGIN
    IF NEW.outcome NOT IN ('accepted', 'partially_accepted', 'rejected')
        OR TRIM(NEW.technical_basis) = ''
        OR TRIM(NEW.idempotency_key) = ''
        OR CHAR_LENGTH(NEW.fingerprint) <> 64
        OR (
            NEW.outcome = 'accepted'
            AND (
                NEW.covered_scope IS NULL
                OR TRIM(NEW.covered_scope) = ''
                OR NEW.excluded_scope IS NOT NULL
            )
        )
        OR (
            NEW.outcome = 'partially_accepted'
            AND (
                NEW.covered_scope IS NULL
                OR TRIM(NEW.covered_scope) = ''
                OR NEW.excluded_scope IS NULL
                OR TRIM(NEW.excluded_scope) = ''
            )
        )
        OR (
            NEW.outcome = 'rejected'
            AND (
                NEW.covered_scope IS NOT NULL
                OR NEW.excluded_scope IS NULL
                OR TRIM(NEW.excluded_scope) = ''
            )
        )
        OR NOT EXISTS (
            SELECT 1
            FROM service_warranty_claims claim
            INNER JOIN service_warranty_grants warranty
                ON warranty.organization_id = claim.organization_id
                AND warranty.id = claim.service_warranty_grant_id
            INNER JOIN organization_memberships membership
                ON membership.organization_id = claim.organization_id
                AND membership.user_id = NEW.resolved_by_user_id
                AND membership.active = 1
                AND membership.role = 'admin'
            WHERE claim.organization_id = NEW.organization_id
                AND claim.id = NEW.service_warranty_claim_id
                AND claim.status = 'pending_review'
                AND NEW.warranty_status_at_resolution = CASE
                    WHEN NEW.resolved_at <= warranty.expires_at
                        THEN 'active'
                    ELSE 'expired'
                END
                AND (
                    (
                        claim.warranty_status_at_claim = 'expired'
                        AND NEW.outcome IN ('accepted', 'partially_accepted')
                        AND NEW.administrative_exception = 1
                        AND NEW.exception_reason IS NOT NULL
                        AND TRIM(NEW.exception_reason) <> ''
                    )
                    OR (
                        NOT (
                            claim.warranty_status_at_claim = 'expired'
                            AND NEW.outcome IN ('accepted', 'partially_accepted')
                        )
                        AND NEW.administrative_exception = 0
                        AND NEW.exception_reason IS NULL
                    )
                )
        ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'La resolución de garantía no es válida.';
    END IF;
END
SQL);

        $this->mysqlImmutable(
            'srv_warranty_res',
            'service_warranty_claim_resolutions'
        );

        DB::unprepared(<<<'SQL'
CREATE TRIGGER srv_warranty_return_guard_insert
BEFORE INSERT ON service_warranty_claim_returns
FOR EACH ROW
BEGIN
    IF TRIM(NEW.recipient_name) = ''
        OR TRIM(NEW.condition_notes) = ''
        OR TRIM(NEW.accessories_snapshot) = ''
        OR TRIM(NEW.idempotency_key) = ''
        OR CHAR_LENGTH(NEW.fingerprint) <> 64
        OR NOT EXISTS (
            SELECT 1
            FROM service_warranty_claims claim
            INNER JOIN service_warranty_claim_resolutions resolution
                ON resolution.organization_id = claim.organization_id
                AND resolution.service_warranty_claim_id = claim.id
            INNER JOIN service_orders corrective_order
                ON corrective_order.organization_id = claim.organization_id
                AND corrective_order.id = claim.corrective_service_order_id
            INNER JOIN service_custody_events custody
                ON custody.organization_id = claim.organization_id
                AND custody.id = NEW.service_custody_event_id
            INNER JOIN organization_memberships membership
                ON membership.organization_id = claim.organization_id
                AND membership.user_id = NEW.returned_by_user_id
                AND membership.active = 1
                AND membership.role IN ('admin', 'operator')
            WHERE claim.organization_id = NEW.organization_id
                AND claim.id = NEW.service_warranty_claim_id
                AND claim.status = 'ready_for_return'
                AND resolution.id = NEW.service_warranty_claim_resolution_id
                AND resolution.outcome = 'rejected'
                AND corrective_order.id = NEW.corrective_service_order_id
                AND corrective_order.status = 'ready_for_return'
                AND custody.service_order_id = corrective_order.id
                AND custody.event_type = 'warranty_returned'
        )
        OR (
            NEW.recipient_business_party_id IS NOT NULL
            AND NOT EXISTS (
                SELECT 1
                FROM business_parties party
                WHERE party.organization_id = NEW.organization_id
                    AND party.id = NEW.recipient_business_party_id
            )
        ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'La devolución de garantía no es válida.';
    END IF;
END
SQL);

        $this->mysqlImmutable(
            'srv_warranty_return',
            'service_warranty_claim_returns'
        );
    }

    private function restoreSqlitePreWarrantyTriggers(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER srv_orders_guard_update
BEFORE UPDATE ON service_orders
WHEN OLD.organization_id <> NEW.organization_id
    OR OLD.public_id <> NEW.public_id
    OR OLD.order_number <> NEW.order_number
    OR OLD.service_asset_id <> NEW.service_asset_id
    OR OLD.customer_business_party_id IS NOT NEW.customer_business_party_id
    OR OLD.owner_business_party_id IS NOT NEW.owner_business_party_id
    OR OLD.intake_location_id <> NEW.intake_location_id
    OR OLD.created_by_user_id <> NEW.created_by_user_id
    OR OLD.received_at <> NEW.received_at
    OR OLD.promised_at IS NOT NEW.promised_at
    OR OLD.idempotency_key <> NEW.idempotency_key
    OR OLD.metadata IS NOT NEW.metadata
    OR (
        OLD.status <> NEW.status
        AND (
            NOT (
                (OLD.status = 'received' AND NEW.status = 'diagnosing')
                OR (OLD.status = 'diagnosing' AND NEW.status = 'awaiting_approval')
                OR (OLD.status = 'awaiting_approval' AND NEW.status = 'in_progress')
                OR (OLD.status = 'awaiting_approval' AND NEW.status = 'diagnosing')
                OR (OLD.status = 'in_progress' AND NEW.status = 'with_external_provider')
                OR (OLD.status = 'with_external_provider' AND NEW.status = 'in_progress')
                OR (OLD.status = 'in_progress' AND NEW.status = 'quality_control')
                OR (OLD.status = 'in_progress' AND NEW.status = 'diagnosing')
                OR (OLD.status = 'in_progress' AND NEW.status = 'awaiting_parts')
                OR (OLD.status = 'awaiting_parts' AND NEW.status = 'in_progress')
                OR (OLD.status = 'quality_control' AND NEW.status = 'in_progress')
                OR (OLD.status = 'quality_control' AND NEW.status = 'ready_for_delivery')
                OR (OLD.status = 'ready_for_delivery' AND NEW.status = 'delivered')
                OR (
                    OLD.status NOT IN (
                        'delivered',
                        'cancellation_pending',
                        'ready_for_return',
                        'cancelled'
                    )
                    AND NEW.status = 'cancellation_pending'
                )
                OR (OLD.status = 'cancellation_pending' AND NEW.status = 'ready_for_return')
                OR (OLD.status = 'ready_for_return' AND NEW.status = 'cancelled')
            )
            OR NOT EXISTS (
                SELECT 1
                FROM service_order_status_histories history
                WHERE history.organization_id = NEW.organization_id
                    AND history.service_order_id = NEW.id
                    AND history.from_status = OLD.status
                    AND history.to_status = NEW.status
                    AND history.id = (
                        SELECT MAX(latest.id)
                        FROM service_order_status_histories latest
                        WHERE latest.organization_id = NEW.organization_id
                            AND latest.service_order_id = NEW.id
                    )
            )
            OR (
                OLD.status = 'quality_control'
                AND NEW.status = 'ready_for_delivery'
                AND NOT EXISTS (
                    SELECT 1 FROM service_quality_inspections quality
                    WHERE quality.organization_id = NEW.organization_id
                        AND quality.service_order_id = NEW.id
                        AND quality.outcome = 'approved'
                        AND quality.id = (
                            SELECT MAX(latest.id)
                            FROM service_quality_inspections latest
                            WHERE latest.organization_id = NEW.organization_id
                                AND latest.service_order_id = NEW.id
                        )
                )
            )
            OR (
                OLD.status = 'quality_control'
                AND NEW.status = 'in_progress'
                AND NOT EXISTS (
                    SELECT 1 FROM service_quality_inspections quality
                    WHERE quality.organization_id = NEW.organization_id
                        AND quality.service_order_id = NEW.id
                        AND quality.outcome = 'rework_required'
                        AND quality.id = (
                            SELECT MAX(latest.id)
                            FROM service_quality_inspections latest
                            WHERE latest.organization_id = NEW.organization_id
                                AND latest.service_order_id = NEW.id
                        )
                )
            )
            OR (
                OLD.status = 'ready_for_delivery'
                AND NEW.status = 'delivered'
                AND (
                    NOT EXISTS (
                        SELECT 1 FROM service_deliveries delivery
                        WHERE delivery.organization_id = NEW.organization_id
                            AND delivery.service_order_id = NEW.id
                    )
                    OR EXISTS (
                        SELECT 1
                        FROM service_work_reports report
                        INNER JOIN service_work_items work
                            ON work.id = report.service_work_item_id
                            AND work.organization_id = report.organization_id
                        WHERE work.organization_id = NEW.organization_id
                            AND work.service_order_id = NEW.id
                            AND report.outcome = 'completed'
                            AND report.warranty_days > 0
                            AND NOT EXISTS (
                                SELECT 1 FROM service_warranty_grants warranty
                                WHERE warranty.organization_id = report.organization_id
                                    AND warranty.service_work_report_id = report.id
                            )
                    )
                )
            )
                OR (
                    NEW.status = 'cancellation_pending'
                    AND NOT EXISTS (
                        SELECT 1
                        FROM service_cancellation_requests request
                        WHERE request.organization_id = NEW.organization_id
                            AND request.service_order_id = NEW.id
                            AND request.order_status_snapshot = OLD.status
                    )
                )
                OR (
                    OLD.status = 'cancellation_pending'
                    AND NEW.status = 'ready_for_return'
                    AND NOT EXISTS (
                        SELECT 1
                        FROM service_cancellation_resolutions resolution
                        INNER JOIN service_cancellation_requests request
                            ON request.id = resolution.service_cancellation_request_id
                            AND request.organization_id = resolution.organization_id
                        WHERE request.organization_id = NEW.organization_id
                            AND request.service_order_id = NEW.id
                    )
                )
                OR (
                    OLD.status = 'ready_for_return'
                    AND NEW.status = 'cancelled'
                    AND NOT EXISTS (
                        SELECT 1
                        FROM service_cancellation_returns cancellation_return
                        WHERE cancellation_return.organization_id = NEW.organization_id
                            AND cancellation_return.service_order_id = NEW.id
                    )
                )
        )
    )
BEGIN
    SELECT RAISE(ABORT, 'La transición de la orden no es válida o carece de evidencia.');
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER srv_work_items_guard_insert
BEFORE INSERT ON service_work_items
WHEN NEW.status <> 'planned'
    OR NEW.execution_mode NOT IN ('internal', 'external')
    OR (
        NEW.execution_mode = 'internal'
        AND (
            NEW.assigned_user_id IS NULL
            OR NEW.provider_business_party_id IS NOT NULL
            OR NOT EXISTS (
                SELECT 1
                FROM organization_memberships membership
                WHERE membership.organization_id = NEW.organization_id
                    AND membership.user_id = NEW.assigned_user_id
                    AND membership.active = 1
            )
        )
    )
    OR (
        NEW.execution_mode = 'external'
        AND (
            NEW.provider_business_party_id IS NULL
            OR NEW.assigned_user_id IS NOT NULL
        )
    )
    OR NOT EXISTS (
        SELECT 1
        FROM service_quote_options quote_option
        INNER JOIN service_quotes quote
            ON quote.id = quote_option.service_quote_id
            AND quote.organization_id = quote_option.organization_id
        INNER JOIN service_quote_decisions decision
            ON decision.service_quote_id = quote.id
            AND decision.organization_id = quote.organization_id
            AND decision.service_quote_option_id = quote_option.id
        WHERE quote_option.id = NEW.service_quote_option_id
            AND quote_option.organization_id = NEW.organization_id
            AND quote.service_order_id = NEW.service_order_id
            AND decision.decision = 'approved'
    )
BEGIN
    SELECT RAISE(ABORT, 'El trabajo no coincide con el alcance aprobado o su responsable.');
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER srv_work_items_guard_update
BEFORE UPDATE ON service_work_items
WHEN OLD.organization_id <> NEW.organization_id
    OR OLD.service_order_id <> NEW.service_order_id
    OR OLD.service_quote_option_id <> NEW.service_quote_option_id
    OR OLD.sequence <> NEW.sequence
    OR OLD.title <> NEW.title
    OR OLD.description <> NEW.description
    OR OLD.execution_mode <> NEW.execution_mode
    OR OLD.provider_business_party_id IS NOT NEW.provider_business_party_id
    OR OLD.assigned_user_id IS NOT NEW.assigned_user_id
    OR OLD.created_by_user_id <> NEW.created_by_user_id
    OR OLD.planned_at <> NEW.planned_at
    OR OLD.idempotency_key <> NEW.idempotency_key
    OR OLD.fingerprint <> NEW.fingerprint
    OR (
        OLD.status <> NEW.status
        AND (
            NOT (
                (OLD.status = 'planned' AND NEW.status = 'in_progress')
                OR (OLD.status = 'planned' AND NEW.status = 'with_provider')
                OR (OLD.status = 'with_provider' AND NEW.status = 'in_progress')
                OR (OLD.status = 'in_progress' AND NEW.status = 'completed')
                OR (OLD.status = 'in_progress' AND NEW.status = 'unresolved')
                OR (
                    OLD.status IN ('planned', 'in_progress', 'with_provider')
                    AND NEW.status = 'cancelled'
                    AND EXISTS (
                        SELECT 1
                        FROM service_orders orders
                        INNER JOIN service_cancellation_requests request
                            ON request.service_order_id = orders.id
                            AND request.organization_id = orders.organization_id
                        WHERE orders.id = NEW.service_order_id
                            AND orders.organization_id = NEW.organization_id
                            AND orders.status = 'cancellation_pending'
                    )
                )
            )
            OR NOT EXISTS (
                SELECT 1
                FROM service_work_status_histories history
                WHERE history.organization_id = NEW.organization_id
                    AND history.service_work_item_id = NEW.id
                    AND history.from_status = OLD.status
                    AND history.to_status = NEW.status
                    AND history.id = (
                        SELECT MAX(latest.id)
                        FROM service_work_status_histories latest
                        WHERE latest.organization_id = NEW.organization_id
                            AND latest.service_work_item_id = NEW.id
                    )
            )
        )
    )
BEGIN
    SELECT RAISE(ABORT, 'El trabajo es inmutable o su transición carece de historia.');
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER srv_work_items_guard_delete
BEFORE DELETE ON service_work_items
BEGIN
    SELECT RAISE(ABORT, 'El registro de ejecución no puede eliminarse.');
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER srv_part_req_guard_insert
BEFORE INSERT ON service_part_requirements
WHEN NEW.source NOT IN ('stock', 'direct_purchase')
    OR NEW.condition NOT IN ('new', 'used', 'refurbished', 'damaged', 'display')
    OR NEW.required_quantity <= 0
    OR NOT EXISTS (
        SELECT 1
        FROM service_work_items work_item
        INNER JOIN service_orders service_order
            ON service_order.organization_id = work_item.organization_id
            AND service_order.id = work_item.service_order_id
        INNER JOIN service_quote_lines quote_line
            ON quote_line.organization_id = work_item.organization_id
            AND quote_line.service_quote_option_id = work_item.service_quote_option_id
        INNER JOIN catalog_products product
            ON product.id = NEW.catalog_product_id
        WHERE work_item.organization_id = NEW.organization_id
            AND work_item.id = NEW.service_work_item_id
            AND service_order.id = NEW.service_order_id
            AND service_order.status IN ('in_progress', 'awaiting_parts')
            AND work_item.status IN ('planned', 'in_progress')
            AND quote_line.id = NEW.service_quote_line_id
            AND quote_line.line_type = 'part'
            AND quote_line.quantity = NEW.required_quantity
            AND product.active = 1
            AND product.base_unit_code = NEW.base_unit_code
            AND NEW.required_quantity = ROUND(
                NEW.required_quantity,
                product.quantity_scale
            )
    )
BEGIN
    SELECT RAISE(ABORT, 'El repuesto no coincide con el alcance aprobado.');
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER srv_part_req_guard_update
BEFORE UPDATE ON service_part_requirements
BEGIN
    SELECT RAISE(ABORT, 'El registro de repuestos es inmutable.');
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER srv_part_req_guard_delete
BEFORE DELETE ON service_part_requirements
BEGIN
    SELECT RAISE(ABORT, 'El registro de repuestos no puede eliminarse.');
END
SQL);

    }

    private function restoreMysqlPreWarrantyTriggers(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER srv_orders_guard_update
BEFORE UPDATE ON service_orders
FOR EACH ROW
BEGIN
    IF OLD.organization_id <> NEW.organization_id
        OR OLD.public_id <> NEW.public_id
        OR OLD.order_number <> NEW.order_number
        OR OLD.service_asset_id <> NEW.service_asset_id
        OR NOT (OLD.customer_business_party_id <=> NEW.customer_business_party_id)
        OR NOT (OLD.owner_business_party_id <=> NEW.owner_business_party_id)
        OR OLD.intake_location_id <> NEW.intake_location_id
        OR OLD.created_by_user_id <> NEW.created_by_user_id
        OR OLD.received_at <> NEW.received_at
        OR NOT (OLD.promised_at <=> NEW.promised_at)
        OR OLD.idempotency_key <> NEW.idempotency_key
        OR NOT (OLD.metadata <=> NEW.metadata)
        OR (
            OLD.status <> NEW.status
            AND (
                NOT (
                    (OLD.status = 'received' AND NEW.status = 'diagnosing')
                    OR (OLD.status = 'diagnosing' AND NEW.status = 'awaiting_approval')
                    OR (OLD.status = 'awaiting_approval' AND NEW.status = 'in_progress')
                    OR (OLD.status = 'awaiting_approval' AND NEW.status = 'diagnosing')
                    OR (OLD.status = 'in_progress' AND NEW.status = 'with_external_provider')
                    OR (OLD.status = 'with_external_provider' AND NEW.status = 'in_progress')
                    OR (OLD.status = 'in_progress' AND NEW.status = 'quality_control')
                    OR (OLD.status = 'in_progress' AND NEW.status = 'diagnosing')
                    OR (OLD.status = 'in_progress' AND NEW.status = 'awaiting_parts')
                    OR (OLD.status = 'awaiting_parts' AND NEW.status = 'in_progress')
                    OR (OLD.status = 'quality_control' AND NEW.status = 'in_progress')
                    OR (OLD.status = 'quality_control' AND NEW.status = 'ready_for_delivery')
                    OR (OLD.status = 'ready_for_delivery' AND NEW.status = 'delivered')
                    OR (
                        OLD.status NOT IN (
                            'delivered',
                            'cancellation_pending',
                            'ready_for_return',
                            'cancelled'
                        )
                        AND NEW.status = 'cancellation_pending'
                    )
                    OR (OLD.status = 'cancellation_pending' AND NEW.status = 'ready_for_return')
                    OR (OLD.status = 'ready_for_return' AND NEW.status = 'cancelled')
                )
                OR NOT EXISTS (
                    SELECT 1
                    FROM service_order_status_histories history
                    WHERE history.organization_id = NEW.organization_id
                        AND history.service_order_id = NEW.id
                        AND history.from_status = OLD.status
                        AND history.to_status = NEW.status
                        AND history.id = (
                            SELECT MAX(latest.id)
                            FROM service_order_status_histories latest
                            WHERE latest.organization_id = NEW.organization_id
                                AND latest.service_order_id = NEW.id
                        )
                )
                OR (
                    OLD.status = 'quality_control'
                    AND NEW.status = 'ready_for_delivery'
                    AND NOT EXISTS (
                        SELECT 1 FROM service_quality_inspections quality
                        WHERE quality.organization_id = NEW.organization_id
                            AND quality.service_order_id = NEW.id
                            AND quality.outcome = 'approved'
                            AND quality.id = (
                                SELECT MAX(latest.id)
                                FROM service_quality_inspections latest
                                WHERE latest.organization_id = NEW.organization_id
                                    AND latest.service_order_id = NEW.id
                            )
                    )
                )
                OR (
                    OLD.status = 'quality_control'
                    AND NEW.status = 'in_progress'
                    AND NOT EXISTS (
                        SELECT 1 FROM service_quality_inspections quality
                        WHERE quality.organization_id = NEW.organization_id
                            AND quality.service_order_id = NEW.id
                            AND quality.outcome = 'rework_required'
                            AND quality.id = (
                                SELECT MAX(latest.id)
                                FROM service_quality_inspections latest
                                WHERE latest.organization_id = NEW.organization_id
                                    AND latest.service_order_id = NEW.id
                            )
                    )
                )
                OR (
                    OLD.status = 'ready_for_delivery'
                    AND NEW.status = 'delivered'
                    AND (
                        NOT EXISTS (
                            SELECT 1 FROM service_deliveries delivery
                            WHERE delivery.organization_id = NEW.organization_id
                                AND delivery.service_order_id = NEW.id
                        )
                        OR EXISTS (
                            SELECT 1
                            FROM service_work_reports report
                            INNER JOIN service_work_items work
                                ON work.id = report.service_work_item_id
                                AND work.organization_id = report.organization_id
                            WHERE work.organization_id = NEW.organization_id
                                AND work.service_order_id = NEW.id
                                AND report.outcome = 'completed'
                                AND report.warranty_days > 0
                                AND NOT EXISTS (
                                    SELECT 1 FROM service_warranty_grants warranty
                                    WHERE warranty.organization_id = report.organization_id
                                        AND warranty.service_work_report_id = report.id
                                )
                        )
                    )
                )
                    OR (
                        NEW.status = 'cancellation_pending'
                        AND NOT EXISTS (
                            SELECT 1
                            FROM service_cancellation_requests request
                            WHERE request.organization_id = NEW.organization_id
                                AND request.service_order_id = NEW.id
                                AND request.order_status_snapshot = OLD.status
                        )
                    )
                    OR (
                        OLD.status = 'cancellation_pending'
                        AND NEW.status = 'ready_for_return'
                        AND NOT EXISTS (
                            SELECT 1
                            FROM service_cancellation_resolutions resolution
                            INNER JOIN service_cancellation_requests request
                                ON request.id = resolution.service_cancellation_request_id
                                AND request.organization_id = resolution.organization_id
                            WHERE request.organization_id = NEW.organization_id
                                AND request.service_order_id = NEW.id
                        )
                    )
                    OR (
                        OLD.status = 'ready_for_return'
                        AND NEW.status = 'cancelled'
                        AND NOT EXISTS (
                            SELECT 1
                            FROM service_cancellation_returns cancellation_return
                            WHERE cancellation_return.organization_id = NEW.organization_id
                                AND cancellation_return.service_order_id = NEW.id
                        )
                    )
            )
        ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'La transición de la orden no es válida o carece de evidencia.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER srv_work_items_guard_insert
BEFORE INSERT ON service_work_items
FOR EACH ROW
BEGIN
    IF NEW.status <> 'planned'
        OR NEW.execution_mode NOT IN ('internal', 'external')
        OR (
            NEW.execution_mode = 'internal'
            AND (
                NEW.assigned_user_id IS NULL
                OR NEW.provider_business_party_id IS NOT NULL
                OR NOT EXISTS (
                    SELECT 1
                    FROM organization_memberships membership
                    WHERE membership.organization_id = NEW.organization_id
                        AND membership.user_id = NEW.assigned_user_id
                        AND membership.active = 1
                )
            )
        )
        OR (
            NEW.execution_mode = 'external'
            AND (
                NEW.provider_business_party_id IS NULL
                OR NEW.assigned_user_id IS NOT NULL
            )
        )
        OR NOT EXISTS (
            SELECT 1
            FROM service_quote_options quote_option
            INNER JOIN service_quotes quote
                ON quote.id = quote_option.service_quote_id
                AND quote.organization_id = quote_option.organization_id
            INNER JOIN service_quote_decisions decision
                ON decision.service_quote_id = quote.id
                AND decision.organization_id = quote.organization_id
                AND decision.service_quote_option_id = quote_option.id
            WHERE quote_option.id = NEW.service_quote_option_id
                AND quote_option.organization_id = NEW.organization_id
                AND quote.service_order_id = NEW.service_order_id
                AND decision.decision = 'approved'
        ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'El trabajo no coincide con el alcance aprobado o su responsable.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER srv_work_items_guard_update
BEFORE UPDATE ON service_work_items
FOR EACH ROW
BEGIN
    IF OLD.organization_id <> NEW.organization_id
        OR OLD.service_order_id <> NEW.service_order_id
        OR OLD.service_quote_option_id <> NEW.service_quote_option_id
        OR OLD.sequence <> NEW.sequence
        OR OLD.title <> NEW.title
        OR OLD.description <> NEW.description
        OR OLD.execution_mode <> NEW.execution_mode
        OR NOT (OLD.provider_business_party_id <=> NEW.provider_business_party_id)
        OR NOT (OLD.assigned_user_id <=> NEW.assigned_user_id)
        OR OLD.created_by_user_id <> NEW.created_by_user_id
        OR OLD.planned_at <> NEW.planned_at
        OR OLD.idempotency_key <> NEW.idempotency_key
        OR OLD.fingerprint <> NEW.fingerprint
        OR (
            OLD.status <> NEW.status
            AND (
                NOT (
                    (OLD.status = 'planned' AND NEW.status = 'in_progress')
                    OR (OLD.status = 'planned' AND NEW.status = 'with_provider')
                    OR (OLD.status = 'with_provider' AND NEW.status = 'in_progress')
                    OR (OLD.status = 'in_progress' AND NEW.status = 'completed')
                    OR (OLD.status = 'in_progress' AND NEW.status = 'unresolved')
                    OR (
                        OLD.status IN ('planned', 'in_progress', 'with_provider')
                        AND NEW.status = 'cancelled'
                        AND EXISTS (
                            SELECT 1
                            FROM service_orders orders
                            INNER JOIN service_cancellation_requests request
                                ON request.service_order_id = orders.id
                                AND request.organization_id = orders.organization_id
                            WHERE orders.id = NEW.service_order_id
                                AND orders.organization_id = NEW.organization_id
                                AND orders.status = 'cancellation_pending'
                        )
                    )
                )
                OR NOT EXISTS (
                    SELECT 1
                    FROM service_work_status_histories history
                    WHERE history.organization_id = NEW.organization_id
                        AND history.service_work_item_id = NEW.id
                        AND history.from_status = OLD.status
                        AND history.to_status = NEW.status
                        AND history.id = (
                            SELECT MAX(latest.id)
                            FROM service_work_status_histories latest
                            WHERE latest.organization_id = NEW.organization_id
                                AND latest.service_work_item_id = NEW.id
                        )
                )
            )
        ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'El trabajo es inmutable o su transición carece de historia.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER srv_work_items_guard_delete
BEFORE DELETE ON service_work_items
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El registro de ejecución no puede eliminarse.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER srv_part_req_guard_insert
BEFORE INSERT ON service_part_requirements
FOR EACH ROW
BEGIN
    IF NEW.source NOT IN ('stock', 'direct_purchase')
        OR NEW.condition NOT IN ('new', 'used', 'refurbished', 'damaged', 'display')
        OR NEW.required_quantity <= 0
        OR NOT EXISTS (
            SELECT 1
            FROM service_work_items work_item
            INNER JOIN service_orders service_order
                ON service_order.organization_id = work_item.organization_id
                AND service_order.id = work_item.service_order_id
            INNER JOIN service_quote_lines quote_line
                ON quote_line.organization_id = work_item.organization_id
                AND quote_line.service_quote_option_id = work_item.service_quote_option_id
            INNER JOIN catalog_products product
                ON product.id = NEW.catalog_product_id
            WHERE work_item.organization_id = NEW.organization_id
                AND work_item.id = NEW.service_work_item_id
                AND service_order.id = NEW.service_order_id
                AND service_order.status IN ('in_progress', 'awaiting_parts')
                AND work_item.status IN ('planned', 'in_progress')
                AND quote_line.id = NEW.service_quote_line_id
                AND quote_line.line_type = 'part'
                AND quote_line.quantity = NEW.required_quantity
                AND product.active = 1
                AND product.base_unit_code = NEW.base_unit_code
                AND NEW.required_quantity = ROUND(
                    NEW.required_quantity,
                    product.quantity_scale
                )
        ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'El repuesto no coincide con el alcance aprobado.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER srv_part_req_guard_update
BEFORE UPDATE ON service_part_requirements
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El registro de repuestos es inmutable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER srv_part_req_guard_delete
BEFORE DELETE ON service_part_requirements
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El registro de repuestos no puede eliminarse.';
END
SQL);

    }

    private function sqliteImmutable(
        string $prefix,
        string $table,
        bool $update = true
    ): void {
        if ($update) {
            DB::unprepared(
                "CREATE TRIGGER {$prefix}_guard_update\n"
                ."BEFORE UPDATE ON {$table}\n"
                ."BEGIN\n"
                ."    SELECT RAISE(ABORT, 'El registro es inmutable.');\n"
                .'END'
            );
        }

        DB::unprepared(
            "CREATE TRIGGER {$prefix}_guard_delete\n"
            ."BEFORE DELETE ON {$table}\n"
            ."BEGIN\n"
            ."    SELECT RAISE(ABORT, 'El registro no puede eliminarse.');\n"
            .'END'
        );
    }

    private function mysqlImmutable(
        string $prefix,
        string $table,
        bool $update = true
    ): void {
        if ($update) {
            DB::unprepared(
                "CREATE TRIGGER {$prefix}_guard_update\n"
                ."BEFORE UPDATE ON {$table}\n"
                ."FOR EACH ROW\n"
                ."BEGIN\n"
                ."    SIGNAL SQLSTATE '45000'\n"
                ."        SET MESSAGE_TEXT = 'El registro es inmutable.';\n"
                .'END'
            );
        }

        DB::unprepared(
            "CREATE TRIGGER {$prefix}_guard_delete\n"
            ."BEFORE DELETE ON {$table}\n"
            ."FOR EACH ROW\n"
            ."BEGIN\n"
            ."    SIGNAL SQLSTATE '45000'\n"
            ."        SET MESSAGE_TEXT = 'El registro no puede eliminarse.';\n"
            .'END'
        );
    }
};
