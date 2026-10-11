<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Activity;
use App\Models\Comment;
use App\Models\UserNotification;
use Psr\Log\NullLogger;

/**
 * Räumt ab, was über `entity_type`/`entity_id` an einer Entität hängt.
 *
 * Vier Tabellen zeigen so auf ihr Objekt: `attachments`, `comments`,
 * `activities` und `user_notifications`. Einen Fremdschlüssel können sie nicht
 * tragen, weil das Ziel je Zeile in einer anderen Tabelle steht - die Datenbank
 * nimmt beim Löschen also nichts davon mit, und das Aufräumen bleibt am
 * löschenden Codepfad hängen.
 *
 * Vorher stand dieser Schritt als eigene Abfrage in jedem Controller, der
 * löscht. Die Kopien liefen auseinander: Sponsor, Vereinbarung, Lied und
 * Aufgabe räumten ihre Anhänge ab, die Notizen von Aufgabe und Termin, der
 * Verlauf einer Aufgabe und die Dateien eines Newsletter-Entwurfs blieben
 * liegen. Unsichtbar war das in beide Richtungen: Die Zeilen sind über die
 * Oberfläche nicht mehr erreichbar, und ein BLOB in `attachments.file_content`
 * wächst still mit.
 *
 * Deshalb eine Naht statt einer weiteren Kopie - wer löscht, ruft hier an und
 * muss nicht wissen, welche vier Tabellen es gerade sind.
 *
 * `user_notifications` kam als vierte dazu: Die Glocke führte ihre Einträge zu
 * einem gelöschten Termin oder einer gelöschten Aufgabe weiterhin, und der Klick
 * landete im Nichts. Schwerer wog der Text: Ein Eintrag aus einem Kommentar
 * trägt dessen erste Zeile als `body`. Die einzelne Bemerkung zog ihn über
 * `comment_id` nach (InAppNotificationStore::deleteForComment), das Löschen des
 * ganzen Objekts aber nicht - der Kommentartext blieb in der Glocke aller
 * Beteiligten lesbar, obwohl die Zeile in `comments` längst weg war.
 */
class EntityCleanupService
{
    /**
     * Die Anhänge laufen über EntityAttachmentService und nicht über eine
     * eigene Abfrage: "Anhänge dieser Entitäten entfernen" soll an einer Stelle
     * definiert bleiben. Der Logger dort trägt nur das Hochladen und Ausliefern,
     * nicht das Löschen - der NullLogger als Vorgabe genügt deshalb für die
     * Aufrufe, die den Dienst ohne Container bauen (Tests, CLI).
     */
    public function __construct(
        private readonly EntityAttachmentService $attachments = new EntityAttachmentService(new NullLogger())
    ) {
    }

    /**
     * Entfernt Anhänge, Notizen und Verlauf der genannten Entitäten.
     *
     * Aufzurufen **vor** dem Löschen der Entität selbst, solange ihre Kennung
     * noch feststeht. Geprüft wird nicht, ob es die Entität noch gibt: Der
     * Aufrufer löscht sie gerade, und eine Kennung ohne Anhängsel kostet hier
     * nichts.
     *
     * @param list<int> $entityIds
     * @return array{attachments:int, comments:int, activities:int, notifications:int}
     *         Je Tabelle die Zahl entfernter Zeilen
     */
    public function purgeForEntities(string $entityType, array $entityIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $entityIds)));

        if ($ids === []) {
            return ['attachments' => 0, 'comments' => 0, 'activities' => 0, 'notifications' => 0];
        }

        return [
            'attachments' => $this->attachments->deleteAllForEntities($entityType, $ids),
            'comments' => Comment::where('entity_type', $entityType)->whereIn('entity_id', $ids)->delete(),
            'activities' => Activity::where('entity_type', $entityType)->whereIn('entity_id', $ids)->delete(),
            'notifications' => UserNotification::where('entity_type', $entityType)
                ->whereIn('entity_id', $ids)
                ->delete(),
        ];
    }

    /**
     * @return array{attachments:int, comments:int, activities:int, notifications:int}
     */
    public function purgeForEntity(string $entityType, int $entityId): array
    {
        return $this->purgeForEntities($entityType, [$entityId]);
    }
}
