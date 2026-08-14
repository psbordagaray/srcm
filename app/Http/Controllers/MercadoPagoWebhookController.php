<?php

namespace App\Http\Controllers;

use App\Domain\Finance\MercadoPagoPointWebhookReceiptService;
use App\Jobs\ProcessMercadoPagoPointWebhook;
use DomainException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class MercadoPagoWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        string $connectionPublicId,
        MercadoPagoPointWebhookReceiptService $receipts
    ): Response {
        try {
            $receipt = $receipts->accept(
                $connectionPublicId,
                (string) $request->server('QUERY_STRING', ''),
                $request->getContent(),
                $request->header('content-type'),
                (string) $request->header('x-signature', ''),
                (string) $request->header('x-request-id', '')
            );
        } catch (DomainException) {
            // Intentionally no provider/body/tenant detail in the public
            // response. Invalid or misrouted notifications are not ACKed.
            return response('', 401);
        }

        ProcessMercadoPagoPointWebhook::dispatch(
            $receipt->connectionPublicId,
            $receipt->resourceId,
            $receipt->notificationId
        );

        return response('', 200);
    }
}
