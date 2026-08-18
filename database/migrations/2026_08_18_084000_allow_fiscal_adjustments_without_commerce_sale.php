<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private const ADJUSTMENT_GUARD_TRIGGERS = [
        'fiscal_documents_origin_guard_insert',
        'fiscal_document_lines_origin_guard_insert',
        'fiscal_adjustment_authorization_block_insert',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->makeCommercialOriginsNullable();

            return;
        }

        $this->assertNoDependentSqliteViews();

        DB::transaction(function (): void {
            $dependentTriggers = $this->captureDependentSqliteTriggers();

            $this->dropSqliteTriggers($dependentTriggers);
            $this->makeCommercialOriginsNullable();
            $this->restoreSqliteTriggers($dependentTriggers);
            $this->createAdjustmentGuardTriggers();
        }, 3);
    }

    public function down(): void
    {
        if (
            DB::table('fiscal_documents')
                ->whereIn('document_type', ['credit_note', 'debit_note'])
                ->exists()
        ) {
            throw new \RuntimeException(
                'No se puede revertir la fundación de ajustes mientras existan notas fiscales.'
            );
        }

        if (DB::getDriverName() !== 'sqlite') {
            $this->makeCommercialOriginsRequired();

            return;
        }

        $this->assertNoDependentSqliteViews();

        DB::transaction(function (): void {
            $dependentTriggers = $this->captureDependentSqliteTriggers();

            $this->dropSqliteTriggers($dependentTriggers);
            $this->makeCommercialOriginsRequired();
            $this->restoreSqliteTriggers(
                $dependentTriggers,
                self::ADJUSTMENT_GUARD_TRIGGERS
            );
        }, 3);
    }

    private function makeCommercialOriginsNullable(): void
    {
        Schema::table('fiscal_documents', function (Blueprint $table): void {
            $table->foreignId('commerce_sale_id')
                ->nullable()
                ->change();
        });

        Schema::table('fiscal_document_lines', function (Blueprint $table): void {
            $table->foreignId('commerce_sale_line_id')
                ->nullable()
                ->change();
        });
    }

    private function makeCommercialOriginsRequired(): void
    {
        Schema::table('fiscal_document_lines', function (Blueprint $table): void {
            $table->foreignId('commerce_sale_line_id')
                ->nullable(false)
                ->change();
        });

        Schema::table('fiscal_documents', function (Blueprint $table): void {
            $table->foreignId('commerce_sale_id')
                ->nullable(false)
                ->change();
        });
    }

    /**
     * @return list<array{name:string,sql:string}>
     */
    private function captureDependentSqliteTriggers(): array
    {
        $rows = DB::select(
            <<<'SQL'
SELECT name, sql
FROM sqlite_master
WHERE type = 'trigger'
  AND sql IS NOT NULL
  AND (
      instr(lower(sql), 'fiscal_documents') > 0
      OR instr(lower(sql), 'fiscal_document_lines') > 0
  )
ORDER BY name
SQL
        );

        return array_map(
            static fn (object $row): array => [
                'name' => (string) $row->name,
                'sql' => (string) $row->sql,
            ],
            $rows
        );
    }

    /**
     * @param list<array{name:string,sql:string}> $triggers
     */
    private function dropSqliteTriggers(array $triggers): void
    {
        foreach ($triggers as $trigger) {
            DB::unprepared(
                'DROP TRIGGER IF EXISTS '
                . $this->quoteSqliteIdentifier($trigger['name'])
            );
        }
    }

    /**
     * @param list<array{name:string,sql:string}> $triggers
     * @param list<string> $excludedNames
     */
    private function restoreSqliteTriggers(
        array $triggers,
        array $excludedNames = []
    ): void {
        foreach ($triggers as $trigger) {
            if (in_array($trigger['name'], $excludedNames, true)) {
                continue;
            }

            DB::unprepared($trigger['sql']);
        }
    }

    private function assertNoDependentSqliteViews(): void
    {
        $views = DB::select(
            <<<'SQL'
SELECT name
FROM sqlite_master
WHERE type = 'view'
  AND sql IS NOT NULL
  AND (
      instr(lower(sql), 'fiscal_documents') > 0
      OR instr(lower(sql), 'fiscal_document_lines') > 0
  )
ORDER BY name
SQL
        );

        if ($views === []) {
            return;
        }

        $names = array_map(
            static fn (object $row): string => (string) $row->name,
            $views
        );

        throw new \RuntimeException(
            'La migración detectó vistas SQLite dependientes del core fiscal: '
            . implode(', ', $names)
            . '. Se requiere una migración explícita para preservarlas.'
        );
    }

    private function quoteSqliteIdentifier(string $identifier): string
    {
        return '"'
            . str_replace('"', '""', $identifier)
            . '"';
    }

    private function createAdjustmentGuardTriggers(): void
    {
        foreach (self::ADJUSTMENT_GUARD_TRIGGERS as $trigger) {
            DB::unprepared(
                'DROP TRIGGER IF EXISTS '
                . $this->quoteSqliteIdentifier($trigger)
            );
        }

        DB::unprepared(
            "CREATE TRIGGER fiscal_documents_origin_guard_insert "
            . "BEFORE INSERT ON fiscal_documents "
            . "WHEN "
            . "(NEW.document_type = 'invoice' AND NEW.commerce_sale_id IS NULL) "
            . "OR "
            . "(NEW.document_type IN ('credit_note','debit_note') "
            . "AND NEW.commerce_sale_id IS NOT NULL) "
            . "BEGIN "
            . "SELECT RAISE(ABORT, 'Fiscal document commercial origin mismatch'); "
            . "END"
        );

        DB::unprepared(
            "CREATE TRIGGER fiscal_document_lines_origin_guard_insert "
            . "BEFORE INSERT ON fiscal_document_lines "
            . "WHEN "
            . "(EXISTS ("
            . "SELECT 1 FROM fiscal_documents "
            . "WHERE fiscal_documents.id = NEW.fiscal_document_id "
            . "AND fiscal_documents.document_type = 'invoice'"
            . ") AND NEW.commerce_sale_line_id IS NULL) "
            . "OR "
            . "(EXISTS ("
            . "SELECT 1 FROM fiscal_documents "
            . "WHERE fiscal_documents.id = NEW.fiscal_document_id "
            . "AND fiscal_documents.document_type IN ('credit_note','debit_note')"
            . ") AND NEW.commerce_sale_line_id IS NOT NULL) "
            . "BEGIN "
            . "SELECT RAISE(ABORT, 'Fiscal document line commercial origin mismatch'); "
            . "END"
        );

        DB::unprepared(
            "CREATE TRIGGER fiscal_adjustment_authorization_block_insert "
            . "BEFORE INSERT ON fiscal_authorization_attempts "
            . "WHEN EXISTS ("
            . "SELECT 1 FROM fiscal_documents "
            . "WHERE fiscal_documents.id = NEW.fiscal_document_id "
            . "AND fiscal_documents.document_type IN ('credit_note','debit_note')"
            . ") "
            . "BEGIN "
            . "SELECT RAISE(ABORT, 'Fiscal adjustment association evidence required before authorization'); "
            . "END"
        );
    }
};
