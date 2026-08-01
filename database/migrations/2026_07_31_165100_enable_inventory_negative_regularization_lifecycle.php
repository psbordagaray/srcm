<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var list<string> */
    private const TRIGGERS = [
        'inv_neg_incidents_guard_update',
        'inv_neg_incident_lines_guard_update',
        'inv_neg_incident_history_guard_insert',
        'inv_neg_regularizations_guard_insert',
        'inv_neg_regularizations_guard_update',
        'inv_neg_regularizations_guard_delete',
    ];

    public function up(): void
    {
        $this->dropTriggers();
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            $this->createSqliteTriggers();

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->createMysqlTriggers();

            return;
        }

        throw new LogicException(
            "La regularización negativa no está implementada para {$driver}."
        );
    }

    public function down(): void
    {
        $this->dropTriggers();
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            DB::unprepared(<<<'SQL'
CREATE TRIGGER inv_neg_incidents_guard_update
BEFORE UPDATE ON inventory_negative_incidents
BEGIN
    SELECT RAISE(ABORT, 'La incidencia aún no admite revisión o resolución.');
END
SQL);
            DB::unprepared(<<<'SQL'
CREATE TRIGGER inv_neg_incident_lines_guard_update
BEFORE UPDATE ON inventory_negative_incident_lines
BEGIN
    SELECT RAISE(ABORT, 'La línea de incidencia es inmutable.');
END
SQL);
            $this->createSqliteHistoryInsertTrigger();

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::unprepared(<<<'SQL'
CREATE TRIGGER inv_neg_incidents_guard_update
BEFORE UPDATE ON inventory_negative_incidents
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'La incidencia aún no admite revisión o resolución.';
END
SQL);
            DB::unprepared(<<<'SQL'
CREATE TRIGGER inv_neg_incident_lines_guard_update
BEFORE UPDATE ON inventory_negative_incident_lines
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'La línea de incidencia es inmutable.';
END
SQL);
            $this->createMysqlHistoryInsertTrigger();
        }
    }

    private function dropTriggers(): void
    {
        foreach (self::TRIGGERS as $trigger) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
        }
    }

    private function createSqliteTriggers(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER inv_neg_regularizations_guard_insert
BEFORE INSERT ON inventory_negative_regularizations
WHEN NEW.quantity <= 0
    OR NOT EXISTS (
        SELECT 1
        FROM inventory_negative_incident_lines AS line
        JOIN inventory_negative_incidents AS incident
          ON incident.id = line.inventory_negative_incident_id
         AND incident.organization_id = line.organization_id
        JOIN inventory_movements AS movement
          ON movement.id = NEW.regularizing_movement_id
         AND movement.organization_id = NEW.organization_id
        JOIN organization_memberships AS membership
          ON membership.organization_id = NEW.organization_id
         AND membership.user_id = NEW.applied_by_user_id
        WHERE line.id = NEW.inventory_negative_incident_line_id
          AND line.inventory_negative_incident_id =
                NEW.inventory_negative_incident_id
          AND line.organization_id = NEW.organization_id
          AND incident.status IN ('open', 'under_review')
          AND line.pending_deficit >= NEW.quantity
          AND NOT EXISTS (
              SELECT 1
              FROM inventory_negative_incident_lines AS older_line
              JOIN inventory_negative_incidents AS older_incident
                ON older_incident.id =
                   older_line.inventory_negative_incident_id
               AND older_incident.organization_id =
                   older_line.organization_id
              WHERE older_line.organization_id = NEW.organization_id
                AND older_line.catalog_product_id = line.catalog_product_id
                AND older_line.inventory_location_id =
                    line.inventory_location_id
                AND older_line.condition = line.condition
                AND older_line.pending_deficit > 0
                AND older_incident.status IN ('open', 'under_review')
                AND (
                    older_incident.opened_at < incident.opened_at
                    OR (
                        older_incident.opened_at = incident.opened_at
                        AND older_incident.id < incident.id
                    )
                    OR (
                        older_incident.id = incident.id
                        AND older_line.sequence < line.sequence
                    )
                )
          )
          AND movement.status = 'draft'
          AND membership.active = 1
          AND (
              CASE
                  WHEN (
                      SELECT balance.quantity
                      FROM inventory_balances AS balance
                      WHERE balance.organization_id = NEW.organization_id
                        AND balance.catalog_product_id = line.catalog_product_id
                        AND balance.inventory_location_id =
                            line.inventory_location_id
                        AND balance.condition = line.condition
                  ) < 0
                  THEN -(
                      SELECT balance.quantity
                      FROM inventory_balances AS balance
                      WHERE balance.organization_id = NEW.organization_id
                        AND balance.catalog_product_id = line.catalog_product_id
                        AND balance.inventory_location_id =
                            line.inventory_location_id
                        AND balance.condition = line.condition
                  )
                  ELSE 0
              END
              + COALESCE((
                  SELECT SUM(applied.quantity)
                  FROM inventory_negative_regularizations AS applied
                  JOIN inventory_negative_incident_lines AS applied_line
                    ON applied_line.id =
                       applied.inventory_negative_incident_line_id
                  WHERE applied.organization_id = NEW.organization_id
                    AND applied_line.catalog_product_id = line.catalog_product_id
                    AND applied_line.inventory_location_id =
                        line.inventory_location_id
                    AND applied_line.condition = line.condition
              ), 0)
              + NEW.quantity
          ) <= (
              SELECT COALESCE(SUM(all_lines.incremental_deficit), 0)
              FROM inventory_negative_incident_lines AS all_lines
              WHERE all_lines.organization_id = NEW.organization_id
                AND all_lines.catalog_product_id = line.catalog_product_id
                AND all_lines.inventory_location_id =
                    line.inventory_location_id
                AND all_lines.condition = line.condition
          )
          AND (
              SELECT COALESCE(SUM(
                  CASE
                      WHEN movement_line.destination_location_id =
                           line.inventory_location_id
                      THEN movement_line.base_quantity
                      ELSE 0
                  END
                  - CASE
                      WHEN movement_line.source_location_id =
                           line.inventory_location_id
                      THEN movement_line.base_quantity
                      ELSE 0
                  END
              ), 0)
              FROM inventory_movement_lines AS movement_line
              WHERE movement_line.inventory_movement_id = movement.id
                AND movement_line.organization_id = NEW.organization_id
                AND movement_line.catalog_product_id = line.catalog_product_id
                AND movement_line.condition = line.condition
          ) > 0
          AND NEW.quantity + COALESCE((
              SELECT SUM(existing.quantity)
              FROM inventory_negative_regularizations AS existing
              JOIN inventory_negative_incident_lines AS existing_line
                ON existing_line.id =
                   existing.inventory_negative_incident_line_id
              WHERE existing.organization_id = NEW.organization_id
                AND existing.regularizing_movement_id = movement.id
                AND existing_line.catalog_product_id = line.catalog_product_id
                AND existing_line.inventory_location_id =
                    line.inventory_location_id
                AND existing_line.condition = line.condition
          ), 0) <= (
              SELECT COALESCE(SUM(
                  CASE
                      WHEN movement_line.destination_location_id =
                           line.inventory_location_id
                      THEN movement_line.base_quantity
                      ELSE 0
                  END
                  - CASE
                      WHEN movement_line.source_location_id =
                           line.inventory_location_id
                      THEN movement_line.base_quantity
                      ELSE 0
                  END
              ), 0)
              FROM inventory_movement_lines AS movement_line
              WHERE movement_line.inventory_movement_id = movement.id
                AND movement_line.organization_id = NEW.organization_id
                AND movement_line.catalog_product_id = line.catalog_product_id
                AND movement_line.condition = line.condition
          )
    )
BEGIN
    SELECT RAISE(ABORT, 'La imputación de regularización es inválida.');
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER inv_neg_regularizations_guard_update
BEFORE UPDATE ON inventory_negative_regularizations
BEGIN
    SELECT RAISE(ABORT, 'La imputación de regularización es inmutable.');
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER inv_neg_regularizations_guard_delete
BEFORE DELETE ON inventory_negative_regularizations
BEGIN
    SELECT RAISE(ABORT, 'La imputación de regularización no puede eliminarse.');
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER inv_neg_incident_lines_guard_update
BEFORE UPDATE ON inventory_negative_incident_lines
WHEN OLD.organization_id IS NOT NEW.organization_id
    OR OLD.inventory_negative_incident_id IS NOT
       NEW.inventory_negative_incident_id
    OR OLD.sequence IS NOT NEW.sequence
    OR OLD.catalog_product_id IS NOT NEW.catalog_product_id
    OR OLD.inventory_location_id IS NOT NEW.inventory_location_id
    OR OLD.condition IS NOT NEW.condition
    OR OLD.previous_quantity IS NOT NEW.previous_quantity
    OR OLD.outgoing_quantity IS NOT NEW.outgoing_quantity
    OR OLD.incoming_quantity IS NOT NEW.incoming_quantity
    OR OLD.net_quantity IS NOT NEW.net_quantity
    OR OLD.resulting_quantity IS NOT NEW.resulting_quantity
    OR OLD.previous_deficit IS NOT NEW.previous_deficit
    OR OLD.resulting_deficit IS NOT NEW.resulting_deficit
    OR OLD.incremental_deficit IS NOT NEW.incremental_deficit
    OR OLD.base_unit_code IS NOT NEW.base_unit_code
    OR OLD.created_at IS NOT NEW.created_at
    OR NEW.pending_deficit < 0
    OR NEW.pending_deficit >= OLD.pending_deficit
    OR NEW.pending_deficit <> NEW.incremental_deficit - COALESCE((
        SELECT SUM(quantity)
        FROM inventory_negative_regularizations
        WHERE inventory_negative_incident_line_id = NEW.id
    ), 0)
    OR (
        NEW.pending_deficit = 0
        AND NEW.regularized_at IS NULL
    )
    OR (
        NEW.pending_deficit > 0
        AND NEW.regularized_at IS NOT NULL
    )
BEGIN
    SELECT RAISE(ABORT, 'La regularización de la línea es inválida.');
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER inv_neg_incidents_guard_update
BEFORE UPDATE ON inventory_negative_incidents
WHEN OLD.organization_id IS NOT NEW.organization_id
    OR OLD.public_id IS NOT NEW.public_id
    OR OLD.inventory_movement_id IS NOT NEW.inventory_movement_id
    OR OLD.inventory_negative_request_id IS NOT
       NEW.inventory_negative_request_id
    OR OLD.inventory_negative_override_id IS NOT
       NEW.inventory_negative_override_id
    OR OLD.requested_by_user_id IS NOT NEW.requested_by_user_id
    OR OLD.granted_by_user_id IS NOT NEW.granted_by_user_id
    OR OLD.reason IS NOT NEW.reason
    OR OLD.opened_at IS NOT NEW.opened_at
    OR OLD.created_at IS NOT NEW.created_at
    OR NOT (
        (
            OLD.status = NEW.status
            AND NEW.status IN ('open', 'under_review')
            AND OLD.regularized_at IS NULL
            AND NEW.regularized_at IS NOT NULL
            AND OLD.reviewed_by_user_id IS NEW.reviewed_by_user_id
            AND OLD.reviewed_at IS NEW.reviewed_at
            AND OLD.review_reason IS NEW.review_reason
            AND OLD.resolved_by_user_id IS NEW.resolved_by_user_id
            AND OLD.resolved_at IS NEW.resolved_at
            AND OLD.resolution_reason IS NEW.resolution_reason
            AND NOT EXISTS (
                SELECT 1 FROM inventory_negative_incident_lines
                WHERE inventory_negative_incident_id = NEW.id
                  AND pending_deficit > 0
            )
        )
        OR (
            OLD.status = 'open'
            AND NEW.status = 'under_review'
            AND OLD.regularized_at IS NEW.regularized_at
            AND OLD.reviewed_by_user_id IS NULL
            AND NEW.reviewed_by_user_id IS NOT NULL
            AND NEW.reviewed_at IS NOT NULL
            AND LENGTH(TRIM(NEW.review_reason)) > 0
            AND OLD.resolved_by_user_id IS NEW.resolved_by_user_id
            AND OLD.resolved_at IS NEW.resolved_at
            AND OLD.resolution_reason IS NEW.resolution_reason
            AND EXISTS (
                SELECT 1 FROM organization_memberships
                WHERE organization_id = NEW.organization_id
                  AND user_id = NEW.reviewed_by_user_id
                  AND role = 'admin'
                  AND active = 1
            )
        )
        OR (
            OLD.status IN ('open', 'under_review')
            AND NEW.status = 'resolved'
            AND NEW.regularized_at IS NOT NULL
            AND OLD.regularized_at IS NEW.regularized_at
            AND OLD.reviewed_by_user_id IS NEW.reviewed_by_user_id
            AND OLD.reviewed_at IS NEW.reviewed_at
            AND OLD.review_reason IS NEW.review_reason
            AND NEW.resolved_by_user_id IS NOT NULL
            AND NEW.resolved_at IS NOT NULL
            AND LENGTH(TRIM(NEW.resolution_reason)) > 0
            AND NOT EXISTS (
                SELECT 1 FROM inventory_negative_incident_lines
                WHERE inventory_negative_incident_id = NEW.id
                  AND pending_deficit > 0
            )
            AND EXISTS (
                SELECT 1 FROM organization_memberships
                WHERE organization_id = NEW.organization_id
                  AND user_id = NEW.resolved_by_user_id
                  AND role = 'admin'
                  AND active = 1
            )
        )
    )
BEGIN
    SELECT RAISE(ABORT, 'La transición de incidencia es inválida.');
END
SQL);

        $this->createSqliteHistoryInsertTrigger(false);
    }

    private function createSqliteHistoryInsertTrigger(
        bool $initialOnly = true
    ): void {
        $transition = $initialOnly ? '' : <<<'SQL'
        OR (
            NEW.from_status IS NOT NULL
            AND (
                (NEW.from_status = 'open'
                    AND NEW.to_status IN ('under_review', 'resolved'))
                OR (NEW.from_status = 'under_review'
                    AND NEW.to_status = 'resolved')
            )
            AND incident.status = NEW.to_status
            AND (
                (NEW.to_status = 'under_review'
                    AND incident.reviewed_by_user_id = NEW.changed_by_user_id)
                OR (NEW.to_status = 'resolved'
                    AND incident.resolved_by_user_id = NEW.changed_by_user_id)
            )
            AND EXISTS (
                SELECT 1 FROM organization_memberships
                WHERE organization_id = NEW.organization_id
                  AND user_id = NEW.changed_by_user_id
                  AND role = 'admin'
                  AND active = 1
            )
            AND (
                SELECT to_status
                FROM inventory_negative_incident_status_histories
                WHERE inventory_negative_incident_id = incident.id
                ORDER BY id DESC
                LIMIT 1
            ) = NEW.from_status
        )
SQL;

        DB::unprepared(<<<SQL
CREATE TRIGGER inv_neg_incident_history_guard_insert
BEFORE INSERT ON inventory_negative_incident_status_histories
WHEN LENGTH(TRIM(NEW.reason)) = 0
    OR NOT EXISTS (
        SELECT 1
        FROM inventory_negative_incidents AS incident
        JOIN inventory_movements AS movement
          ON movement.id = incident.inventory_movement_id
         AND movement.organization_id = incident.organization_id
        JOIN inventory_negative_requests AS request
          ON request.id = incident.inventory_negative_request_id
         AND request.organization_id = incident.organization_id
        WHERE incident.id = NEW.inventory_negative_incident_id
          AND incident.organization_id = NEW.organization_id
          AND (
              (
                  NEW.from_status IS NULL
                  AND NEW.to_status = 'open'
                  AND incident.status = 'open'
                  AND movement.status = 'draft'
                  AND NEW.changed_by_user_id = request.requested_by_user_id
                  AND NOT EXISTS (
                      SELECT 1
                      FROM inventory_negative_incident_status_histories
                      WHERE inventory_negative_incident_id = incident.id
                  )
              )
              {$transition}
          )
    )
BEGIN
    SELECT RAISE(ABORT, 'La historia de incidencia es inválida.');
END
SQL);
    }

    private function createMysqlTriggers(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER inv_neg_regularizations_guard_insert
BEFORE INSERT ON inventory_negative_regularizations
FOR EACH ROW
BEGIN
    IF NEW.quantity <= 0 OR NOT EXISTS (
        SELECT 1
        FROM inventory_negative_incident_lines AS line
        JOIN inventory_negative_incidents AS incident
          ON incident.id = line.inventory_negative_incident_id
         AND incident.organization_id = line.organization_id
        JOIN inventory_movements AS movement
          ON movement.id = NEW.regularizing_movement_id
         AND movement.organization_id = NEW.organization_id
        JOIN organization_memberships AS membership
          ON membership.organization_id = NEW.organization_id
         AND membership.user_id = NEW.applied_by_user_id
        WHERE line.id = NEW.inventory_negative_incident_line_id
          AND line.inventory_negative_incident_id =
                NEW.inventory_negative_incident_id
          AND line.organization_id = NEW.organization_id
          AND incident.status IN ('open', 'under_review')
          AND line.pending_deficit >= NEW.quantity
          AND NOT EXISTS (
              SELECT 1
              FROM inventory_negative_incident_lines AS older_line
              JOIN inventory_negative_incidents AS older_incident
                ON older_incident.id =
                   older_line.inventory_negative_incident_id
               AND older_incident.organization_id =
                   older_line.organization_id
              WHERE older_line.organization_id = NEW.organization_id
                AND older_line.catalog_product_id = line.catalog_product_id
                AND older_line.inventory_location_id =
                    line.inventory_location_id
                AND older_line.condition = line.condition
                AND older_line.pending_deficit > 0
                AND older_incident.status IN ('open', 'under_review')
                AND (
                    older_incident.opened_at < incident.opened_at
                    OR (
                        older_incident.opened_at = incident.opened_at
                        AND older_incident.id < incident.id
                    )
                    OR (
                        older_incident.id = incident.id
                        AND older_line.sequence < line.sequence
                    )
                )
          )
          AND movement.status = 'draft'
          AND membership.active = 1
          AND (
              CASE
                  WHEN (
                      SELECT balance.quantity
                      FROM inventory_balances AS balance
                      WHERE balance.organization_id = NEW.organization_id
                        AND balance.catalog_product_id = line.catalog_product_id
                        AND balance.inventory_location_id =
                            line.inventory_location_id
                        AND balance.condition = line.condition
                  ) < 0
                  THEN -(
                      SELECT balance.quantity
                      FROM inventory_balances AS balance
                      WHERE balance.organization_id = NEW.organization_id
                        AND balance.catalog_product_id = line.catalog_product_id
                        AND balance.inventory_location_id =
                            line.inventory_location_id
                        AND balance.condition = line.condition
                  )
                  ELSE 0
              END
              + COALESCE((
                  SELECT SUM(applied.quantity)
                  FROM inventory_negative_regularizations AS applied
                  JOIN inventory_negative_incident_lines AS applied_line
                    ON applied_line.id =
                       applied.inventory_negative_incident_line_id
                  WHERE applied.organization_id = NEW.organization_id
                    AND applied_line.catalog_product_id = line.catalog_product_id
                    AND applied_line.inventory_location_id =
                        line.inventory_location_id
                    AND applied_line.condition = line.condition
              ), 0)
              + NEW.quantity
          ) <= (
              SELECT COALESCE(SUM(all_lines.incremental_deficit), 0)
              FROM inventory_negative_incident_lines AS all_lines
              WHERE all_lines.organization_id = NEW.organization_id
                AND all_lines.catalog_product_id = line.catalog_product_id
                AND all_lines.inventory_location_id =
                    line.inventory_location_id
                AND all_lines.condition = line.condition
          )
          AND (
              SELECT COALESCE(SUM(
                  CASE
                      WHEN movement_line.destination_location_id =
                           line.inventory_location_id
                      THEN movement_line.base_quantity
                      ELSE 0
                  END
                  - CASE
                      WHEN movement_line.source_location_id =
                           line.inventory_location_id
                      THEN movement_line.base_quantity
                      ELSE 0
                  END
              ), 0)
              FROM inventory_movement_lines AS movement_line
              WHERE movement_line.inventory_movement_id = movement.id
                AND movement_line.organization_id = NEW.organization_id
                AND movement_line.catalog_product_id = line.catalog_product_id
                AND movement_line.condition = line.condition
          ) > 0
          AND NEW.quantity + COALESCE((
              SELECT SUM(existing.quantity)
              FROM inventory_negative_regularizations AS existing
              JOIN inventory_negative_incident_lines AS existing_line
                ON existing_line.id =
                   existing.inventory_negative_incident_line_id
              WHERE existing.organization_id = NEW.organization_id
                AND existing.regularizing_movement_id = movement.id
                AND existing_line.catalog_product_id = line.catalog_product_id
                AND existing_line.inventory_location_id =
                    line.inventory_location_id
                AND existing_line.condition = line.condition
          ), 0) <= (
              SELECT COALESCE(SUM(
                  CASE
                      WHEN movement_line.destination_location_id =
                           line.inventory_location_id
                      THEN movement_line.base_quantity
                      ELSE 0
                  END
                  - CASE
                      WHEN movement_line.source_location_id =
                           line.inventory_location_id
                      THEN movement_line.base_quantity
                      ELSE 0
                  END
              ), 0)
              FROM inventory_movement_lines AS movement_line
              WHERE movement_line.inventory_movement_id = movement.id
                AND movement_line.organization_id = NEW.organization_id
                AND movement_line.catalog_product_id = line.catalog_product_id
                AND movement_line.condition = line.condition
          )
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'La imputación de regularización es inválida.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER inv_neg_regularizations_guard_update
BEFORE UPDATE ON inventory_negative_regularizations
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'La imputación de regularización es inmutable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER inv_neg_regularizations_guard_delete
BEFORE DELETE ON inventory_negative_regularizations
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'La imputación de regularización no puede eliminarse.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER inv_neg_incident_lines_guard_update
BEFORE UPDATE ON inventory_negative_incident_lines
FOR EACH ROW
BEGIN
    IF NOT (OLD.organization_id <=> NEW.organization_id)
        OR NOT (OLD.inventory_negative_incident_id <=>
                NEW.inventory_negative_incident_id)
        OR NOT (OLD.sequence <=> NEW.sequence)
        OR NOT (OLD.catalog_product_id <=> NEW.catalog_product_id)
        OR NOT (OLD.inventory_location_id <=> NEW.inventory_location_id)
        OR NOT (OLD.condition <=> NEW.condition)
        OR NOT (OLD.previous_quantity <=> NEW.previous_quantity)
        OR NOT (OLD.outgoing_quantity <=> NEW.outgoing_quantity)
        OR NOT (OLD.incoming_quantity <=> NEW.incoming_quantity)
        OR NOT (OLD.net_quantity <=> NEW.net_quantity)
        OR NOT (OLD.resulting_quantity <=> NEW.resulting_quantity)
        OR NOT (OLD.previous_deficit <=> NEW.previous_deficit)
        OR NOT (OLD.resulting_deficit <=> NEW.resulting_deficit)
        OR NOT (OLD.incremental_deficit <=> NEW.incremental_deficit)
        OR NOT (OLD.base_unit_code <=> NEW.base_unit_code)
        OR NOT (OLD.created_at <=> NEW.created_at)
        OR NEW.pending_deficit < 0
        OR NEW.pending_deficit >= OLD.pending_deficit
        OR NEW.pending_deficit <> NEW.incremental_deficit - COALESCE((
            SELECT SUM(quantity)
            FROM inventory_negative_regularizations
            WHERE inventory_negative_incident_line_id = NEW.id
        ), 0)
        OR (NEW.pending_deficit = 0 AND NEW.regularized_at IS NULL)
        OR (NEW.pending_deficit > 0 AND NEW.regularized_at IS NOT NULL) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'La regularización de la línea es inválida.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER inv_neg_incidents_guard_update
BEFORE UPDATE ON inventory_negative_incidents
FOR EACH ROW
BEGIN
    IF NOT (OLD.organization_id <=> NEW.organization_id)
        OR NOT (OLD.public_id <=> NEW.public_id)
        OR NOT (OLD.inventory_movement_id <=> NEW.inventory_movement_id)
        OR NOT (OLD.inventory_negative_request_id <=>
                NEW.inventory_negative_request_id)
        OR NOT (OLD.inventory_negative_override_id <=>
                NEW.inventory_negative_override_id)
        OR NOT (OLD.requested_by_user_id <=> NEW.requested_by_user_id)
        OR NOT (OLD.granted_by_user_id <=> NEW.granted_by_user_id)
        OR NOT (OLD.reason <=> NEW.reason)
        OR NOT (OLD.opened_at <=> NEW.opened_at)
        OR NOT (OLD.created_at <=> NEW.created_at)
        OR NOT (
            (
                OLD.status = NEW.status
                AND NEW.status IN ('open', 'under_review')
                AND OLD.regularized_at IS NULL
                AND NEW.regularized_at IS NOT NULL
                AND (OLD.reviewed_by_user_id <=> NEW.reviewed_by_user_id)
                AND (OLD.reviewed_at <=> NEW.reviewed_at)
                AND (OLD.review_reason <=> NEW.review_reason)
                AND (OLD.resolved_by_user_id <=> NEW.resolved_by_user_id)
                AND (OLD.resolved_at <=> NEW.resolved_at)
                AND (OLD.resolution_reason <=> NEW.resolution_reason)
                AND NOT EXISTS (
                    SELECT 1 FROM inventory_negative_incident_lines
                    WHERE inventory_negative_incident_id = NEW.id
                      AND pending_deficit > 0
                )
            )
            OR (
                OLD.status = 'open'
                AND NEW.status = 'under_review'
                AND (OLD.regularized_at <=> NEW.regularized_at)
                AND OLD.reviewed_by_user_id IS NULL
                AND NEW.reviewed_by_user_id IS NOT NULL
                AND NEW.reviewed_at IS NOT NULL
                AND LENGTH(TRIM(NEW.review_reason)) > 0
                AND (OLD.resolved_by_user_id <=> NEW.resolved_by_user_id)
                AND (OLD.resolved_at <=> NEW.resolved_at)
                AND (OLD.resolution_reason <=> NEW.resolution_reason)
                AND EXISTS (
                    SELECT 1 FROM organization_memberships
                    WHERE organization_id = NEW.organization_id
                      AND user_id = NEW.reviewed_by_user_id
                      AND role = 'admin'
                      AND active = 1
                )
            )
            OR (
                OLD.status IN ('open', 'under_review')
                AND NEW.status = 'resolved'
                AND NEW.regularized_at IS NOT NULL
                AND (OLD.regularized_at <=> NEW.regularized_at)
                AND (OLD.reviewed_by_user_id <=> NEW.reviewed_by_user_id)
                AND (OLD.reviewed_at <=> NEW.reviewed_at)
                AND (OLD.review_reason <=> NEW.review_reason)
                AND NEW.resolved_by_user_id IS NOT NULL
                AND NEW.resolved_at IS NOT NULL
                AND LENGTH(TRIM(NEW.resolution_reason)) > 0
                AND NOT EXISTS (
                    SELECT 1 FROM inventory_negative_incident_lines
                    WHERE inventory_negative_incident_id = NEW.id
                      AND pending_deficit > 0
                )
                AND EXISTS (
                    SELECT 1 FROM organization_memberships
                    WHERE organization_id = NEW.organization_id
                      AND user_id = NEW.resolved_by_user_id
                      AND role = 'admin'
                      AND active = 1
                )
            )
        ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'La transición de incidencia es inválida.';
    END IF;
END
SQL);

        $this->createMysqlHistoryInsertTrigger(false);
    }

    private function createMysqlHistoryInsertTrigger(
        bool $initialOnly = true
    ): void {
        $transition = $initialOnly ? '' : <<<'SQL'
            OR (
                NEW.from_status IS NOT NULL
                AND (
                    (NEW.from_status = 'open'
                        AND NEW.to_status IN ('under_review', 'resolved'))
                    OR (NEW.from_status = 'under_review'
                        AND NEW.to_status = 'resolved')
                )
                AND incident.status = NEW.to_status
                AND (
                    (NEW.to_status = 'under_review'
                        AND incident.reviewed_by_user_id = NEW.changed_by_user_id)
                    OR (NEW.to_status = 'resolved'
                        AND incident.resolved_by_user_id = NEW.changed_by_user_id)
                )
                AND EXISTS (
                    SELECT 1 FROM organization_memberships
                    WHERE organization_id = NEW.organization_id
                      AND user_id = NEW.changed_by_user_id
                      AND role = 'admin'
                      AND active = 1
                )
                AND (
                    SELECT history.to_status
                    FROM inventory_negative_incident_status_histories AS history
                    WHERE history.inventory_negative_incident_id = incident.id
                    ORDER BY history.id DESC
                    LIMIT 1
                ) = NEW.from_status
            )
SQL;

        DB::unprepared(<<<SQL
CREATE TRIGGER inv_neg_incident_history_guard_insert
BEFORE INSERT ON inventory_negative_incident_status_histories
FOR EACH ROW
BEGIN
    IF LENGTH(TRIM(NEW.reason)) = 0 OR NOT EXISTS (
        SELECT 1
        FROM inventory_negative_incidents AS incident
        JOIN inventory_movements AS movement
          ON movement.id = incident.inventory_movement_id
         AND movement.organization_id = incident.organization_id
        JOIN inventory_negative_requests AS request
          ON request.id = incident.inventory_negative_request_id
         AND request.organization_id = incident.organization_id
        WHERE incident.id = NEW.inventory_negative_incident_id
          AND incident.organization_id = NEW.organization_id
          AND (
              (
                  NEW.from_status IS NULL
                  AND NEW.to_status = 'open'
                  AND incident.status = 'open'
                  AND movement.status = 'draft'
                  AND NEW.changed_by_user_id = request.requested_by_user_id
                  AND NOT EXISTS (
                      SELECT 1
                      FROM inventory_negative_incident_status_histories
                      WHERE inventory_negative_incident_id = incident.id
                  )
              )
              {$transition}
          )
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'La historia de incidencia es inválida.';
    END IF;
END
SQL);
    }
};
