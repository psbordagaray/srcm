<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var list<string> */
    private const INCIDENT_TRIGGERS = [
        'inv_neg_incidents_guard_insert',
        'inv_neg_incidents_guard_update',
        'inv_neg_incidents_guard_delete',
        'inv_neg_incident_lines_guard_insert',
        'inv_neg_incident_lines_guard_update',
        'inv_neg_incident_lines_guard_delete',
        'inv_neg_incident_history_guard_insert',
        'inv_neg_incident_history_guard_update',
        'inv_neg_incident_history_guard_delete',
    ];

    public function up(): void
    {
        $this->dropIncidentTriggers();
        $this->replaceRequestTrigger(true);
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            $this->createSqliteIncidentTriggers();

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->createMysqlIncidentTriggers();

            return;
        }

        throw new LogicException(
            "La inmutabilidad de incidencias no está implementada para {$driver}."
        );
    }

    public function down(): void
    {
        $this->dropIncidentTriggers();
        $this->replaceRequestTrigger(false);
    }

    private function dropIncidentTriggers(): void
    {
        foreach (self::INCIDENT_TRIGGERS as $trigger) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
        }
    }

    private function replaceRequestTrigger(
        bool $allowApprovedInvalidation
    ): void {
        DB::unprepared(
            'DROP TRIGGER IF EXISTS inv_neg_requests_guard_update'
        );
        $driver = DB::getDriverName();
        $approved = $allowApprovedInvalidation
            ? "NEW.status IN ('fulfilled', 'invalidated')"
            : "NEW.status = 'fulfilled'";

        if ($driver === 'sqlite') {
            DB::unprepared(<<<SQL
CREATE TRIGGER inv_neg_requests_guard_update
BEFORE UPDATE ON inventory_negative_requests
WHEN OLD.organization_id IS NOT NEW.organization_id
    OR OLD.public_id IS NOT NEW.public_id
    OR OLD.inventory_movement_id IS NOT NEW.inventory_movement_id
    OR OLD.requested_by_user_id IS NOT NEW.requested_by_user_id
    OR OLD.reason IS NOT NEW.reason
    OR OLD.movement_fingerprint IS NOT NEW.movement_fingerprint
    OR OLD.snapshot_fingerprint IS NOT NEW.snapshot_fingerprint
    OR OLD.request_fingerprint IS NOT NEW.request_fingerprint
    OR OLD.requested_at IS NOT NEW.requested_at
    OR NOT (
        (OLD.status = 'pending' AND NEW.status IN (
            'approved', 'rejected', 'invalidated'
        ))
        OR (OLD.status = 'approved' AND {$approved})
    )
BEGIN
    SELECT RAISE(ABORT, 'La solicitud negativa es inmutable.');
END
SQL);

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::unprepared(<<<SQL
CREATE TRIGGER inv_neg_requests_guard_update
BEFORE UPDATE ON inventory_negative_requests
FOR EACH ROW
BEGIN
    IF NOT (OLD.organization_id <=> NEW.organization_id)
        OR NOT (OLD.public_id <=> NEW.public_id)
        OR NOT (OLD.inventory_movement_id <=> NEW.inventory_movement_id)
        OR NOT (OLD.requested_by_user_id <=> NEW.requested_by_user_id)
        OR NOT (OLD.reason <=> NEW.reason)
        OR NOT (OLD.movement_fingerprint <=> NEW.movement_fingerprint)
        OR NOT (OLD.snapshot_fingerprint <=> NEW.snapshot_fingerprint)
        OR NOT (OLD.request_fingerprint <=> NEW.request_fingerprint)
        OR NOT (OLD.requested_at <=> NEW.requested_at)
        OR NOT (
            (OLD.status = 'pending' AND NEW.status IN (
                'approved', 'rejected', 'invalidated'
            ))
            OR (OLD.status = 'approved' AND {$approved})
        ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'La solicitud negativa es inmutable.';
    END IF;
END
SQL);

            return;
        }

        throw new LogicException(
            "La transición de solicitudes no está implementada para {$driver}."
        );
    }

    private function createSqliteIncidentTriggers(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER inv_neg_incidents_guard_insert
BEFORE INSERT ON inventory_negative_incidents
WHEN NEW.status <> 'open'
    OR NEW.regularized_at IS NOT NULL
    OR NEW.resolved_by_user_id IS NOT NULL
    OR NEW.resolved_at IS NOT NULL
    OR NEW.resolution_reason IS NOT NULL
    OR NOT EXISTS (
        SELECT 1
        FROM inventory_movements AS movement
        JOIN inventory_negative_requests AS request
          ON request.id = NEW.inventory_negative_request_id
         AND request.organization_id = NEW.organization_id
        JOIN inventory_negative_overrides AS negative_override
          ON negative_override.id = NEW.inventory_negative_override_id
         AND negative_override.organization_id = NEW.organization_id
        WHERE movement.id = NEW.inventory_movement_id
          AND movement.organization_id = NEW.organization_id
          AND movement.status = 'draft'
          AND request.inventory_movement_id = movement.id
          AND request.requested_by_user_id = NEW.requested_by_user_id
          AND request.reason = NEW.reason
          AND request.status = 'approved'
          AND negative_override.inventory_movement_id = movement.id
          AND negative_override.inventory_negative_request_id = request.id
          AND negative_override.authorized_user_id = NEW.requested_by_user_id
          AND negative_override.granted_by_user_id = NEW.granted_by_user_id
          AND negative_override.status = 'active'
    )
BEGIN
    SELECT RAISE(ABORT, 'Una incidencia nueva debe quedar abierta.');
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER inv_neg_incidents_guard_update
BEFORE UPDATE ON inventory_negative_incidents
BEGIN
    SELECT RAISE(ABORT, 'La incidencia aún no admite revisión o resolución.');
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER inv_neg_incidents_guard_delete
BEFORE DELETE ON inventory_negative_incidents
BEGIN
    SELECT RAISE(ABORT, 'La incidencia negativa no puede eliminarse.');
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER inv_neg_incident_lines_guard_insert
BEFORE INSERT ON inventory_negative_incident_lines
WHEN NOT EXISTS (
    SELECT 1 FROM inventory_negative_incidents
    WHERE id = NEW.inventory_negative_incident_id
      AND organization_id = NEW.organization_id
      AND status = 'open'
      AND EXISTS (
          SELECT 1 FROM inventory_movements
          WHERE id = inventory_negative_incidents.inventory_movement_id
            AND organization_id = NEW.organization_id
            AND status = 'draft'
      )
      AND EXISTS (
          SELECT 1
          FROM inventory_negative_request_lines AS request_line
          WHERE request_line.inventory_negative_request_id =
                inventory_negative_incidents.inventory_negative_request_id
            AND request_line.organization_id = NEW.organization_id
            AND request_line.catalog_product_id = NEW.catalog_product_id
            AND request_line.inventory_location_id = NEW.inventory_location_id
            AND request_line.condition = NEW.condition
            AND request_line.current_quantity = NEW.previous_quantity
            AND request_line.requested_quantity = NEW.outgoing_quantity
            AND request_line.incoming_quantity = NEW.incoming_quantity
            AND request_line.projected_quantity = NEW.resulting_quantity
            AND request_line.current_deficit = NEW.previous_deficit
            AND request_line.projected_deficit = NEW.resulting_deficit
            AND request_line.incremental_deficit = NEW.incremental_deficit
            AND request_line.base_unit_code = NEW.base_unit_code
            AND request_line.creates_negative = 1
      )
      AND NEW.net_quantity = NEW.incoming_quantity - NEW.outgoing_quantity
      AND NEW.pending_deficit = NEW.incremental_deficit
      AND NEW.incremental_deficit > 0
)
BEGIN
    SELECT RAISE(ABORT, 'La incidencia no admite líneas nuevas.');
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER inv_neg_incident_lines_guard_update
BEFORE UPDATE ON inventory_negative_incident_lines
BEGIN
    SELECT RAISE(ABORT, 'La línea de incidencia es inmutable.');
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER inv_neg_incident_lines_guard_delete
BEFORE DELETE ON inventory_negative_incident_lines
BEGIN
    SELECT RAISE(ABORT, 'La línea de incidencia no puede eliminarse.');
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER inv_neg_incident_history_guard_insert
BEFORE INSERT ON inventory_negative_incident_status_histories
WHEN NEW.from_status IS NOT NULL
    OR NEW.to_status <> 'open'
    OR EXISTS (
        SELECT 1
        FROM inventory_negative_incident_status_histories
        WHERE inventory_negative_incident_id = NEW.inventory_negative_incident_id
    )
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
          AND incident.status = 'open'
          AND movement.status = 'draft'
          AND NEW.changed_by_user_id = request.requested_by_user_id
    )
BEGIN
    SELECT RAISE(ABORT, 'La historia inicial de incidencia es inválida.');
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER inv_neg_incident_history_guard_update
BEFORE UPDATE ON inventory_negative_incident_status_histories
BEGIN
    SELECT RAISE(ABORT, 'La historia de incidencia es inmutable.');
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER inv_neg_incident_history_guard_delete
BEFORE DELETE ON inventory_negative_incident_status_histories
BEGIN
    SELECT RAISE(ABORT, 'La historia de incidencia no puede eliminarse.');
END
SQL);
    }

    private function createMysqlIncidentTriggers(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER inv_neg_incidents_guard_insert
BEFORE INSERT ON inventory_negative_incidents
FOR EACH ROW
BEGIN
    IF NEW.status <> 'open'
        OR NEW.regularized_at IS NOT NULL
        OR NEW.resolved_by_user_id IS NOT NULL
        OR NEW.resolved_at IS NOT NULL
        OR NEW.resolution_reason IS NOT NULL
        OR NOT EXISTS (
            SELECT 1
            FROM inventory_movements AS movement
            JOIN inventory_negative_requests AS request
              ON request.id = NEW.inventory_negative_request_id
             AND request.organization_id = NEW.organization_id
            JOIN inventory_negative_overrides AS negative_override
              ON negative_override.id = NEW.inventory_negative_override_id
             AND negative_override.organization_id = NEW.organization_id
            WHERE movement.id = NEW.inventory_movement_id
              AND movement.organization_id = NEW.organization_id
              AND movement.status = 'draft'
              AND request.inventory_movement_id = movement.id
              AND request.requested_by_user_id = NEW.requested_by_user_id
              AND request.reason = NEW.reason
              AND request.status = 'approved'
              AND negative_override.inventory_movement_id = movement.id
              AND negative_override.inventory_negative_request_id = request.id
              AND negative_override.authorized_user_id = NEW.requested_by_user_id
              AND negative_override.granted_by_user_id = NEW.granted_by_user_id
              AND negative_override.status = 'active'
        ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Una incidencia nueva debe quedar abierta.';
    END IF;
END
SQL);

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
CREATE TRIGGER inv_neg_incidents_guard_delete
BEFORE DELETE ON inventory_negative_incidents
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'La incidencia negativa no puede eliminarse.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER inv_neg_incident_lines_guard_insert
BEFORE INSERT ON inventory_negative_incident_lines
FOR EACH ROW
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM inventory_negative_incidents
        WHERE id = NEW.inventory_negative_incident_id
          AND organization_id = NEW.organization_id
          AND status = 'open'
          AND EXISTS (
              SELECT 1 FROM inventory_movements
              WHERE id = inventory_negative_incidents.inventory_movement_id
                AND organization_id = NEW.organization_id
                AND status = 'draft'
          )
          AND EXISTS (
              SELECT 1
              FROM inventory_negative_request_lines AS request_line
              WHERE request_line.inventory_negative_request_id =
                    inventory_negative_incidents.inventory_negative_request_id
                AND request_line.organization_id = NEW.organization_id
                AND request_line.catalog_product_id = NEW.catalog_product_id
                AND request_line.inventory_location_id = NEW.inventory_location_id
                AND request_line.condition = NEW.condition
                AND request_line.current_quantity = NEW.previous_quantity
                AND request_line.requested_quantity = NEW.outgoing_quantity
                AND request_line.incoming_quantity = NEW.incoming_quantity
                AND request_line.projected_quantity = NEW.resulting_quantity
                AND request_line.current_deficit = NEW.previous_deficit
                AND request_line.projected_deficit = NEW.resulting_deficit
                AND request_line.incremental_deficit = NEW.incremental_deficit
                AND request_line.base_unit_code = NEW.base_unit_code
                AND request_line.creates_negative = 1
          )
          AND NEW.net_quantity = NEW.incoming_quantity - NEW.outgoing_quantity
          AND NEW.pending_deficit = NEW.incremental_deficit
          AND NEW.incremental_deficit > 0
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'La incidencia no admite líneas nuevas.';
    END IF;
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

        DB::unprepared(<<<'SQL'
CREATE TRIGGER inv_neg_incident_lines_guard_delete
BEFORE DELETE ON inventory_negative_incident_lines
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'La línea de incidencia no puede eliminarse.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER inv_neg_incident_history_guard_insert
BEFORE INSERT ON inventory_negative_incident_status_histories
FOR EACH ROW
BEGIN
    IF NEW.from_status IS NOT NULL
        OR NEW.to_status <> 'open'
        OR EXISTS (
            SELECT 1
            FROM inventory_negative_incident_status_histories
            WHERE inventory_negative_incident_id = NEW.inventory_negative_incident_id
        )
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
              AND incident.status = 'open'
              AND movement.status = 'draft'
              AND NEW.changed_by_user_id = request.requested_by_user_id
        ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'La historia inicial de incidencia es inválida.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER inv_neg_incident_history_guard_update
BEFORE UPDATE ON inventory_negative_incident_status_histories
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'La historia de incidencia es inmutable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER inv_neg_incident_history_guard_delete
BEFORE DELETE ON inventory_negative_incident_status_histories
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'La historia de incidencia no puede eliminarse.';
END
SQL);
    }
};
