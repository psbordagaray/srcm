<?php

namespace App\Http\Controllers;

use App\Domain\Import\ProductImportManager;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class ProductImportController extends Controller
{
    public function create(): View
    {
        return view('imports.products', [
            'preview' => null,
        ]);
    }

    public function preview(
        Request $request,
        ProductImportManager $manager
    ): View {
        $validated = $request->validate([
            'file' => [
                'required',
                'file',
                'max:5120',
            ],
        ], [
            'file.required' =>
                'Seleccioná un archivo CSV o .xlsx.',
            'file.file' =>
                'El archivo recibido no es válido.',
            'file.max' =>
                'El archivo no puede superar 5 MB.',
        ]);

        try {
            $preview = $manager->preview(
                $validated['file'],
                $request->user()
            );
        } catch (DomainException $exception) {
            return view('imports.products', [
                'preview' => null,
            ])->withErrors([
                'file' => $exception->getMessage(),
            ]);
        }

        return view('imports.products', compact('preview'));
    }

    public function store(
        Request $request,
        ProductImportManager $manager
    ): RedirectResponse {
        $validated = $request->validate([
            'token' => [
                'required',
                'uuid',
            ],
        ]);

        try {
            $count = $manager->commit(
                $validated['token'],
                $request->user()
            );
        } catch (DomainException $exception) {
            return redirect()
                ->route('product-imports.create')
                ->with(
                    'error',
                    $exception->getMessage()
                );
        }

        return redirect()
            ->route('products.index')
            ->with(
                'success',
                $count.' productos importados correctamente.'
            );
    }

    public function template(): Response
    {
        $header = implode(';', [
            'sku',
            'nombre',
            'categoria',
            'marca',
            'fabricante',
            'descripcion',
            'unidad_base',
            'decimales',
            'activo',
        ]);

        return response(
            "\xEF\xBB\xBF".$header."\r\n",
            200,
            [
                'Content-Type' =>
                    'text/csv; charset=UTF-8',
                'Content-Disposition' =>
                    'attachment; filename="plantilla_productos_srcm.csv"',
                'Cache-Control' =>
                    'no-store, private',
            ]
        );
    }
}
