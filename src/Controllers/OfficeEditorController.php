<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Controllers\Concerns\FileControllerSupport;
use App\Models\FileFolderShare;
use App\Services\Files\FileAccessService;
use App\Services\Files\FileManagementException;
use App\Services\Files\FileService;
use App\Services\Office\OfficeDiscovery;
use App\Services\Office\OfficeDocumentCreator;
use App\Services\Office\OfficeSettings;
use App\Services\Office\OfficeTokenService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Seite mit dem Collabora-Editor. Sie stellt das Zugangstoken aus und schickt es
 * per Formular-POST in den Rahmen - so steht es nicht in der Adresszeile.
 */
final class OfficeEditorController
{
    use FileControllerSupport;

    public function __construct(
        private readonly Twig $view,
        private readonly FileService $files,
        private readonly FileAccessService $access,
        private readonly OfficeDiscovery $discovery,
        private readonly OfficeTokenService $tokens,
        private readonly OfficeSettings $settings,
        private readonly OfficeDocumentCreator $creator
    ) {
    }

    /**
     * Neues, leeres Dokument im Ordner anlegen und gleich im Editor öffnen.
     * Recht wie beim Hochladen; ein vorhandener Name wird nicht überschrieben.
     */
    public function create(Request $request, Response $response, array $args): Response
    {
        $folderId = (int) $args['id'];
        $body = $request->getParsedBody();
        try {
            $file = $this->creator->create(
                $this->actor(),
                $this->liveFolder($folderId),
                self::field($body, 'type'),
                self::field($body, 'name')
            );
        } catch (FileManagementException $exception) {
            $_SESSION['error'] = $exception->getMessage();

            return $this->redirect($response, $this->folderUrl($folderId));
        }

        return $this->redirect($response, '/files/' . $file->id . '/edit?from=folder');
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        $actor = $this->actor();
        try {
            $file = $this->files->findReadable($actor, (int) $args['id']);
        } catch (FileManagementException) {
            $_SESSION['error'] = 'Die Datei existiert nicht oder ist nicht freigegeben.';

            return $this->redirect($response, '/files');
        }

        // Zurück dorthin, wo geöffnet wurde: Aus der Ordnerliste in den Ordner,
        // sonst auf die Detailseite. Nur ein fester Wert, keine Adresse von außen;
        // wer den Ordner nicht sieht (nur Dateifreigabe), landet auf der Detailseite.
        $fromFolder = ($request->getQueryParams()['from'] ?? '') === 'folder'
            && $this->access->levelFor($actor, (int) $file->folder_id) > FileFolderShare::LEVEL_NONE;
        $backUrl = $fromFolder ? $this->folderUrl((int) $file->folder_id) : '/files/' . $file->id;
        if (!$this->discovery->isAvailable()) {
            $_SESSION['error'] = 'Der Office-Server ist gerade nicht erreichbar. Bitte später erneut versuchen.';

            return $this->redirect($response, $backUrl);
        }

        $action = $this->discovery->actionFor((string) $file->name);
        if ($action === null) {
            $_SESSION['error'] = 'Dieser Dateityp lässt sich nicht im Browser öffnen.';

            return $this->redirect($response, $backUrl);
        }

        $mode = $action->modeFor($this->access->fileLevelFor($actor, $file));
        $token = $this->tokens->issue($actor->userId, (int) $file->id);
        $wopiSrc = $this->settings->wopiBaseUrl . '/wopi/files/' . $file->id;

        return $this->view->render($response, 'files/office_editor.twig', [
            'file' => $file,
            'can_edit' => $mode === 'edit',
            'editor_url' => $action->urlSrc . 'WOPISrc=' . rawurlencode($wopiSrc) . '&lang=de&closebutton=1',
            'access_token' => $token->plain,
            'access_token_ttl' => $token->ttlMilliseconds(),
            'office_origin' => $this->settings->serverOrigin(),
            'back_url' => $backUrl,
        ]);
    }
}
