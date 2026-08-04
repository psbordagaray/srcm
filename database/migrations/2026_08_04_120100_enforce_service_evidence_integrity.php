<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var array<string, string> */
    private const CONTEXT_COLUMNS = [
        'order' => '',
        'intake' => 'service_order_intake_id',
        'diagnostic' => 'service_diagnostic_id',
        'work_item' => 'service_work_item_id',
        'part_requirement' => 'service_part_requirement_id',
        'custody_event' => 'service_custody_event_id',
        'quality_inspection' => 'service_quality_inspection_id',
        'delivery' => 'service_delivery_id',
        'cancellation_request' => 'service_cancellation_request_id',
        'cancellation_resolution' => 'service_cancellation_resolution_id',
        'cancellation_return' => 'service_cancellation_return_id',
        'warranty_claim' => 'service_warranty_claim_id',
        'warranty_resolution' => 'service_warranty_claim_resolution_id',
        'warranty_return' => 'service_warranty_claim_return_id',
    ];

    /** @var list<string> */
    private const REFERENCE_COLUMNS = [
        'service_order_intake_id',
        'service_diagnostic_id',
        'service_work_item_id',
        'service_part_requirement_id',
        'service_custody_event_id',
        'service_quality_inspection_id',
        'service_delivery_id',
        'service_cancellation_request_id',
        'service_cancellation_resolution_id',
        'service_cancellation_return_id',
        'service_warranty_claim_id',
        'service_warranty_claim_resolution_id',
        'service_warranty_claim_return_id',
    ];

    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        match ($driver) {
            'sqlite' => $this->createSqliteTriggers(),
            'mysql' => $this->createMysqlTriggers(),
            default => throw new RuntimeException(
                'Motor no soportado para evidencias privadas: '.$driver
            ),
        };
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS svc_ev_insert_guard');
            DB::unprepared('DROP TRIGGER IF EXISTS svc_ev_update_guard');
            DB::unprepared('DROP TRIGGER IF EXISTS svc_ev_delete_guard');
        }

        if ($driver === 'mysql') {
            DB::unprepared('DROP TRIGGER IF EXISTS svc_ev_insert_guard');
            DB::unprepared('DROP TRIGGER IF EXISTS svc_ev_update_guard');
            DB::unprepared('DROP TRIGGER IF EXISTS svc_ev_delete_guard');
        }
    }

    private function createSqliteTriggers(): void
    {
        $checks = $this->checks('sqlite');
        $body = implode("\n", array_map(
            static fn (array $check): string => sprintf(
                "    SELECT CASE WHEN %s THEN RAISE(ABORT, '%s') END;",
                $check[0],
                str_replace("'", "''", $check[1])
            ),
            $checks
        ));

        DB::unprepared(<<<SQL
CREATE TRIGGER svc_ev_insert_guard
BEFORE INSERT ON service_evidences
FOR EACH ROW
BEGIN
{$body}
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER svc_ev_update_guard
BEFORE UPDATE ON service_evidences
FOR EACH ROW
BEGIN
    SELECT RAISE(ABORT, 'La evidencia confirmada es inmutable.');
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER svc_ev_delete_guard
BEFORE DELETE ON service_evidences
FOR EACH ROW
BEGIN
    SELECT RAISE(ABORT, 'La evidencia confirmada no puede eliminarse.');
END
SQL);
    }

    private function createMysqlTriggers(): void
    {
        $checks = $this->checks('mysql');
        $body = implode("\n", array_map(
            static fn (array $check): string => sprintf(
                "    IF %s THEN\n        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '%s';\n    END IF;",
                $check[0],
                str_replace("'", "''", $check[1])
            ),
            $checks
        ));

        DB::unprepared(<<<SQL
CREATE TRIGGER svc_ev_insert_guard
BEFORE INSERT ON service_evidences
FOR EACH ROW
BEGIN
{$body}
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER svc_ev_update_guard
BEFORE UPDATE ON service_evidences
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'La evidencia confirmada es inmutable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER svc_ev_delete_guard
BEFORE DELETE ON service_evidences
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'La evidencia confirmada no puede eliminarse.';
END
SQL);
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function checks(string $driver): array
    {
        $contexts = "'".implode("','", array_keys(self::CONTEXT_COLUMNS))."'";
        $referenceCount = implode(' + ', array_map(
            static fn (string $column): string => "(NEW.{$column} IS NOT NULL)",
            self::REFERENCE_COLUMNS
        ));

        $hexCheck = $driver === 'sqlite'
            ? static fn (string $column): string => "length(NEW.{$column}) <> 64 OR lower(NEW.{$column}) <> NEW.{$column} OR NEW.{$column} GLOB '*[^0-9a-f]*'"
            : static fn (string $column): string => "CONVERT(NEW.{$column} USING utf8mb4) COLLATE utf8mb4_bin NOT REGEXP _utf8mb4'^[0-9a-f]{64}$' COLLATE utf8mb4_bin";

        $pathPrefixValue = $driver === 'sqlite'
            ? "('service-evidence/' || NEW.organization_id || '/' || NEW.service_order_id || '/')"
            : "CONCAT('service-evidence/', NEW.organization_id, '/', NEW.service_order_id, '/')";
        $pathPrefix = $driver === 'sqlite'
            ? "substr(NEW.path, 1, length({$pathPrefixValue})) <> {$pathPrefixValue}"
            : "CAST(LEFT(NEW.path, CHAR_LENGTH({$pathPrefixValue})) AS BINARY) <> CAST({$pathPrefixValue} AS BINARY)";
        $pathUnsafe = $driver === 'sqlite'
            ? "NEW.path LIKE '/%' OR instr(NEW.path, '..') > 0 OR instr(NEW.path, char(92)) > 0 OR instr(NEW.path, '://') > 0"
            : "LEFT(NEW.path, 1) = '/' OR INSTR(NEW.path, '..') > 0 OR INSTR(NEW.path, CHAR(92)) > 0 OR INSTR(NEW.path, '://') > 0";
        $storedSuffixValue = $driver === 'sqlite'
            ? "('/' || NEW.stored_filename)"
            : "CONCAT('/', NEW.stored_filename)";
        $storedSuffix = $driver === 'sqlite'
            ? "substr(NEW.path, -length({$storedSuffixValue})) <> {$storedSuffixValue}"
            : "CAST(RIGHT(NEW.path, CHAR_LENGTH({$storedSuffixValue})) AS BINARY) <> CAST({$storedSuffixValue} AS BINARY)";
        $filenameSlash = $driver === 'sqlite'
            ? "instr(NEW.original_filename, '/') > 0 OR instr(NEW.original_filename, char(92)) > 0"
            : "INSTR(NEW.original_filename, '/') > 0 OR INSTR(NEW.original_filename, CHAR(92)) > 0";
        $filenameControl = $driver === 'sqlite'
            ? implode(' OR ', array_map(
                static fn (int $code): string => "instr(NEW.original_filename, char({$code})) > 0",
                [...range(0, 31), 127]
            ))
            : "NEW.original_filename REGEXP '[[:cntrl:]]'";
        $idempotencyUnsafe = $driver === 'sqlite'
            ? "substr(NEW.idempotency_key, 1, 1) GLOB '[^A-Za-z0-9]' OR NEW.idempotency_key GLOB '*[^A-Za-z0-9:._-]*'"
            : "NEW.idempotency_key NOT REGEXP '^[A-Za-z0-9][A-Za-z0-9:._-]{7,190}$'";
        $uuidInvalid = $driver === 'sqlite'
            ? "length(NEW.public_id) <> 36 OR lower(NEW.public_id) <> NEW.public_id OR substr(NEW.public_id, 9, 1) <> '-' OR substr(NEW.public_id, 14, 1) <> '-' OR substr(NEW.public_id, 19, 1) <> '-' OR substr(NEW.public_id, 24, 1) <> '-' OR replace(NEW.public_id, '-', '') GLOB '*[^0-9a-f]*'"
            : "CONVERT(NEW.public_id USING utf8mb4) COLLATE utf8mb4_bin NOT REGEXP _utf8mb4'^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$' COLLATE utf8mb4_bin";
        $storedFilenameInvalid = $driver === 'sqlite'
            ? "NEW.stored_filename <> (NEW.public_id || '.' || NEW.extension)"
            : "CAST(NEW.stored_filename AS BINARY) <> CAST(CONCAT(NEW.public_id, '.', NEW.extension) AS BINARY)";
        $contextNotNormalized = $driver === 'sqlite'
            ? 'NEW.context <> lower(NEW.context)'
            : 'CAST(NEW.context AS BINARY) <> CAST(LOWER(NEW.context) AS BINARY)';
        $diskInvalid = $driver === 'sqlite'
            ? "NEW.disk <> 'local'"
            : "CAST(NEW.disk AS BINARY) <> CAST('local' AS BINARY)";
        $mimeNotNormalized = $driver === 'sqlite'
            ? 'NEW.mime_type <> lower(NEW.mime_type) OR NEW.extension <> lower(NEW.extension)'
            : 'CAST(NEW.mime_type AS BINARY) <> CAST(LOWER(NEW.mime_type) AS BINARY) OR CAST(NEW.extension AS BINARY) <> CAST(LOWER(NEW.extension) AS BINARY)';
        $filenameTrimInvalid = $driver === 'sqlite'
            ? 'NEW.original_filename <> trim(NEW.original_filename)'
            : 'CAST(NEW.original_filename AS BINARY) <> CAST(TRIM(NEW.original_filename) AS BINARY)';

        $checks = [
            ["NEW.context NOT IN ({$contexts})", 'Contexto de evidencia inválido.'],
            [$contextNotNormalized, 'El contexto debe estar normalizado.'],
            ["NEW.context = 'order' AND ({$referenceCount}) <> 0", 'La evidencia general no admite referencia.'],
            ["NEW.context <> 'order' AND ({$referenceCount}) <> 1", 'La evidencia contextual requiere una referencia única.'],
            [$diskInvalid, 'La evidencia debe usar el disco privado local.'],
            ['NEW.size_bytes < 1 OR NEW.size_bytes > 20971520', 'El tamaño de evidencia no está permitido.'],
            [$mimeNotNormalized, 'MIME y extensión deben estar normalizados.'],
            ["NEW.mime_type NOT IN ('image/jpeg','image/png','image/webp','application/pdf','text/plain')", 'Tipo real de archivo no permitido.'],
            ["(NEW.mime_type = 'image/jpeg' AND NEW.extension <> 'jpg') OR (NEW.mime_type = 'image/png' AND NEW.extension <> 'png') OR (NEW.mime_type = 'image/webp' AND NEW.extension <> 'webp') OR (NEW.mime_type = 'application/pdf' AND NEW.extension <> 'pdf') OR (NEW.mime_type = 'text/plain' AND NEW.extension <> 'txt')", 'La extensión interna no coincide con el tipo real.'],
            [$uuidInvalid, 'UUID público inválido.'],
            [$storedFilenameInvalid, 'Nombre interno inválido.'],
            [$hexCheck('sha256'), 'Hash SHA-256 inválido.'],
            [$hexCheck('path_hash'), 'Hash de ruta inválido.'],
            [$hexCheck('fingerprint'), 'Fingerprint inválido.'],
            ["length(NEW.idempotency_key) < 8 OR length(NEW.idempotency_key) > 191 OR {$idempotencyUnsafe}", 'Clave de idempotencia inválida.'],
            ["NEW.original_filename = '' OR {$filenameTrimInvalid} OR {$filenameSlash} OR {$filenameControl}", 'Nombre original inválido.'],
            [$pathUnsafe, 'Ruta privada insegura.'],
            [$pathPrefix, 'La ruta no pertenece al expediente.'],
            [$storedSuffix, 'El nombre interno no coincide con la ruta.'],
            ['NOT EXISTS (SELECT 1 FROM organizations org WHERE org.id = NEW.organization_id AND org.active = 1)', 'La organización no está activa.'],
            ['NOT EXISTS (SELECT 1 FROM service_orders svc_order WHERE svc_order.id = NEW.service_order_id AND svc_order.organization_id = NEW.organization_id)', 'La orden no pertenece a la organización.'],
            ["NOT EXISTS (SELECT 1 FROM organization_memberships mship WHERE mship.organization_id = NEW.organization_id AND mship.user_id = NEW.uploaded_by_user_id AND mship.active = 1 AND mship.role IN ('admin','operator'))", 'El usuario no puede registrar evidencias.'],
        ];

        foreach (self::CONTEXT_COLUMNS as $context => $column) {
            if ($column !== '') {
                $checks[] = [
                    "NEW.context = '{$context}' AND NEW.{$column} IS NULL",
                    'La referencia no corresponde al contexto.',
                ];
            }
        }

        $checks[] = [
            "NEW.context = 'intake' AND NOT EXISTS (SELECT 1 FROM service_order_intakes ref WHERE ref.id = NEW.service_order_intake_id AND ref.organization_id = NEW.organization_id AND ref.service_order_id = NEW.service_order_id)",
            'El ingreso no pertenece al expediente.',
        ];
        $checks[] = [
            "NEW.context = 'diagnostic' AND NOT EXISTS (SELECT 1 FROM service_diagnostics ref WHERE ref.id = NEW.service_diagnostic_id AND ref.organization_id = NEW.organization_id AND ref.service_order_id = NEW.service_order_id)",
            'El diagnóstico no pertenece al expediente.',
        ];
        $checks[] = [
            "NEW.context = 'work_item' AND NOT EXISTS (SELECT 1 FROM service_work_items ref WHERE ref.id = NEW.service_work_item_id AND ref.organization_id = NEW.organization_id AND ref.service_order_id = NEW.service_order_id)",
            'El trabajo no pertenece al expediente.',
        ];
        $checks[] = [
            "NEW.context = 'part_requirement' AND NOT EXISTS (SELECT 1 FROM service_part_requirements ref WHERE ref.id = NEW.service_part_requirement_id AND ref.organization_id = NEW.organization_id AND ref.service_order_id = NEW.service_order_id)",
            'El repuesto no pertenece al expediente.',
        ];
        $checks[] = [
            "NEW.context = 'custody_event' AND NOT EXISTS (SELECT 1 FROM service_custody_events ref WHERE ref.id = NEW.service_custody_event_id AND ref.organization_id = NEW.organization_id AND ref.service_order_id = NEW.service_order_id)",
            'La custodia no pertenece al expediente.',
        ];
        $checks[] = [
            "NEW.context = 'quality_inspection' AND NOT EXISTS (SELECT 1 FROM service_quality_inspections ref WHERE ref.id = NEW.service_quality_inspection_id AND ref.organization_id = NEW.organization_id AND ref.service_order_id = NEW.service_order_id)",
            'El control de calidad no pertenece al expediente.',
        ];
        $checks[] = [
            "NEW.context = 'delivery' AND NOT EXISTS (SELECT 1 FROM service_deliveries ref WHERE ref.id = NEW.service_delivery_id AND ref.organization_id = NEW.organization_id AND ref.service_order_id = NEW.service_order_id)",
            'La entrega no pertenece al expediente.',
        ];
        $checks[] = [
            "NEW.context = 'cancellation_request' AND NOT EXISTS (SELECT 1 FROM service_cancellation_requests ref WHERE ref.id = NEW.service_cancellation_request_id AND ref.organization_id = NEW.organization_id AND ref.service_order_id = NEW.service_order_id)",
            'La solicitud de cancelación no pertenece al expediente.',
        ];
        $checks[] = [
            "NEW.context = 'cancellation_resolution' AND NOT EXISTS (SELECT 1 FROM service_cancellation_resolutions res JOIN service_cancellation_requests req ON req.id = res.service_cancellation_request_id WHERE res.id = NEW.service_cancellation_resolution_id AND res.organization_id = NEW.organization_id AND req.organization_id = NEW.organization_id AND req.service_order_id = NEW.service_order_id)",
            'La resolución de cancelación no pertenece al expediente.',
        ];
        $checks[] = [
            "NEW.context = 'cancellation_return' AND NOT EXISTS (SELECT 1 FROM service_cancellation_returns ref WHERE ref.id = NEW.service_cancellation_return_id AND ref.organization_id = NEW.organization_id AND ref.service_order_id = NEW.service_order_id)",
            'La devolución cancelada no pertenece al expediente.',
        ];
        $checks[] = [
            "NEW.context = 'warranty_claim' AND NOT EXISTS (SELECT 1 FROM service_warranty_claims ref WHERE ref.id = NEW.service_warranty_claim_id AND ref.organization_id = NEW.organization_id AND ref.corrective_service_order_id = NEW.service_order_id)",
            'El reclamo de garantía no pertenece al expediente correctivo.',
        ];
        $checks[] = [
            "NEW.context = 'warranty_resolution' AND NOT EXISTS (SELECT 1 FROM service_warranty_claim_resolutions res JOIN service_warranty_claims clm ON clm.id = res.service_warranty_claim_id WHERE res.id = NEW.service_warranty_claim_resolution_id AND res.organization_id = NEW.organization_id AND clm.organization_id = NEW.organization_id AND clm.corrective_service_order_id = NEW.service_order_id)",
            'La resolución de garantía no pertenece al expediente correctivo.',
        ];
        $checks[] = [
            "NEW.context = 'warranty_return' AND NOT EXISTS (SELECT 1 FROM service_warranty_claim_returns ref WHERE ref.id = NEW.service_warranty_claim_return_id AND ref.organization_id = NEW.organization_id AND ref.corrective_service_order_id = NEW.service_order_id)",
            'La devolución de garantía no pertenece al expediente correctivo.',
        ];

        return $checks;
    }
};
