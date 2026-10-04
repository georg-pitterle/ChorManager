<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Event;
use App\Models\Newsletter;
use App\Models\NewsletterRecipient;
use App\Models\NewsletterRecipientSource;
use App\Models\User;
use App\Services\Audience\AudienceFilterNormalizer;
use App\Services\Audience\AudienceFilterService;
use App\Services\Audience\AudienceFormInput;
use App\Services\Audience\InvalidAudienceFilterException;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Collection;

/**
 * Empfänger eines Newsletters: Mitglieder der Zielgruppen-Zeilen ODER der
 * Zielgruppen der gewählten Termine. Ohne Zeile und ohne Termin gibt es keine
 * Empfänger; gespeichert werden darf trotzdem, erst der Versand verlangt welche.
 */
class NewsletterRecipientService
{
    public const OWNER = 'newsletter_id';

    public function __construct(
        private readonly AudienceFilterService $filters = new AudienceFilterService(),
        private readonly AudienceFilterNormalizer $normalizer = new AudienceFilterNormalizer()
    ) {
    }

    /**
     * Zielgruppe aus dem Formular: Zielgruppen-Zeilen (freiwillig) und Termine,
     * deren Zielgruppe mit angeschrieben wird. Newsletter und Vorlage teilen
     * sich diese Prüfung, damit beide dieselbe Auswahl akzeptieren.
     *
     * @param array<string, mixed> $data
     * @return array{sets: list<array<string, list<int>>>, event_ids: list<int>}
     * @throws InvalidAudienceFilterException
     */
    public function readAudience(array $data): array
    {
        $sets = $this->normalizer->normalizeRows(AudienceFormInput::rows($data['audience'] ?? []));

        $ids = [];
        foreach (is_array($data['event_ids'] ?? null) ? $data['event_ids'] : [] as $value) {
            if (is_scalar($value) && ctype_digit((string) $value) && (int) $value > 0) {
                $ids[] = (int) $value;
            }
        }
        $eventIds = $ids === [] ? [] : Event::query()->whereIn('id', array_values(array_unique($ids)))
            ->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all();

        return ['sets' => $sets, 'event_ids' => $eventIds];
    }

    /**
     * Ersetzt Zielgruppen-Zeilen und Termine und löst die Empfänger neu auf.
     *
     * @param list<array<string, list<int>>> $sets
     * @param list<int> $eventIds
     */
    public function setAudience(Newsletter $newsletter, array $sets, array $eventIds): void
    {
        Capsule::connection()->transaction(function () use ($newsletter, $sets, $eventIds): void {
            $this->filters->replaceForOwner(self::OWNER, (int) $newsletter->id, $sets);
            $newsletter->recipientSources()->delete();
            foreach ($eventIds as $eventId) {
                $newsletter->recipientSources()->create([
                    'source_type' => NewsletterRecipientSource::TYPE_EVENT_ATTENDEES,
                    'reference_id' => $eventId,
                ]);
            }
        });

        // Ohne dieses Verwerfen läse resolveRecipients() die vorab geladene und
        // eben gelöschte Auswahl weiter - der Newsletter ginge an die vorherige
        // Zielgruppe statt an die gerade gespeicherte.
        $newsletter->unsetRelation('recipientSources');
        $newsletter->unsetRelation('audienceFilters');

        $this->setRecipients(
            $newsletter,
            $this->resolveRecipients($newsletter)->pluck('id')->map(static fn ($id): int => (int) $id)->all()
        );
    }

    /**
     * @return array{sets: list<array<string, list<int>>>, event_ids: list<int>}
     */
    public function audienceOf(Newsletter $newsletter): array
    {
        $filters = $newsletter->relationLoaded('audienceFilters')
            ? $newsletter->audienceFilters
            : $newsletter->audienceFilters()->with('conditions')->orderBy('id')->get();
        $sources = $newsletter->relationLoaded('recipientSources')
            ? $newsletter->recipientSources
            : $newsletter->recipientSources()->orderBy('id')->get();

        return [
            'sets' => $filters->map(static fn ($filter): array => $filter->conditionSet())->values()->all(),
            'event_ids' => $sources->pluck('reference_id')->map(static fn ($id): int => (int) $id)->values()->all(),
        ];
    }

    /**
     * @return Collection<int, User>
     */
    public function resolveRecipients(Newsletter $newsletter): Collection
    {
        $audience = $this->audienceOf($newsletter);

        return $this->resolveFor($audience['sets'], $audience['event_ids']);
    }

    /**
     * Aktive Mitglieder der Zielgruppen-Zeilen ODER der Zielgruppen der Termine.
     *
     * @param list<array<string, list<int>>> $sets
     * @param list<int> $eventIds
     * @return Collection<int, User>
     */
    public function resolveFor(array $sets, array $eventIds): Collection
    {
        $ids = $this->filters->membersQueryForSets($sets)->pluck('users.id')->all();
        foreach ($eventIds as $eventId) {
            $ids = array_merge($ids, $this->getEventAudience($eventId)->pluck('id')->all());
        }

        $uniqueIds = array_values(array_unique(array_map(static fn ($id): int => (int) $id, $ids)));
        if ($uniqueIds === []) {
            return new Collection();
        }

        return User::query()
            ->whereIn('id', $uniqueIds)
            ->where('is_active', 1)
            ->get();
    }

    /**
     * Die Mitglieder, für die ein Termin gilt - seine Zielgruppe.
     *
     * Aufgelöst wurde das bis Lauf 22 über die Anwesenheitsliste
     * (`attendances.status = 'present'`). Die füllt sich erst, nachdem der
     * Termin stattgefunden hat und jemand sie eingetragen hat. Im Formular
     * stehen aber alle Termine zur Auswahl: Wer einen bevorstehenden nahm -
     * der übliche Fall, "Infos zur Probe am Freitag" -, bekam kommentarlos
     * null Empfänger und konnte den Newsletter gar nicht senden, ohne dass
     * irgendwo stand, woran es lag.
     *
     * Massgeblich ist deshalb dieselbe Zielgruppe, über die auch Einladung und
     * Anwesenheitsliste laufen. `eligibleUsersQuery()` filtert `is_active`
     * bereits selbst.
     *
     * @param int $eventId
     * @return Collection<int, User>
     */
    public function getEventAudience(int $eventId): Collection
    {
        $event = Event::find($eventId);
        if (!$event) {
            return new Collection();
        }

        return $event->eligibleUsersQuery()->get();
    }

    /**
     * Get stored recipients for a newsletter
     *
     * @param int $newsletterId
     * @return Collection<int, NewsletterRecipient>
     */
    public function getRecipients(int $newsletterId): Collection
    {
        return NewsletterRecipient::query()
            ->with(['user.voiceGroups', 'user.subVoices'])
            ->where('newsletter_id', $newsletterId)
            ->get();
    }

    /**
     * Store or update recipients for a newsletter
     *
     * @param Newsletter $newsletter
     * @param array<int> $userIds User IDs
     * @return void
     */
    public function setRecipients(Newsletter $newsletter, array $userIds): void
    {
        // Je Mitglied entsteht genau eine Zeile. `recipient_count` zählte davor die
        // rohe Eingabe und stand damit bei einer doppelten Kennung höher als die
        // Zahl der Empfängerzeilen - in der Oberfläche liest sich das wie Post, die
        // unterwegs verloren ging.
        $uniqueUserIds = array_values(array_unique($userIds));

        $newsletter->recipients()->delete();

        foreach ($uniqueUserIds as $userId) {
            $newsletter->recipients()->create([
                'user_id' => $userId,
                'status' => 'pending',
            ]);
        }

        // Eine vorab geladene Beziehung trägt sonst weiter die gelöschten Zeilen.
        $newsletter->unsetRelation('recipients');

        $newsletter->recipient_count = count($uniqueUserIds);
        $newsletter->save();
    }
}
