<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Models\AppSetting;

class AppSettingController
{
    public const DEFAULT_PRIMARY_COLOR = '#E8A817';

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
        $primaryColor = self::normalizePrimaryColor($data['primary_color'] ?? null);

        try {
            if ($appName) {
                AppSetting::updateOrCreate(
                    ['setting_key' => 'app_name'],
                    [
                        'setting_value' => $appName,
                        'binary_content' => '',
                        'mime_type' => 'text/plain'
                    ]
                );
            }

            AppSetting::updateOrCreate(
                ['setting_key' => 'primary_color'],
                [
                    'setting_value' => $primaryColor,
                    'binary_content' => '',
                    'mime_type' => 'text/plain',
                ]
            );

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

    public static function normalizePrimaryColor(?string $value): string
    {
        $candidate = strtoupper(trim((string) $value));

        if ($candidate === '') {
            return self::DEFAULT_PRIMARY_COLOR;
        }

        if ($candidate[0] !== '#') {
            $candidate = '#' . $candidate;
        }

        if (preg_match('/^#([A-F0-9]{6}|[A-F0-9]{3})$/', $candidate) !== 1) {
            return self::DEFAULT_PRIMARY_COLOR;
        }

        if (strlen($candidate) === 4) {
            return sprintf(
                '#%1$s%1$s%2$s%2$s%3$s%3$s',
                $candidate[1],
                $candidate[2],
                $candidate[3]
            );
        }

        return $candidate;
    }

    public function themeCss(Request $request, Response $response): Response
    {
        $themeColor = self::DEFAULT_PRIMARY_COLOR;

        try {
            $themeColor = self::normalizePrimaryColor(AppSetting::query()->find('primary_color')?->setting_value);
        } catch (\Throwable $exception) {
            $themeColor = self::DEFAULT_PRIMARY_COLOR;
        }

        $css = ':root, [data-bs-theme="light"] {' . "\n"
            . "    --theme-primary: {$themeColor};\n"
            . "    --bs-primary: {$themeColor};\n"
            . "}\n";

        $response->getBody()->write($css);

        return $response
            ->withHeader('Content-Type', 'text/css; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store, max-age=0');
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
