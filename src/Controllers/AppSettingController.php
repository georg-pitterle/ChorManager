<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Models\AppSetting;
use App\Util\UploadValidator;

class AppSettingController
{
    public const DEFAULT_PRIMARY_COLOR = '#E8A817';
    private const MAX_LOGO_SIZE = 2097152; // 2 MB
    /** @var array<int, string> */
    private const ALLOWED_LOGO_MIME_TYPES = [
        'image/png',
        'image/jpeg',
        'image/webp',
        'image/gif',
    ];

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
        $mailQueueTriggerMode = self::normalizeMailQueueTriggerMode($data['mailqueue_trigger_mode'] ?? null);
        $mailQueueOpportunisticRateLimit = self::normalizePositiveInteger(
            $data['mailqueue_opportunistic_rate_limit'] ?? null,
            10
        );
        $mailQueueBatchSize = self::normalizePositiveInteger($data['mailqueue_batch_size'] ?? null, 50);

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

            AppSetting::updateOrCreate(
                ['setting_key' => 'mailqueue_trigger_mode'],
                [
                    'setting_value' => $mailQueueTriggerMode,
                    'binary_content' => '',
                    'mime_type' => 'text/plain',
                ]
            );

            AppSetting::updateOrCreate(
                ['setting_key' => 'mailqueue_opportunistic_rate_limit'],
                [
                    'setting_value' => (string) $mailQueueOpportunisticRateLimit,
                    'binary_content' => '',
                    'mime_type' => 'text/plain',
                ]
            );

            AppSetting::updateOrCreate(
                ['setting_key' => 'mailqueue_batch_size'],
                [
                    'setting_value' => (string) $mailQueueBatchSize,
                    'binary_content' => '',
                    'mime_type' => 'text/plain',
                ]
            );

            $uploadedFiles = $request->getUploadedFiles();
            if (isset($uploadedFiles['app_logo'])) {
                $file = $uploadedFiles['app_logo'];
                $uploadError = UploadValidator::getUploadErrorMessage($file->getError(), 'Logo-Datei');
                if ($uploadError !== null) {
                    $_SESSION['error'] = $uploadError;
                    return $response->withHeader('Location', '/settings')->withStatus(302);
                }

                if ($file->getError() === UPLOAD_ERR_OK) {
                    $size = (int) $file->getSize();
                    $mimeType = UploadValidator::detectMimeType($file);

                    // Use validateImageSize() for 2MB image limit
                    $validation = UploadValidator::validateImageSize($size, $mimeType);
                    if (!$validation['valid']) {
                        $_SESSION['error'] = $validation['error'];
                        return $response->withHeader('Location', '/settings')->withStatus(302);
                    }

                    $safeName = self::normalizeFileName((string) $file->getClientFilename());

                    AppSetting::updateOrCreate(
                        ['setting_key' => 'app_logo'],
                        [
                            'binary_content' => $file->getStream()->getContents(),
                            'mime_type' => $mimeType,
                            'setting_value' => $safeName
                        ]
                    );
                }
            }

            $_SESSION['success'] = 'Einstellungen erfolgreich gespeichert.';
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Fehler beim Speichern: ';
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

        $themeRgb = self::hexToRgb($themeColor);
        $themeStrong = self::darkenHex($themeColor, 16);

        $css = ':root, [data-bs-theme="light"] {' . "\n"
            . "    --theme-primary: {$themeColor};\n"
            . "    --theme-primary-rgb: {$themeRgb};\n"
            . "    --theme-primary-strong: {$themeStrong};\n"
            . "    --bs-primary: {$themeColor};\n"
            . "    --bs-primary-rgb: {$themeRgb};\n"
            . "}\n";

        $response->getBody()->write($css);

        return $response
            ->withHeader('Content-Type', 'text/css; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store, max-age=0');
    }

    private static function hexToRgb(string $hexColor): string
    {
        $normalized = ltrim(self::normalizePrimaryColor($hexColor), '#');

        $red = hexdec(substr($normalized, 0, 2));
        $green = hexdec(substr($normalized, 2, 2));
        $blue = hexdec(substr($normalized, 4, 2));

        return sprintf('%d, %d, %d', $red, $green, $blue);
    }

    private static function darkenHex(string $hexColor, int $percentage): string
    {
        $normalized = ltrim(self::normalizePrimaryColor($hexColor), '#');
        $factor = max(0.0, min(1.0, 1 - ($percentage / 100)));

        $red = (int) round(hexdec(substr($normalized, 0, 2)) * $factor);
        $green = (int) round(hexdec(substr($normalized, 2, 2)) * $factor);
        $blue = (int) round(hexdec(substr($normalized, 4, 2)) * $factor);

        return sprintf('#%02X%02X%02X', $red, $green, $blue);
    }

    private static function normalizeFileName(string $name): string
    {
        $safe = str_replace(["\r", "\n", '"', '\\', '/'], '_', $name);
        $trimmed = trim($safe);
        return $trimmed !== '' ? $trimmed : 'download';
    }

    public function logo(Request $request, Response $response): Response
    {
        $logo = AppSetting::find('app_logo');
        if ($logo && $logo->binary_content) {
            $response->getBody()->write($logo->binary_content);
            $safeName = self::normalizeFileName((string) $logo->setting_value);
            return $response
                ->withHeader('Content-Type', $logo->mime_type)
                ->withHeader(
                    'Content-Disposition',
                    'inline; filename="' . $safeName . '"; filename*=UTF-8\'\'' . rawurlencode($safeName)
                );
        }

        // Return default logo if not found
        $defaultLogoPath = __DIR__ . '/../../public/icons/icon-512.png';
        if (file_exists($defaultLogoPath)) {
            $response->getBody()->write(file_get_contents($defaultLogoPath));
            return $response->withHeader('Content-Type', 'image/png');
        }

        return $response->withStatus(404);
    }

    private static function normalizeMailQueueTriggerMode(?string $value): string
    {
        $candidate = strtolower(trim((string) $value));
        $allowed = ['cron', 'opportunistic', 'hybrid'];

        return in_array($candidate, $allowed, true) ? $candidate : 'hybrid';
    }

    private static function normalizePositiveInteger(?string $value, int $default): int
    {
        $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $parsed !== false ? (int) $parsed : $default;
    }
}
