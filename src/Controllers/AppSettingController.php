<?php
declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Models\AppSetting;
use Psr\Http\Message\UploadedFileInterface;

class AppSettingController
{
    private Twig $view;

    public function __construct(Twig $view)
    {
        $this->view = $view;
    }

    public function index(Request $request, Response $response): Response
    {
        $settings = AppSetting::all()->pluck('setting_value', 'setting_key')->toArray();

        $success = $_SESSION['success'] ?? null;
        $error = $_SESSION['error'] ?? null;
        unset($_SESSION['success'], $_SESSION['error']);

        return $this->view->render($response, 'settings/index.twig', [
            'settings' => $settings,
            'success' => $success,
            'error' => $error,
        ]);
    }

    public function save(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        $appName = trim($data['app_name'] ?? '');

        try {
            if ($appName) {
                AppSetting::updateOrCreate(
                    ['setting_key' => 'app_name'],
                    ['setting_value' => $appName]
                );
            }

            $uploadedFiles = $request->getUploadedFiles();
            if (isset($uploadedFiles['app_logo'])) {
                $file = $uploadedFiles['app_logo'];
                if ($file->getError() === UPLOAD_ERR_OK) {
                    AppSetting::updateOrCreate(
                        ['setting_key' => 'app_logo'],
                        [
                            'binary_content' => $file->getStream()->getContents(),
                            'mime_type' => $file->getClientMediaType(),
                            'setting_value' => $file->getClientFilename()
                        ]
                    );
                }
            }

            $_SESSION['success'] = 'Einstellungen erfolgreich gespeichert.';
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Fehler beim Speichern: ' . $e->getMessage();
        }

        return $response->withHeader('Location', '/settings')->withStatus(302);
    }

    public function logo(Request $request, Response $response): Response
    {
        $logo = AppSetting::find('app_logo');
        if ($logo && $logo->binary_content) {
            $response->getBody()->write($logo->binary_content);
            return $response
                ->withHeader('Content-Type', $logo->mime_type)
                ->withHeader('Content-Disposition', 'inline; filename="' . $logo->setting_value . '"');
        }

        // Return default logo if not found
        $defaultLogoPath = __DIR__ . '/../../public/img/logo.png';
        if (file_exists($defaultLogoPath)) {
            $response->getBody()->write(file_get_contents($defaultLogoPath));
            return $response->withHeader('Content-Type', 'image/png');
        }

        return $response->withStatus(404);
    }
}
