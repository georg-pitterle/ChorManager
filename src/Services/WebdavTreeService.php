<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Attachment;
use App\Models\Project;
use App\Util\DownloadFileName;

/**
 * Was jemand über WebDAV sieht: die eigenen Projekte, deren Lieder und deren
 * Anhänge.
 *
 * Diese Klasse ist die **einzige** Rechteprüfung des Noten-Ordners. Das ist
 * Absicht: Ein Pfad wird aufgelöst, indem der Baum der anfragenden Person
 * gebaut und der Name darin gesucht wird - nie, indem eine Kennung aus dem Pfad
 * gelesen und nachträglich geprüft wird. Damit ist jede erreichbare Datei schon
 * durch die Projektmitgliedschaft gedeckt, und eine vergessene zweite Prüfung
 * kann es gar nicht geben.
 *
 * Bewusst nicht benutzt wird AttachmentAccessRegistry: Die liest ihre Rechte
 * aus `$_SESSION`, und ein WebDAV-Zugriff hat keine Sitzung. Die Regel hier ist
 * dieselbe wie auf der Seite /downloads (DownloadController::index) - wer im
 * Projekt singt, sieht dessen Noten.
 *
 * Der Baum wird je Anfrage einmal vollständig gebaut und im Objekt behalten:
 * Ein PROPFIND löst erst den Pfad auf und listet dann dessen Kinder, das wären
 * sonst zwei Läufe. Für die Datenmenge eines Chores - einige Projekte mit
 * einigen hundert Anhängen, alle ohne Datei-Inhalt - ist das billiger als eine
 * Abfrage je Ebene.
 */
class WebdavTreeService
{
    /**
     * Alles darüber ist kein Dateisuffix mehr, sondern Teil des Namens.
     * Gleiche Grenze wie in EntityAttachmentService.
     */
    private const MAX_EXTENSION_LENGTH = 20;

    /** @var array<int, array<string, WebdavNode>> */
    private array $nodes = [];

    /** @var array<int, array<string, list<WebdavNode>>> */
    private array $children = [];

    public function resolve(int $userId, string $path): ?WebdavNode
    {
        $this->build($userId);

        return $this->nodes[$userId][$this->normalize($path)] ?? null;
    }

    /**
     * @return list<WebdavNode>
     */
    public function children(int $userId, WebdavNode $node): array
    {
        if (!$node->isCollection) {
            return [];
        }

        $this->build($userId);

        return $this->children[$userId][$node->path] ?? [];
    }

    /**
     * Lädt den Datei-Inhalt - die einzige Stelle, die `file_content` anfasst.
     * Der Knoten muss aus resolve() stammen; damit ist die Berechtigung schon
     * entschieden.
     */
    public function loadFile(WebdavNode $node): ?Attachment
    {
        if ($node->isCollection || $node->attachmentId === null) {
            return null;
        }

        $attachment = Attachment::where('id', $node->attachmentId)
            ->where('entity_type', 'song')
            ->first();

        return $attachment instanceof Attachment ? $attachment : null;
    }

    private function normalize(string $path): string
    {
        return trim($path, '/');
    }

    private function build(int $userId): void
    {
        if (isset($this->nodes[$userId])) {
            return;
        }

        $root = new WebdavNode('', '', true);
        $nodes = ['' => $root];
        $children = ['' => []];

        foreach ($this->loadProjects($userId) as $project) {
            $projectName = $this->uniqueName((string) $project->name, $children['']);
            $projectNode = new WebdavNode($projectName, $projectName, true);

            $nodes[$projectNode->path] = $projectNode;
            $children[''][] = $projectNode;
            $children[$projectNode->path] = [];

            foreach ($project->assignedSongs as $song) {
                $songName = $this->uniqueName((string) $song->title, $children[$projectNode->path]);
                $songPath = $projectNode->path . '/' . $songName;
                $songNode = new WebdavNode(
                    $songPath,
                    $songName,
                    true,
                    null,
                    0,
                    '',
                    $this->formatDate($song->created_at)
                );

                $nodes[$songPath] = $songNode;
                $children[$projectNode->path][] = $songNode;
                $children[$songPath] = [];

                foreach ($song->attachments as $attachment) {
                    $fileName = $this->uniqueName((string) $attachment->original_name, $children[$songPath]);
                    $filePath = $songPath . '/' . $fileName;
                    $fileNode = new WebdavNode(
                        $filePath,
                        $fileName,
                        false,
                        (int) $attachment->id,
                        (int) $attachment->file_size,
                        (string) $attachment->mime_type,
                        $this->formatDate($attachment->created_at)
                    );

                    $nodes[$filePath] = $fileNode;
                    $children[$songPath][] = $fileNode;
                }
            }
        }

        $this->nodes[$userId] = $nodes;
        $this->children[$userId] = $children;
    }

    /**
     * Dieselbe Abfrage wie DownloadController::index(), nur ohne den
     * Datei-Inhalt: Ein PROPFIND listet Namen und Größen und darf dafür keine
     * BLOBs durch den Speicher ziehen.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Project>
     */
    private function loadProjects(int $userId)
    {
        return Project::query()
            ->select('projects.*')
            ->join('project_users', 'project_users.project_id', '=', 'projects.id')
            ->where('project_users.user_id', $userId)
            ->with([
                'assignedSongs' => function ($query) {
                    $query->orderBy('title', 'asc')->orderBy('songs.id', 'asc');
                },
                'assignedSongs.attachments' => function ($query) {
                    $query->select(EntityAttachmentService::METADATA_COLUMNS)
                        ->orderBy('original_name', 'asc')
                        ->orderBy('id', 'asc');
                },
            ])
            ->distinct()
            ->chronological()
            ->get();
    }

    /**
     * Ein Name aus der Datenbank ist noch kein Dateiname: Ein Schrägstrich in
     * "Kyrie / Gloria" würde eine Ebene vortäuschen, die es nicht gibt, und ein
     * Steuerzeichen bricht die XML-Antwort auf. Zwei gleich benannte Lieder
     * dürfen sich außerdem nicht gegenseitig verdecken - der Nachzügler bekommt
     * eine Nummer, und zwar vor der Dateiendung, damit die Noten-App die Datei
     * weiterhin als PDF erkennt.
     *
     * @param list<WebdavNode> $siblings
     */
    private function uniqueName(string $raw, array $siblings): string
    {
        $name = DownloadFileName::sanitize(
            (string) preg_replace('/[\x00-\x1F\x7F]/u', '_', $raw)
        );

        $taken = [];
        foreach ($siblings as $sibling) {
            $taken[mb_strtolower($sibling->name)] = true;
        }

        if (!isset($taken[mb_strtolower($name)])) {
            return $name;
        }

        for ($index = 2; $index < 1000; $index++) {
            $candidate = $this->withSuffix($name, $index);
            if (!isset($taken[mb_strtolower($candidate)])) {
                return $candidate;
            }
        }

        return $this->withSuffix($name, random_int(1000, 999999));
    }

    private function withSuffix(string $name, int $index): string
    {
        $dot = strrpos($name, '.');
        $suffix = ' (' . $index . ')';

        if ($dot === false || $dot === 0 || strlen($name) - $dot - 1 > self::MAX_EXTENSION_LENGTH) {
            return $name . $suffix;
        }

        return substr($name, 0, $dot) . $suffix . substr($name, $dot);
    }

    private function formatDate(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }
}
