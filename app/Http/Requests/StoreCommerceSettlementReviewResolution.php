<?php

namespace App\Http\Requests;

use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\CommerceSettlementReviewResolutionOutcome;
use App\Models\CommerceSettlementReview;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCommerceSettlementReviewResolution extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if (
            ! $user
            || ! $user->can(
                'resolve-commerce-settlement-review'
            )
        ) {
            return false;
        }

        $review = $this->route(
            'commerceSettlementReview'
        );

        if (! $review instanceof CommerceSettlementReview) {
            return false;
        }

        $organizationId = app(CurrentOrganization::class)
            ->id($user);

        abort_unless(
            (int) $review->organization_id
                === $organizationId,
            404
        );

        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'outcome' => strtolower(
                trim((string) $this->input('outcome'))
            ),
            'reason' => trim(
                (string) $this->input('reason')
            ),
            'notes' => filled($this->input('notes'))
                ? trim((string) $this->input('notes'))
                : null,
            'idempotency_key' => trim(
                (string) $this->input(
                    'idempotency_key'
                )
            ),
        ]);
    }

    public function rules(): array
    {
        return [
            'outcome' => [
                'required',
                Rule::enum(
                    CommerceSettlementReviewResolutionOutcome::class
                ),
            ],
            'reason' => [
                'required',
                'string',
                'min:10',
                'max:1000',
            ],
            'notes' => [
                'nullable',
                'string',
                'max:2000',
            ],
            'idempotency_key' => [
                'required',
                'string',
                'max:180',
            ],
        ];
    }
}
