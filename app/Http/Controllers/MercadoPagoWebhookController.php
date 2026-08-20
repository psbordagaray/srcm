<?php

namespace App\Http\Controllers;

use App\Domain\Finance\MercadoPagoPointWebhookReceiptService;
use App\Jobs\ProcessMercadoPagoPointWebhook;
use DomainException;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class MercadoPagoWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        string $connectionPublicId,
        MercadoPagoPointWebhookReceiptService $receipts,
        QueueFactory $queues
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
            Log::warning('integration.webhook_rejected', [
                'integration' => 'mercado_pago_point',
                'reason' => 'validation',
            ]);

            // Intentionally no provider/body/tenant detail in the public
            // response. Invalid or misrouted notifications are not ACKed.
            return response('', 401);
        }

        $correlation = $request->attributes->get('correlation_id');
        $correlation = is_string($correlation) && Str::isUuid($correlation)
            ? strtolower($correlation)
            : null;

        $queues->connection()->push(
            new ProcessMercadoPagoPointWebhook(
                $receipt->connectionPublicId,
                $receipt->resourceId,
                $receipt->notificationId,
                $correlation
            )
        );

        Log::info('integration.webhook_queued', [
            'integration' => 'mercado_pago_point',
            'notification_id_present' => $receipt->notificationId !== null,
        ]);

        return response('', 200);
    }
}
