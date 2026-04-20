<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\MailDeliveryEvent;
use App\Models\MailQueue;
use InvalidArgumentException;

final class MailEventMapperService
{
    public function mapEvent(array $event): void
    {
        $idempotencyKey = trim((string) ($event['idempotency_key'] ?? ''));
        if ($idempotencyKey === '') {
            throw new InvalidArgumentException('Missing idempotency_key.');
        }

        $eventData = $event;
        $eventData['idempotency_key'] = $idempotencyKey;

        $deliveryEvent = MailDeliveryEvent::query()->firstOrCreate(
            ['idempotency_key' => $idempotencyKey],
            $eventData
        );

        // Reconcile queue state from the persisted event row so duplicate deliveries
        // can still repair missed queue updates without creating duplicate event rows.
        $mailQueueId = (int) ($deliveryEvent->mail_queue_id ?? 0);
        if ($mailQueueId <= 0) {
            return;
        }

        $queue = MailQueue::query()->find($mailQueueId);
        if (!$queue instanceof MailQueue) {
            return;
        }

        $normalizedType = (string) ($deliveryEvent->event_type_normalized ?? '');
        $occurredAt = $deliveryEvent->occurred_at;

        $baseUpdate = [
            'last_event_type' => $normalizedType,
            'last_event_at' => $occurredAt,
        ];

        if ($normalizedType === 'delivered') {
            $queue->update($baseUpdate + [
                'delivery_status' => 'delivered',
                'delivered_at' => $occurredAt,
            ]);

            return;
        }

        if ($normalizedType === 'bounced') {
            $queue->update($baseUpdate + [
                'delivery_status' => 'bounced',
                'bounced_at' => $occurredAt,
            ]);

            return;
        }

        if ($normalizedType === 'complained') {
            $queue->update($baseUpdate + [
                'delivery_status' => 'complained',
                'complained_at' => $occurredAt,
            ]);
        }
    }
}
