<?php

declare(strict_types=1);

use DI\ContainerBuilder;
use App\Logging\AppLoggerFactory;
use App\Logging\DatabaseWriteLogger;
use App\Logging\LogLevelResolver;
use App\Logging\RequestContext;
use App\Models\AppSetting;
use App\Views\HtmlTwig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Slim\Views\Twig;
use App\Controllers\AppSettingController;
use App\Controllers\EventController;
use App\Controllers\ProfileController;
use App\Controllers\ProjectController;
use App\Controllers\TaskController;
use App\Queries\ProjectQuery;
use App\Services\HtmlSanitizer;
use App\Queries\UserQuery;
use App\Queries\NewsletterTemplateQuery;
use App\Persistence\UserPersistence;
use App\Persistence\ProjectPersistence;
use App\Persistence\NewsletterTemplatePersistence;
use App\Services\Mailer;
use App\Services\NewsletterService;
use App\Services\NewsletterLockingService;
use App\Services\NewsletterMailRenderer;
use App\Services\NewsletterPlaceholderService;
use App\Services\NewsletterRecipientService;
use App\Services\BankStatementImportService;
use App\Services\FinanceAccountService;
use App\Services\FinanceCsvExportService;
use App\Services\FinanceJournalService;
use App\Services\BudgetService;
use App\Services\SessionInvalidationService;
use App\Services\SheetArchiveService;
use App\Services\MailQueueService;
use App\Services\MailDeliveryService;
use App\Services\MailQueueAdminService;
use App\Services\MailEventMapperService;
use App\Services\ProviderWebhookVerifier;
use App\Controllers\MailDeliveryWebhookController;
use App\Controllers\MailDeliveryDsnController;
use App\Controllers\BudgetController;
use App\Controllers\BackupController;
use App\Controllers\DashboardController;
use App\Controllers\EvaluationController;
use App\Controllers\FinanceAccountController;
use App\Controllers\FinanceController;
use App\Controllers\PasswordResetController;
use App\Controllers\MailBadgeController;
use App\Controllers\RoleController;
use App\Controllers\SongLibraryController;
use App\Services\FinanceReportPdfService;
use App\Services\Pdf\PdfCanvas;
use App\Services\Pdf\TcLibPdfCanvas;
use App\Services\RememberLoginService;
use App\Commands\ProcessMailQueueCommand;
use App\Commands\CreateBackupCommand;
use App\Commands\RotateMailCredentialKeyCommand;
use App\Commands\SendNotificationRemindersCommand;
use App\Commands\SendRegistrationRemindersCommand;
use App\Services\NotificationReminderService;
use App\Services\PasswordPolicyService;
use App\Services\NotificationService;
use App\Services\RegistrationReminderService;
use App\Services\BackupService;
use App\Services\DumpRunnerInterface;
use App\Services\FlashMessageService;
use App\Services\MysqldumpRunner;
use App\Services\MailBadgeService;
use App\Services\MailBadgeViewService;
use App\Services\MailCredentialCryptoService;
use App\Middleware\CsrfMiddleware;
use App\Middleware\HtmlFormCsrfInjectorMiddleware;
use App\Middleware\MailBadgeRefreshMiddleware;
use App\Middleware\NotificationReminderMiddleware;
use App\Middleware\RegistrationReminderMiddleware;
use App\Navigation\NavigationBuilder;
use App\Navigation\NavigationContext;
use App\Util\AttachmentPreview;
use App\Util\EnvHelper;
use App\Policies\ProjectMemberPolicy;
use App\Policies\SponsoringPolicy;
use App\Services\AttachmentAccessRegistry;
use App\Services\AttachmentResponseFactory;
use App\Services\EntityAttachmentService;
use App\Policies\TaskPolicy;
use App\Policies\UserEditPolicy;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;
use Twig\TwigFunction;
use App\Util\Csrf;
use App\Util\SessionView;
use App\Services\NameFormatterService;
use Twig\TwigFilter;

return function (ContainerBuilder $containerBuilder) {
    $containerBuilder->addDefinitions([
        Capsule::class => function (ContainerInterface $c) {
            $settings = $c->get('settings')['db'];

            $capsule = new Capsule();
            $capsule->addConnection($settings);

            // Make this Capsule instance available globally via static methods
            $capsule->setAsGlobal();

            // Setup the Eloquent ORM
            $capsule->bootEloquent();

            // Ein Event-Dispatcher ist Voraussetzung dafuer, dass die Connection
            // ueberhaupt QueryExecuted-Events feuert - ohne ihn ist listen() in
            // DatabaseWriteLogger::register() ein stiller No-Op.
            //
            // Die Reihenfolge ist bewusst: bootEloquent() reicht einen bereits
            // gesetzten Dispatcher an Eloquent weiter und schaltet damit den
            // gesamten Model-Lebenszyklus (creating, saved, deleted, Observer)
            // projektweit scharf. Dieses Feature braucht nur Query-Events, also
            // wird der Dispatcher erst danach gesetzt.
            $capsule->setEventDispatcher(new Dispatcher());

            $c->get(DatabaseWriteLogger::class)->register($capsule);

            return $capsule;
        },
        RequestContext::class => \DI\create(RequestContext::class),
        LogLevelResolver::class => function (ContainerInterface $c): LogLevelResolver {
            $settings = $c->get('settings');
            $fallback = is_array($settings['logging'] ?? null)
                ? (string) ($settings['logging']['level'] ?? 'INFO')
                : 'INFO';

            // Der Container wird hier absichtlich nur in der Closure benutzt: Die
            // Datenbank wird erst beim ersten Logaufruf angefasst, nicht beim Bau
            // des Loggers.
            return new LogLevelResolver(
                static function () use ($c): array {
                    $c->get(Capsule::class);

                    return AppSetting::query()
                        ->whereIn('setting_key', ['log_level', 'log_db_writes'])
                        ->pluck('setting_value', 'setting_key')
                        ->map(static fn ($value): string => (string) $value)
                        ->toArray();
                },
                $fallback
            );
        },
        LoggerInterface::class => function (ContainerInterface $c): LoggerInterface {
            $settings = $c->get('settings');
            $loggingSettings = is_array($settings['logging'] ?? null) ? $settings['logging'] : [];

            return AppLoggerFactory::create(
                $loggingSettings,
                $c->get(LogLevelResolver::class),
                $c->get(RequestContext::class)
            );
        },
        DatabaseWriteLogger::class => function (ContainerInterface $c): DatabaseWriteLogger {
            return new DatabaseWriteLogger(
                $c->get(LoggerInterface::class),
                $c->get(LogLevelResolver::class)
            );
        },
        UserQuery::class => \DI\autowire(),
        UserPersistence::class => \DI\autowire(),
        ProjectQuery::class => \DI\autowire(),
        ProjectPersistence::class => \DI\autowire(),
        NewsletterTemplateQuery::class => \DI\autowire(),
        NewsletterTemplatePersistence::class => \DI\autowire(),
        // Derselbe Fall wie bei SongLibraryController/PasswordResetController: der optionale
        // Logger-Parameter wird von der Autowiring-Reflexion uebersprungen und blieb bislang
        // stets der NullLogger - mail.send.skipped/.success/.failed kamen dadurch nie im Log an.
        Mailer::class => function (ContainerInterface $c): Mailer {
            return new Mailer($c->get(LoggerInterface::class));
        },
        MailQueueService::class => \DI\autowire(),
        MailDeliveryService::class => \DI\autowire(),
        MailQueueAdminService::class => \DI\autowire(),
        MailEventMapperService::class => \DI\autowire(),
        ProviderWebhookVerifier::class => \DI\autowire(),
        MailDeliveryWebhookController::class => \DI\autowire(),
        MailDeliveryDsnController::class => \DI\autowire(),
        ProcessMailQueueCommand::class => \DI\autowire(),
        // Nicht `autowire()`: Der Logger-Parameter hat einen Vorgabewert, und PHP-DI
        // füllt optionale Parameter nicht aus dem Container. Die Sperre liefe dann
        // still mit einem NullLogger.
        SessionInvalidationService::class => function (ContainerInterface $c) {
            return new SessionInvalidationService($c->get(LoggerInterface::class));
        },
        RegistrationReminderService::class => \DI\autowire(),
        // Nicht `autowire()`: Der Dienst braucht die Modul-Flags aus den
        // Einstellungen, und die sind kein Klassentyp, den der Container
        // auflösen könnte. Ohne sie liefe jeder modulgebundene Anlass ins Leere.
        // Diese drei Controller nehmen den Benachrichtigungsdienst als letzten,
        // optionalen Parameter - er musste ans Ende, weil zahlreiche Tests sie
        // mit festen Positionsargumenten bauen. PHP-DI fuellt optionale
        // Parameter nicht aus dem Container, deshalb werden sie hier von Hand
        // zusammengesetzt statt autoverdrahtet. Ohne diese drei Eintraege
        // verschickte der Betrieb still keine Benachrichtigung;
        // `NotificationWiringFeatureTest` prueft genau das.
        TaskController::class => function (ContainerInterface $c): TaskController {
            return new TaskController(
                $c->get(Twig::class),
                $c->get(HtmlSanitizer::class),
                $c->get(TaskPolicy::class),
                $c->get(NameFormatterService::class),
                $c->get(LoggerInterface::class),
                $c->get(NotificationService::class)
            );
        },
        AppSettingController::class => function (ContainerInterface $c): AppSettingController {
            return new AppSettingController(
                $c->get(Twig::class),
                $c->get(LoggerInterface::class),
                $c->get(NotificationService::class)
            );
        },
        ProfileController::class => function (ContainerInterface $c): ProfileController {
            return new ProfileController(
                $c->get(Twig::class),
                $c->get(UserQuery::class),
                $c->get(PasswordPolicyService::class),
                $c->get(LoggerInterface::class),
                $c->get(MailCredentialCryptoService::class),
                $c->get(RememberLoginService::class),
                $c->get(NotificationService::class)
            );
        },
        EventController::class => function (ContainerInterface $c): EventController {
            return new EventController(
                $c->get(Twig::class),
                $c->get(NameFormatterService::class),
                $c->get(LoggerInterface::class),
                $c->get(NotificationService::class)
            );
        },
        ProjectController::class => function (ContainerInterface $c): ProjectController {
            return new ProjectController(
                $c->get(Twig::class),
                $c->get(ProjectQuery::class),
                $c->get(ProjectPersistence::class),
                $c->get(ProjectMemberPolicy::class),
                $c->get(LoggerInterface::class),
                $c->get(NotificationService::class)
            );
        },
        NotificationService::class => function (ContainerInterface $c): NotificationService {
            $settings = $c->get('settings');
            $modules = is_array($settings['modules'] ?? null) ? $settings['modules'] : [];

            return new NotificationService(
                $c->get(MailQueueService::class),
                $c->get(Twig::class),
                $c->get(LoggerInterface::class),
                $modules
            );
        },
        SendRegistrationRemindersCommand::class => \DI\autowire(),
        NewsletterRecipientService::class => \DI\autowire(),
        NewsletterLockingService::class => \DI\autowire(),
        NewsletterPlaceholderService::class => \DI\autowire(),
        NewsletterMailRenderer::class => \DI\autowire(),
        NewsletterService::class => \DI\autowire(),
        BudgetService::class => \DI\autowire(),
        BudgetController::class => \DI\autowire(),
        DashboardController::class => function (ContainerInterface $c) {
            return new DashboardController(
                $c->get(Twig::class),
                $c->get(MailQueueAdminService::class),
                $c->get('settings')
            );
        },
        RoleController::class => function (ContainerInterface $c) {
            return new RoleController(
                $c->get(Twig::class),
                $c->get('settings'),
                $c->get(LoggerInterface::class)
            );
        },
        PdfCanvas::class => \DI\autowire(TcLibPdfCanvas::class),
        FinanceReportPdfService::class => \DI\autowire(),
        BankStatementImportService::class => \DI\autowire(),
        FinanceAccountService::class => \DI\autowire(),
        FinanceJournalService::class => \DI\autowire(),
        FinanceCsvExportService::class => \DI\autowire(),
        FinanceAccountController::class => \DI\autowire(),
        // FinanceController braucht seit der PDF-Export-Action zusätzlich den
        // FinanceReportPdfService - explizit verdrahtet, damit Autowiring hier
        // nicht ins Spiel kommt und die Auflösung deterministisch bleibt.
        FinanceController::class => function (ContainerInterface $c) {
            return new FinanceController(
                $c->get(Twig::class),
                $c->get(BudgetService::class),
                $c->get(LoggerInterface::class),
                $c->get(FinanceReportPdfService::class),
                $c->get(BankStatementImportService::class),
                $c->get(FinanceAccountService::class),
                $c->get(FinanceJournalService::class),
                $c->get(FinanceCsvExportService::class)
            );
        },
        CsrfMiddleware::class => function (ContainerInterface $c) {
            return new CsrfMiddleware($c->get(LoggerInterface::class));
        },
        // Derselbe Grund wie bei CsrfMiddleware: Der Logger hat einen NullLogger-Default,
        // den die Autowiring-Reflexion sonst stehen laesst - die Warnung ueber einen
        // fehlgeschlagenen Token-Einbau kaeme dann nie im Log an.
        HtmlFormCsrfInjectorMiddleware::class => function (ContainerInterface $c) {
            return new HtmlFormCsrfInjectorMiddleware($c->get(LoggerInterface::class));
        },
        // Der Logger ist optional mit NullLogger-Default (bestehende Tests bauen den
        // Controller mit nur $view), daher hier explizit verdrahten - sonst ueberspringt
        // die Autowiring-Reflexion den Parameter und der echte Logger kommt nie an.
        SongLibraryController::class => function (ContainerInterface $c) {
            return new SongLibraryController($c->get(Twig::class), $c->get(LoggerInterface::class));
        },
        // Derselbe Fall wie bei SongLibraryController: der optionale Logger-Parameter faellt in
        // die Autowiring-Luecke und bliebe der NullLogger - die authz.denied-Eintraege der
        // Auswertungen kaemen dann nie im Log an.
        EvaluationController::class => function (ContainerInterface $c) {
            return new EvaluationController(
                $c->get(Twig::class),
                $c->get(ProjectQuery::class),
                $c->get(NameFormatterService::class),
                null,
                $c->get(LoggerInterface::class)
            );
        },
        // Derselbe Fall wie bei SongLibraryController: der optionale Logger-Parameter wird von
        // der Autowiring-Reflexion uebersprungen und blieb bislang stets der NullLogger - auch
        // die bestehenden auth.password_reset.* Events (Task 5) kamen dadurch nie im Log an.
        // Der Mailer wird hier bewusst explizit aufgeloest statt `null` durchzureichen: sonst
        // baut der Controller intern per `new Mailer()` seine eigene Instanz und deren
        // Logger-Parameter faellt exakt in dieselbe Autowiring-Luecke, unabhaengig davon, dass
        // Mailer::class selbst inzwischen eine echte Factory hat.
        // RateLimiter/PasswordPolicyService/MailQueueService bleiben unveraendert bei ihren
        // bisherigen Fallbacks (out of scope fuer dieses Logging-Ticket).
        PasswordResetController::class => function (ContainerInterface $c) {
            return new PasswordResetController(
                $c->get(Twig::class),
                $c->get(Mailer::class),
                null,
                null,
                null,
                $c->get(LoggerInterface::class),
                $c->get(RememberLoginService::class)
            );
        },
        // Derselbe Fall wie bei SongLibraryController/PasswordResetController: der optionale
        // Logger-Parameter wird von der Autowiring-Reflexion uebersprungen und blieb bislang
        // stets der NullLogger - auth.remember_me.used/.rejected kamen dadurch nie im Log an.
        RememberLoginService::class => function (ContainerInterface $c) {
            return new RememberLoginService($c->get(LoggerInterface::class));
        },
        DumpRunnerInterface::class => function () {
            return new MysqldumpRunner(
                EnvHelper::read('DB_HOST', 'db'),
                EnvHelper::read('DB_PORT', '3306'),
                EnvHelper::read('DB_DATABASE', 'db'),
                EnvHelper::read('DB_USERNAME', 'db'),
                EnvHelper::read('DB_PASSWORD', 'db')
            );
        },
        BackupService::class => function (ContainerInterface $c) {
            $backupSettings = $c->get('settings')['backup'];

            // Die Key-Id ist reine Metainformation für den Restore-Fall. Ein
            // fehlender oder ungültiger MAIL_CREDENTIAL_KEY darf das Backup
            // nicht blockieren, deshalb hier bewusst fail-open.
            $mailKeyId = null;
            try {
                $mailKeyId = $c->get(MailCredentialCryptoService::class)->keyId();
            } catch (\Throwable) {
                $mailKeyId = null;
            }

            return new BackupService(
                $c->get(DumpRunnerInterface::class),
                $c->get(LoggerInterface::class),
                $backupSettings['dir'],
                $backupSettings['max_manual'],
                $backupSettings['max_auto'],
                $backupSettings['gzip'],
                EnvHelper::read('DB_DATABASE', 'db'),
                $backupSettings['app_version'],
                $mailKeyId,
                $c->get(SessionInvalidationService::class)
            );
        },
        BackupController::class => \DI\autowire(),
        CreateBackupCommand::class => \DI\autowire(),
        SheetArchiveService::class => function (ContainerInterface $c) {
            return new SheetArchiveService();
        },
        // Derselbe Fall wie bei Mailer: der optionale Logger-Parameter wird von der
        // Autowiring-Reflexion uebersprungen und blieb bislang stets der NullLogger -
        // mail_credential.decrypt.failed kam dadurch nie im Log an.
        MailCredentialCryptoService::class => function (ContainerInterface $c): MailCredentialCryptoService {
            return new MailCredentialCryptoService($c->get(LoggerInterface::class));
        },
        RotateMailCredentialKeyCommand::class => \DI\autowire(),
        MailBadgeService::class => function (ContainerInterface $c) {
            return new MailBadgeService(
                $c->get(MailCredentialCryptoService::class),
                $c->get(LoggerInterface::class),
                3
            );
        },
        MailBadgeViewService::class => \DI\autowire(),
        MailBadgeController::class => \DI\autowire(),
        FlashMessageService::class => \DI\autowire(),
        MailBadgeRefreshMiddleware::class => function (ContainerInterface $c) {
            // Resolve MailBadgeService lazily so a missing/invalid
            // MAIL_CREDENTIAL_KEY degrades the badge only, instead of throwing
            // during middleware construction and 500-ing every request.
            return new MailBadgeRefreshMiddleware(
                static fn (): MailBadgeService => $c->get(MailBadgeService::class),
                $c->get(LoggerInterface::class)
            );
        },
        RegistrationReminderMiddleware::class => function (ContainerInterface $c) {
            // Resolve the reminder service lazily: it depends on Twig, and this
            // global middleware runs before the route-level AuthMiddleware. Building
            // Twig that early froze its session state before a remember-me login was
            // restored, which dropped the navbar for that request.
            return new RegistrationReminderMiddleware(
                static fn (): RegistrationReminderService => $c->get(RegistrationReminderService::class),
                $c->get(LoggerInterface::class)
            );
        },
        NotificationReminderService::class => \DI\autowire(),
        SendNotificationRemindersCommand::class => \DI\autowire(),
        NotificationReminderMiddleware::class => function (ContainerInterface $c) {
            // Dieselbe Fabrik-Konstruktion wie bei der Anmelde-Erinnerung: Der
            // Dienst haengt ueber NotificationService an Twig, und diese globale
            // Middleware laeuft vor der AuthMiddleware. Twig hier zu bauen fror
            // den noch unangemeldeten Sitzungszustand ein.
            return new NotificationReminderMiddleware(
                static fn (): NotificationReminderService => $c->get(NotificationReminderService::class),
                $c->get(LoggerInterface::class)
            );
        },
        ProjectMemberPolicy::class => \DI\autowire(),
        SponsoringPolicy::class => \DI\autowire(),
        EntityAttachmentService::class => \DI\autowire(),
        TaskPolicy::class => \DI\autowire(),
        UserEditPolicy::class => \DI\autowire(),
        AttachmentResponseFactory::class => \DI\autowire(),
        // Die Registry braucht das Modul-Array aus den Settings; PHP-DI kann
        // einen einfachen Array-Parameter nicht selbst auflösen.
        AttachmentAccessRegistry::class => function (ContainerInterface $c): AttachmentAccessRegistry {
            $modules = $c->get('settings')['modules'] ?? [];

            return new AttachmentAccessRegistry(
                $c->get(SponsoringPolicy::class),
                $c->get(TaskPolicy::class),
                is_array($modules) ? $modules : []
            );
        },

        NameFormatterService::class => function (ContainerInterface $c): NameFormatterService {
            // Falls die Tabelle noch nicht existiert (frische Installation),
            // greift der Default des Service.
            try {
                $stored = \App\Models\AppSetting::query()
                    ->find('name_display_format')?->setting_value;
            } catch (\Throwable $e) {
                $stored = null;
            }

            return new NameFormatterService($stored !== null ? (string) $stored : null);
        },

        Twig::class => function (ContainerInterface $c) {
            $allSettings = $c->get('settings');
            $settings = $allSettings['view'];
            $appTimezone = $allSettings['timezone'] ?? 'Europe/Vienna';
            // Explicitly enable autoescape for security (HTML context)
            // HtmlTwig statt Twig: gerenderte Seiten weisen sich damit selbst als
            // text/html aus, woran die HtmlFormCsrfInjectorMiddleware sie erkennt.
            $twig = HtmlTwig::createForPath(
                $settings['template_path'],
                [
                    'cache' => $settings['cache_path'],
                    'autoescape' => 'html',  // Explicit security: escape output context to HTML
                ]
            );

            // Add session to twig global environment
            $environment = $twig->getEnvironment();
            $environment->getExtension(\Twig\Extension\CoreExtension::class)->setTimezone($appTimezone);
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            $environment->addGlobal('settings', $allSettings);
            // Live view instead of a by-value copy of $_SESSION: this factory can
            // run before the request is authenticated (global middleware resolves
            // Twig before the route-level AuthMiddleware restores a remember-me
            // login), and a frozen snapshot then hid the whole navbar.
            $environment->addGlobal('session', new SessionView());
            $environment->addGlobal('csrf_token', Csrf::ensureToken());

            $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
            $currentPath = (string) parse_url($requestUri, PHP_URL_PATH);
            if ($currentPath === '') {
                $currentPath = '/';
            }
            $environment->addGlobal('current_path', $currentPath);

            // Add App Settings to Twig
            try {
                $appSettings = \App\Models\AppSetting::all()->pluck('setting_value', 'setting_key')->toArray();
            } catch (\Exception $e) {
                $appSettings = [];
            }
            $environment->addGlobal('app_settings', $appSettings);

            // The current user's cached unread-mail badge, resolved at render time:
            // this factory can run before the request is authenticated, so a value
            // computed here would describe an anonymous request.
            $mailBadgeView = $c->get(MailBadgeViewService::class);
            $environment->addFunction(new TwigFunction(
                'mail_badge',
                static fn (): array => $mailBadgeView->forCurrentUser()
            ));

            // Flash-Meldungen werden erst beim Rendern des Vollseiten-Layouts
            // konsumiert, damit sie auch auf Seiten erscheinen, die sie nicht selbst
            // aus der Session holen. Als Global statt als Funktion, damit Templates,
            // die ohne diesen Container gerendert werden, den Block still auslassen
            // statt an einer unbekannten Funktion zu scheitern.
            $environment->addGlobal('flash', $c->get(FlashMessageService::class));

            $publicRoot = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'public';

            $environment->addFunction(new TwigFunction(
                'asset_path',
                static function (string $path) use ($publicRoot): string {
                    if ($path === '') {
                        return $path;
                    }

                    $normalizedPath = str_starts_with($path, '/') ? $path : '/' . $path;
                    $filePath = $publicRoot . DIRECTORY_SEPARATOR
                        . str_replace('/', DIRECTORY_SEPARATOR, ltrim($normalizedPath, '/'));

                    if (!is_file($filePath)) {
                        return $normalizedPath;
                    }

                    $separator = str_contains($normalizedPath, '?') ? '&' : '?';

                    return $normalizedPath . $separator . 'v=' . (string) filemtime($filePath);
                }
            ));

            $environment->addFunction(new TwigFunction(
                'navigation',
                static function (string $activeNav = "") use ($allSettings, $currentPath): array {
                    $context = NavigationContext::fromSession($_SESSION, $allSettings, $currentPath, $activeNav);

                    return (new NavigationBuilder())->build($context);
                }
            ));

            // Ob ein Anhang einen Vorschau-Button bekommt, entscheidet dieselbe
            // Klasse wie im Controller. Zwei Listen - eine in PHP, eine in Twig -
            // wären genau die Doppelung, die dieser Umbau beseitigt.
            $environment->addFunction(new TwigFunction(
                'attachment_previewable',
                static fn (?string $mimeType): bool => AttachmentPreview::isModalPreviewable((string) $mimeType)
            ));

            $nameFormatter = $c->get(NameFormatterService::class);
            $environment->addGlobal('name_display_format', $nameFormatter->getFormat());
            $environment->addFilter(new TwigFilter(
                'person_name',
                static fn (mixed $person): string => $nameFormatter->formatPerson($person)
            ));

            return $twig;
        }
    ]);
};
