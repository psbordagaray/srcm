<?php

namespace App\Http\Controllers;

use App\Domain\Finance\FinancialStatementCsvImportManager;
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
                    'No se recibió el archivo CSV.',
            ]);
        }

        try {
            $staged = $imports->stage(
                $account,
                $file->getRealPath(),
                $file->getClientOriginalName(),
                $request->user()
            );

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
                'Extracto CSV importado: '
                    .$result['created'].' nuevo(s), '
                    .$result['deduplicated']
                    .' ya existente(s). '
                    .'No se conciliaron automáticamente.'
            );
    }
}
