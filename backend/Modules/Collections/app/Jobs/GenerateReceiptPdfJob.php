<?php

namespace Modules\Collections\Jobs;

use App\Notifications\Concerns\NotifiesResidents;
use App\Notifications\NotificationCategory;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Collections\Models\Payment;
use Modules\Collections\Models\PaymentStatus;

/**
 * GenerateReceiptPdfJob — §8.5.3 / Flujo B: after a payment is confirmed
 * (register or validate), generate the receipt PDF, attach it to the payment's
 * media, and email it to the unit's titular de pago.
 *
 * Queued AFTER COMMIT (ShouldQueueAfterCommit): NEVER runs inside the money
 * transaction, and — critically — is deferred until the OUTERMOST enclosing
 * DB transaction commits. Without this, a caller that nests PaymentService::register()
 * inside its own transaction (DirectChargeService, ContributionService) would dispatch
 * this job while that outer tx is still open; an async worker could then run before the
 * commit and Payment::find() would return null, silently dropping the receipt PDF + email.
 * On the sync queue (dev/test) it runs inline once the tx commits; on redis the
 * QueueTenancyBootstrapper carries the tenant into the job payload (same pattern as Reports).
 * Failures are tolerated — a PDF/email hiccup must never affect the ledger.
 */
class GenerateReceiptPdfJob implements ShouldQueue, ShouldQueueAfterCommit
{
    use Dispatchable;
    use InteractsWithQueue;
    use NotifiesResidents;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(private readonly string $paymentId) {}

    public function handle(): void
    {
        $payment = Payment::with(['unit', 'payment_method', 'applications.charge.concept'])
            ->find($this->paymentId);

        // Only CONFIRMED payments get a receipt (a cancelled/pending row may have
        // changed state between dispatch and execution).
        if (! $payment || $payment->payment_status_id !== PaymentStatus::CONFIRMED || ! $payment->receipt_number) {
            return;
        }

        try {
            $pdfContent = Pdf::loadView('collections::pdf.receipt', [
                'payment' => $payment,
                'generatedAt' => now()->format('d/m/Y H:i'),
            ])->output();

            $payment->addMediaFromString($pdfContent)
                ->usingFileName('recibo-'.$payment->receipt_number.'.pdf')
                ->toMediaCollection('receipt_pdf');
        } catch (\Throwable $e) {
            report($e);

            return;
        }

        $titularId = $this->unitBillingUserId($payment->unit_id);
        if (! $titularId) {
            return;
        }

        // §8.5.3: "Se envía recibo PDF por email" — email channel carries the notice;
        // the PDF is attached to the payment media (downloadable from the app).
        $this->notifyUsers(
            [$titularId],
            'Recibo de pago '.$payment->receipt_number,
            'Tu recibo de pago '.$payment->receipt_number.' ya está disponible. Puedes descargarlo desde tu estado de cuenta.',
            [
                'category' => NotificationCategory::COLLECTIONS,
                'click_action' => '/payment/'.$payment->id,
                'payment_id' => $payment->id,
                'unit_id' => $payment->unit_id,
            ],
            channels: ['log', 'resend_email'],
        );
    }
}
