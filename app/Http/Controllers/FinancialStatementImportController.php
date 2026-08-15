<?php

namespace App\Http\Controllers;

use App\Domain\Finance\FinancialStatementCsvPreviewer;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\FinancialAccountType;
use App\Models\FinancialAccount;
use DomainException;
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
        FinancialStatementCsvPreviewer $previewer
    ): View|\Illuminate\Http\RedirectResponse {
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
            $preview = $previewer->preview(
                $account,
                $file->getRealPath(),
                $file->getClientOriginalName(),
                $request->user()
            );
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
            ['preview' => $preview]
        );
    }
}
