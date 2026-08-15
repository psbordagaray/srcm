<?php

namespace App\Http\Controllers;

use App\Domain\Finance\FinancialStatementCsvImportManager;
use App\Domain\Finance\FinancialStatementCsvMapping;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\FinancialAccountType;
use App\Models\FinancialAccount;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FinancialStatementImportController extends Controller
{
    public function create(
        Request $request,
        CurrentOrganization $currentOrganization
    ): View {
        $organizationId = $currentOrganization->id(
            $request->user()
        );

        $accounts = FinancialAccount::query()
            ->forOrganization($organizationId)
            ->where('active', true)
            ->whereNotIn(
                'type',
                [
                    FinancialAccountType::CashBox->value,
                    FinancialAccountType::CashReserve->value,
                ]
            )
            ->orderBy('currency_code')
            ->orderBy('name')
            ->get();

        return view(
            'financial-reconciliation.import-csv',
            ['accounts' => $accounts]
        );
    }

    public function preview(
        Request $request,
        CurrentOrganization $currentOrganization,
        FinancialStatementCsvImportManager $imports
    ): View|RedirectResponse {
        $validated = $request->validate([
            'financial_account' => [
                'required',
                'uuid',
            ],
            'statement' => [
                'required',
                'file',
                'max:2048',
            ],
            'mapping_mode' => [
                'nullable',
                'in:canonical,mapped',
            ],
            'mapping_delimiter' => [
                'nullable',
                'in:comma,semicolon,tab',
            ],
            'mapping_decimal_separator' => [
                'nullable',
                'in:dot,comma',
            ],
            'mapping_date_format' => [
                'nullable',
                'in:iso8601,ymd_his,dmy_his,dmy',
            ],
            'mapping_timezone' => [
                'nullable',
                'string',
                'max:64',
            ],
            'mapping_occurred_at_header' => [
                'nullable',
                'string',
                'max:191',
            ],
            'mapping_direction_header' => [
                'nullable',
                'string',
                'max:191',
            ],
            'mapping_gross_amount_header' => [
                'nullable',
                'string',
                'max:191',
            ],
            'mapping_fee_amount_header' => [
                'nullable',
                'string',
                'max:191',
            ],
            'mapping_withholding_amount_header' => [
                'nullable',
                'string',
                'max:191',
            ],
            'mapping_net_amount_header' => [
                'nullable',
                'string',
                'max:191',
            ],
            'mapping_external_operation_id_header' => [
                'nullable',
                'string',
                'max:191',
            ],
            'mapping_reference_header' => [
                'nullable',
                'string',
                'max:191',
            ],
            'mapping_credit_value' => [
                'nullable',
                'string',
                'max:100',
            ],
            'mapping_debit_value' => [
                'nullable',
                'string',
                'max:100',
            ],
        ]);

        $organizationId = $currentOrganization->id(
            $request->user()
        );

        $account = FinancialAccount::query()
            ->forOrganization($organizationId)
            ->where(
                'public_id',
                $validated['financial_account']
            )
            ->firstOrFail();

        $file = $request->file('statement');

        if (! $file) {
            return back()->withErrors([
                'statement_import' =>
                    'No se recibió el archivo CSV/XLSX.',
            ]);
        }

        try {
            $mapping =
                ($validated['mapping_mode'] ?? 'canonical')
                    === 'mapped'
                ? FinancialStatementCsvMapping::fromInput(
                    $validated
                )
                : FinancialStatementCsvMapping::canonical();

            $extension = strtolower(
                $file->getClientOriginalExtension()
            );

            $staged = match ($extension) {
                'csv' => $imports->stage(
                    $account,
                    $file->getRealPath(),
                    $file->getClientOriginalName(),
                    $request->user(),
                    $mapping
                ),
                'xlsx' => $imports->stageXlsx(
                    $account,
                    $file->getRealPath(),
                    $file->getClientOriginalName(),
                    $request->user(),
                    $mapping
                ),
                default => throw new DomainException(
                    'P7.4 sólo admite archivos CSV o XLSX.'
                ),
            };

            $preview = $staged['preview'];
            $token = $staged['token'];
        } catch (DomainException $exception) {
            return back()
                ->withInput(
                    $request->except('statement')
                )
                ->withErrors([
                    'statement_import' =>
                        $exception->getMessage(),
                ]);
        }

        return view(
            'financial-reconciliation.import-csv-preview',
            [
                'preview' => $preview,
                'token' => $token,
            ]
        );
    }

    public function store(
        Request $request,
        FinancialStatementCsvImportManager $imports
    ): RedirectResponse {
        $validated = $request->validate([
            'token' => [
                'required',
                'uuid',
            ],
        ]);

        try {
            $result = $imports->commit(
                $validated['token'],
                $request->user()
            );
        } catch (DomainException $exception) {
            return redirect()
                ->route(
                    'financial-statement-imports.csv.create'
                )
                ->with(
                    'error',
                    $exception->getMessage()
                );
        }

        return redirect()
            ->route('financial-reconciliation.index')
            ->with(
                'success',
                'Extracto importado: '
                    .$result['created'].' nuevo(s), '
                    .$result['deduplicated']
                    .' ya existente(s). '
                    .'No se conciliaron automáticamente.'
            );
    }
}
