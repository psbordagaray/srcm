<?php

namespace App\Http\Controllers;

use App\Domain\Attention\OperationalAttentionManager;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OperationalAttentionController extends Controller
{
    public function acknowledge(
        Request $request,
        OperationalAttentionManager $manager
    ): RedirectResponse {
        $validated = $request->validate([
            'attention_key' => [
                'required',
                'string',
                'regex:/^[a-f0-9]{64}$/',
            ],
        ]);

        try {
            $manager->acknowledge(
                $request->user(),
                $validated['attention_key']
            );
        } catch (DomainException $exception) {
            return back()->withErrors([
                'attention' => $exception->getMessage(),
            ]);
        }

        return back()->with(
            'success',
            'Aviso operativo marcado como visto.'
        );
    }
}
