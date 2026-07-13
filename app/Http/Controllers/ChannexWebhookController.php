<?php

namespace App\Http\Controllers;

use App\Services\ChannexBookingImporter;
use App\Services\ChannexClient;
use App\Services\ChannexMessageImporter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ChannexWebhookController extends Controller
{
    /**
     * Inbound Channex webhook. Validates a shared secret (Channex has no HMAC),
     * then for a booking event pulls the revision, imports it, and acknowledges
     * it. Processed inline so no queue worker is required. On failure we return
     * non-2xx WITHOUT acking, so Channex re-delivers it later.
     */
    public function handle(Request $request, ChannexClient $channex, ChannexBookingImporter $importer): Response
    {
        // FAIL CLOSED: an unset secret disables the endpoint entirely — never
        // accept an anonymous caller on a public money + PII route.
        $secret = $channex->webhookSecret();
        if ($secret === '' || ! hash_equals($secret, (string) $request->header('X-Channex-Webhook-Secret'))) {
            return response('forbidden', 403);
        }

        $event = (string) $request->input('event');

        // Guest messages: ingest into this hotel's inbox. The payload carries the
        // text directly, so no callback is needed. Property is verified inside the
        // importer so a misdelivered message can't reach another tenant.
        if ($event === 'message') {
            try {
                $summary = app(ChannexMessageImporter::class)->importMessage(
                    (array) $request->input('payload', []),
                    $channex->propertyId(),
                );

                if (($summary['status'] ?? null) === 'foreign_property') {
                    return response('ignored — foreign property', 200);
                }
            } catch (\Throwable $e) {
                report($e);

                return response('error', 500);
            }

            return response('ok', 200);
        }

        $revisionId = (string) $request->input('payload.revision_id');

        // Only booking events carry a revision to import; ari/other events are no-ops.
        if (! str_starts_with($event, 'booking') || $revisionId === '') {
            return response('ignored', 200);
        }
        // The id is interpolated into an outbound API URL carrying our key — it must
        // be a UUID, never an attacker-supplied path/query fragment.
        if (! preg_match('/^[0-9a-fA-F-]{36}$/', $revisionId)) {
            return response('bad revision id', 400);
        }

        try {
            $revision = $channex->getBookingRevision($revisionId);
            if ($revision) {
                $summary = $importer->importRevision($revision, $channex->propertyId());

                // A revision of another property (= another hotel/tenant) delivered
                // to this domain is a Channex misconfiguration: never import it and
                // never ack it — the owning hotel's own endpoint/feed must get it.
                if (($summary['status'] ?? null) === 'foreign_property') {
                    return response('ignored — foreign property', 200);
                }
            }
        } catch (\Throwable $e) {
            report($e);

            return response('error', 500); // import failed -> do NOT ack, Channex re-delivers
        }

        // Ack OUTSIDE the import try: the import already committed, so a transient
        // ack failure must not force a full re-import — the feed/catch-up re-acks.
        try {
            $channex->ackBookingRevision($revisionId);
        } catch (\Throwable $e) {
            report($e);
        }

        return response('ok', 200);
    }
}
