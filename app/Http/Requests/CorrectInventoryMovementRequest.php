<?php

namespace App\Http\Requests;

class CorrectInventoryMovementRequest extends StoreInventoryMovementRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('correct-inventory') ?? false;
    }
}
