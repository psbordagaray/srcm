<?php

namespace App\Http\Controllers;

use App\Domain\Commerce\OrganizationProductPriceManager;
use App\Http\Requests\StoreOrganizationProductPriceRequest;
use App\Models\CatalogProduct;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;
use Illuminate\Http\RedirectResponse;

class OrganizationProductPriceController extends Controller
{
    public function update(
        StoreOrganizationProductPriceRequest $request,
        CatalogProduct $product,
        OrganizationProductPriceManager $manager
    ): RedirectResponse {
        $validated = $request->validated();

        try {
            $manager->set(
                $product,
                $validated['currency_code'],
                $this->moneyMinor($validated['amount']),
                $validated['reason'] ?? null,
                $request->user()
            );
        } catch (DomainException $exception) {
            return back()
                ->withInput()
                ->withErrors([
                    'price' => $exception->getMessage(),
                ]);
        }

        return back()->with(
            'success',
            "Precio {$validated['currency_code']} actualizado para {$product->name}."
        );
    }

    private function moneyMinor(string $value): int
    {
        return (int) (string) BigDecimal::of(
            str_replace(',', '.', trim($value))
        )
            ->multipliedBy(100)
            ->toScale(0, RoundingMode::Unnecessary)
            ->toBigInteger();
    }
}
