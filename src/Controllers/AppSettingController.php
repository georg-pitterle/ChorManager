<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Controllers\Concerns\FlashMessages;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Models\AppSetting;
use App\Services\NotificationService;
use App\Util\NotificationType;
use App\Util\UploadValidator;
use App\Services\NameFormatterService;
use Psr\Log\LoggerInterface;
use App\Util\DownloadFileName;
use App\Util\InputValidator;
use App\Util\PrimaryColor;

class AppSettingController
{
    use FlashMessages;

    /** @var array<int, string> */
    public const LOG_LEVELS = [
        'DEBUG',
        'INFO',
        'NOTICE',
        'WARNING',
        'ERROR',
        'CRITICAL',
        'ALERT',
        'EMERGENCY',
    ];
    private const MAX_LOGO_SIZE = 2097152; // 2 MB
    /** @var array<int, string> */
    private const ALLOWED_LOGO_MIME_TYPES = [
        'image/png',
        'image/jpeg',
        'image/webp',
        'image/gif',
    ];

    private Twig $view;
    /**
     * Vorlaufzeiten der termingesteuerten Erinnerungen, mit ihrer Vorgabe in
     * Tagen. 0 schaltet die jeweilige Erinnerung ab - wie bei
     * `registration_reminder_days_before`.
     */
    private const NOTIFICATION_DAY_SETTINGS = [
        'notification_task_due_days_before' => 3,
        'notification_sponsoring_follow_up_days_before' => 1,
    ];

    /**
     * Kennungen der Erklärvideos (nicht gelistet auf YouTube), die die
     * Sponsoring-Übersicht in ihrer Kurzanleitung anbietet.
     */
    public const VIDEO_SETTINGS = [
        'sponsoring_video_member',
        // Dasselbe Video im Hochformat fürs Handy; leer heißt: dort das normale.
        'sponsoring_video_member_portrait',
        'sponsoring_video_admin',
    ];

    private LoggerInterface $logger;

    /**
     * Optional und am Ende wie in den übrigen Controllern - bestehende Tests
     * bauen diesen Controller mit festen Positionsargumenten.
     */
    private ?NotificationService $notificationService;

    public function __construct(
        Twig $view,
        LoggerInterface $logger,
        ?NotificationService $notificationService = null
    ) {
        $this->view = $view;
        $this->logger = $logger;
        $this->notificationService = $notificationService;
    }

    /**
     * Der aktuelle Zustand je Anlass, aufgelöst zum Ankreuzen.
     *
     * Fehlt der Eintrag, gilt die Vorgabe des Anlasses - so muss die Verwaltung
     * nicht erst jeden einzeln bestaetigen, damit er funktioniert.
     *
     * @param array<string, list<array{type: string, label: string, description: string}>> $groups
     * @param array<string, mixed> $storedSettings
     * @return array<string, bool>
     */
    private function notificationValues(array $groups, array $storedSettings): array
    {
        $values = [];

        foreach ($groups as $types) {
            foreach ($types as $type) {
                $stored = $storedSettings[NotificationType::settingKey($type['type'])] ?? null;
                $values[$type['type']] = ($stored === null || $stored === '')
                    ? NotificationType::defaultEnabled($type['type'])
                    : ((string) $stored === '1');
            }
        }

        return $values;
    }

    public function index(Request $request, Response $response): Response
    {
        $settings = AppSetting::all()->pluck('setting_value', 'setting_key')->toArray();

        ['success' => $success, 'error' => $error] = $this->takeFlash();

        // Hier werden alle Anlässe angeboten, die das jeweilige Modul zulässt -
        // auch die abgeschalteten, sonst ließe sich ein einmal abgeschalteter
        // nie wieder einschalten.
        $notificationGroups = $this->notificationService === null
            ? []
            : $this->notificationService->moduleAvailableGrouped();

        return $this->view->render($response, 'settings/index.twig', [
            'settings_values' => $settings,
            'notification_groups' => $notificationGroups,
            'notification_group_labels' => NotificationType::GROUPS,
            'notification_values' => $this->notificationValues($notificationGroups, $settings),
            'log_levels' => self::LOG_LEVELS,
            'success' => $success,
            'error' => $error,
        ]);
    }

    public function save(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        $appName = trim(InputValidator::asString($data['app_name'] ?? null));
        $primaryColor = PrimaryColor::normalize($data['primary_color'] ?? null);
        $mailQueueTriggerMode = self::normalizeMailQueueTriggerMode($data['mailqueue_trigger_mode'] ?? null);
        $mailQueueOpportunisticRateLimit = self::normalizePositiveInteger(
            $data['mailqueue_opportunistic_rate_limit'] ?? null,
            10
        );
        $mailQueueBatchSize = self::normalizePositiveInteger($data['mailqueue_batch_size'] ?? null, 50);
        $registrationReminderDaysBefore = max(0, (int) ($data['registration_reminder_days_before'] ?? 0));
        $nameDisplayFormat = NameFormatterService::normalizeFormat($data['name_display_format'] ?? null);
        $logLevel = self::normalizeLogLevel($data['log_level'] ?? null);
        $logDbWrites = self::normalizeBooleanFlag($data['log_db_writes'] ?? null);

        try {
            if ($appName) {
                AppSetting::putText('app_name', $appName);
            }

            AppSetting::putText('primary_color', $primaryColor);
            AppSetting::putText('mailqueue_trigger_mode', $mailQueueTriggerMode);
            AppSetting::putText('mailqueue_opportunistic_rate_limit', (string) $mailQueueOpportunisticRateLimit);
            AppSetting::putText('mailqueue_batch_size', (string) $mailQueueBatchSize);
            AppSetting::putText('registration_reminder_days_before', (string) $registrationReminderDaysBefore);

            // Nicht angehakte Kästchen sendet der Browser nicht mit - deshalb
            // wird über die angebotenen Anlässe iteriert, nicht über die
            // Eingabe. Sonst bliebe ein abgewählter Anlass auf seinem alten
            // Wert stehen.
            if ($this->notificationService !== null) {
                $submitted = (array) ($data['notifications'] ?? []);

                foreach ($this->notificationService->moduleAvailableGrouped() as $types) {
                    foreach ($types as $type) {
                        AppSetting::putText(
                            NotificationType::settingKey($type['type']),
                            empty($submitted[$type['type']]) ? '0' : '1'
                        );
                    }
                }

                foreach (self::NOTIFICATION_DAY_SETTINGS as $key => $fallback) {
                    // Über InputValidator::asString(): `$key[]=x` ergäbe als Array
                    // bei `(int)` die 1 - eine Zahl, die niemand eingetragen hat.
                    // Leer heißt weiterhin "Vorgabe"; die frühere Prüfung auf null
                    // entfällt, asString() liefert immer eine Zeichenkette.
                    $raw = InputValidator::asString($data[$key] ?? null);
                    $days = $raw === '' ? $fallback : max(0, min(30, (int) $raw));

                    AppSetting::putText($key, (string) $days);
                }
            }

            // Nur, wenn das Feld mitkam: Die Karte steht nur bei aktivem
            // Sponsoring-Modul im Formular, und ein Speichern ohne sie soll die
            // Kennung nicht löschen. Ein eingefügter Link wird zur Kennung, alles
            // Unbrauchbare zu "kein Video".
            foreach (self::VIDEO_SETTINGS as $key) {
                if (!array_key_exists($key, $data)) {
                    continue;
                }

                AppSetting::putText($key, \App\Util\YoutubeVideoId::normalize($data[$key]));
            }

            AppSetting::putText('name_display_format', $nameDisplayFormat);
            AppSetting::putText('log_level', $logLevel);
            AppSetting::putText('log_db_writes', $logDbWrites);

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
                        $this->logger->warning('File upload rejected.', [
                            'event' => 'security.upload.rejected',
                            'reason' => $validation['reason'],
                        ]);
                        $_SESSION['error'] = $validation['error'];
                        return $response->withHeader('Location', '/settings')->withStatus(302);
                    }

                    $safeName = DownloadFileName::sanitize((string) $file->getClientFilename());

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

            $this->logger->info('Application settings updated.', [
                'event' => 'settings.updated',
                'keys' => array_values(array_intersect(
                    array_keys($data),
                    ['app_name', 'primary_color', 'name_display_format', 'log_level', 'log_db_writes']
                )),
            ]);

            $_SESSION['success'] = 'Einstellungen erfolgreich gespeichert.';
        } catch (\Exception $e) {
            $this->logger->error('Application settings update failed.', [
                'event' => 'settings.update.failed',
                'exception' => $e,
            ]);
            $_SESSION['error'] = 'Fehler beim Speichern der Einstellungen. Bitte erneut versuchen.';
        }

        return $response->withHeader('Location', '/settings')->withStatus(302);
    }

    public function themeCss(Request $request, Response $response): Response
    {
        $themeColor = PrimaryColor::DEFAULT;

        try {
            $themeColor = PrimaryColor::normalize(AppSetting::query()->find('primary_color')?->setting_value);
        } catch (\Throwable $exception) {
            $themeColor = PrimaryColor::DEFAULT;
        }

        $themeRgb = PrimaryColor::toRgb($themeColor);
        $themeStrong = PrimaryColor::strong($themeColor);

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

    /**
     * Das Vereinslogo - die einzige Auslieferung hinter der Anmeldung, die
     * zwischengespeichert werden darf.
     *
     * Die SecurityHeadersMiddleware setzt `no-store` auf jede Antwort ohne
     * eigenen `Cache-Control`-Kopf. Für Mitgliederdaten ist das richtig; das Logo
     * hängt aber in der Navigationsleiste jeder Seite, zeigt nichts Persönliches
     * und änderte sich trotzdem so selten, dass es bei jedem einzelnen Aufruf
     * neu über die Leitung ging.
     *
     * `private` erlaubt nur den Zwischenspeicher des Browsers, nicht den eines
     * gemeinsam genutzten Proxys. Die fünf Minuten sind die Obergrenze, um die
     * ein neu hochgeladenes Logo sichtbar nachhinken kann; das `ETag` drückt sie
     * in der Praxis auf null, sobald der Browser nachfragt.
     */
    public function logo(Request $request, Response $response): Response
    {
        $logo = AppSetting::find('app_logo');
        if ($logo && $logo->binary_content) {
            $safeName = DownloadFileName::sanitize((string) $logo->setting_value);

            return $this->respondWithCacheableImage(
                $request,
                $response
                    ->withHeader('Content-Type', $logo->mime_type)
                    ->withHeader(
                        'Content-Disposition',
                        'inline; filename="' . $safeName . '"; filename*=UTF-8\'\'' . rawurlencode($safeName)
                    ),
                (string) $logo->binary_content
            );
        }

        // Return default logo if not found
        $defaultLogoPath = __DIR__ . '/../../public/icons/icon-512.png';
        if (file_exists($defaultLogoPath)) {
            return $this->respondWithCacheableImage(
                $request,
                $response->withHeader('Content-Type', 'image/png'),
                (string) file_get_contents($defaultLogoPath)
            );
        }

        return $response->withStatus(404);
    }

    /**
     * Hängt Zwischenspeicher-Regel und `ETag` an und beantwortet einen passenden
     * `If-None-Match` mit 304, statt die Bytes erneut zu schicken.
     *
     * Die 304 trägt dieselbe Zwischenspeicher-Regel wie die 200: Ohne sie griffe
     * die Regel nur beim ersten Mal, und die Middleware setzte auf die Antwort
     * ohne eigenen Kopf wieder `no-store`.
     */
    private function respondWithCacheableImage(Request $request, Response $response, string $content): Response
    {
        $etag = '"' . hash('sha256', $content) . '"';

        $response = $response
            ->withHeader('Cache-Control', 'private, max-age=300')
            ->withHeader('ETag', $etag);

        if ($this->matchesETag($request->getHeaderLine('If-None-Match'), $etag)) {
            return $response->withStatus(304);
        }

        $response->getBody()->write($content);

        return $response;
    }

    /**
     * Ein `If-None-Match` darf mehrere Kennungen tragen, und ein Zwischenspeicher
     * darf jede davon als schwach (`W/"..."`) zurückschicken. Für einen reinen
     * Byte-Vergleich ist das gleichwertig.
     */
    private function matchesETag(string $headerLine, string $etag): bool
    {
        $headerLine = trim($headerLine);
        if ($headerLine === '') {
            return false;
        }

        if ($headerLine === '*') {
            return true;
        }

        foreach (explode(',', $headerLine) as $candidate) {
            $candidate = trim($candidate);
            if (str_starts_with($candidate, 'W/')) {
                $candidate = substr($candidate, 2);
            }

            if ($candidate === $etag) {
                return true;
            }
        }

        return false;
    }

    private static function normalizeMailQueueTriggerMode(mixed $value): string
    {
        $candidate = strtolower(trim(InputValidator::asString($value)));
        $allowed = ['cron', 'opportunistic', 'hybrid'];

        return in_array($candidate, $allowed, true) ? $candidate : 'hybrid';
    }

    private static function normalizePositiveInteger(mixed $value, int $default): int
    {
        $parsed = filter_var(InputValidator::asString($value), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $parsed !== false ? (int) $parsed : $default;
    }

    public static function normalizeLogLevel(mixed $value): string
    {
        $candidate = strtoupper(trim(InputValidator::asString($value)));

        return in_array($candidate, self::LOG_LEVELS, true) ? $candidate : 'INFO';
    }

    public static function normalizeBooleanFlag(mixed $value): string
    {
        return in_array(strtolower(trim(InputValidator::asString($value))), ['1', 'on', 'true', 'yes'], true) ? '1' : '0';
    }
}
