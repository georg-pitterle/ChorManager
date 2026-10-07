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
use App\Controllers\SponsorController;
use App\Controllers\TaskController;
use App\Controllers\UserNotificationController;
use App\Queries\ProjectQuery;
use App\Services\HtmlSanitizer;
use App\Queries\UserQuery;
use App\Queries\NewsletterTemplateQuery;
use App\Persistence\UserPersistence;
use App\Persistence\ProjectPersistence;
use App\Persistence\NewsletterTemplatePersistence;
use App\Services\Mailer;
use App\Services\NewsletterService;
use App\Services\NewsletterAttachmentService;
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
use App\Services\SessionAuthService;
use App\Services\RateLimiterService;
use App\Services\SecretBoxCryptoService;
use App\Services\Oidc\AccessTokenService;
use App\Services\Oidc\AuthorizationCodeService;
use App\Services\Oidc\IdTokenSigner;
use App\Services\Oidc\OidcAdminService;
use App\Services\Oidc\OidcClaimsBuilder;
use App\Services\Oidc\OidcClientService;
use App\Services\Oidc\OidcSigningKeyService;
use App\Services\Oidc\OidcSigningReadiness;
use App\Controllers\Oidc\AuthorizeController;
use App\Controllers\Oidc\DiscoveryController;
use App\Controllers\Oidc\TokenController;
use App\Controllers\Oidc\UserinfoController;
use App\Services\SheetArchiveService;
use App\Services\MailQueueService;
use App\Services\MailDeliveryService;
use App\Services\MailQueueAdminService;
use App\Services\MailEventMapperService;
use App\Services\ProviderWebhookVerifier;
use App\Controllers\MailDeliveryWebhookController;
use App\Controllers\MailDeliveryDsnController;
use App\Controllers\EventTypeController;
use App\Controllers\MailQueueController;
use App\Controllers\VoiceGroupController;
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
use App\Commands\PurgeFileTrashCommand;
use App\Services\Files\FileAccessService;
use App\Services\Files\FileBackupService;
use App\Services\Files\FileFolderService;
use App\Services\Files\FileQuotaService;
use App\Services\Files\FileResponseFactory;
use App\Services\Files\FileService;
use App\Services\Files\FileStorageRegistry;
use App\Services\Files\FileTrashService;
use App\Services\Files\FileZipService;
use App\Services\Files\LocalFileStorage;
use App\Services\Office\OfficeDiscovery;
use App\Services\Office\OfficeDocumentCreator;
use App\Services\Office\OfficeSettings;
use App\Services\Office\OfficeTokenService;
use App\Controllers\WopiController;
use App\Controllers\OfficeEditorController;
use App\Middleware\SecurityHeadersMiddleware;
use App\Services\DumpRunnerInterface;
use App\Services\FlashMessageService;
use App\Services\MysqldumpRunner;
use App\Services\MailBadgeService;
use App\Services\MailBadgeViewService;
use App\Services\Notifications\InAppNotificationStore;
use App\Services\Notifications\NotificationBadgeViewService;
use App\Services\MailCredentialCryptoService;
use App\Middleware\CsrfMiddleware;
use App\Middleware\HtmlFormCsrfInjectorMiddleware;
use App\Middleware\MailBadgeRefreshMiddleware;
use App\Middleware\NotificationReminderMiddleware;
use App\Middleware\RegistrationReminderMiddleware;
use App\Navigation\NavigationBuilder;
use App\Navigation\NavigationContext;
use App\Util\AppUrlResolver;
use App\Controllers\StorageController;
use App\Services\Storage\BackupUsageProvider;
use App\Services\Storage\DatabaseUsageProvider;
use App\Services\Storage\FileStorageUsageProvider;
use App\Services\Storage\StorageUsageService;
use App\Services\Storage\VarDirectoryUsageProvider;
use App\Util\AttachmentPreview;
use App\Util\ByteFormatter;
use App\Util\EnvHelper;
use App\Policies\NewsletterPolicy;
use App\Policies\ProjectMemberPolicy;
use App\Controllers\SponsorPackageController;
use App\Controllers\SponsorshipController;
use App\Policies\SponsoringPolicy;
use App\Services\AttachmentAccessRegistry;
use App\Services\AttachmentResponseFactory;
use App\Services\EntityAttachmentService;
use App\Services\EntityCleanupService;
use App\Policies\TaskPolicy;
use App\Policies\UserEditPolicy;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;
use Twig\TwigFunction;
use App\Util\Csrf;
use App\Util\SessionView;
use App\Util\UploadValidator;
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

            // Ein Event-Dispatcher ist Voraussetzung dafür, dass die Connection
            // überhaupt QueryExecuted-Events feuert - ohne ihn ist listen() in
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
        // Logger-Parameter wird von der Autowiring-Reflexion übersprungen und blieb bislang
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
            return new SessionInvalidationService(
                $c->get(LoggerInterface::class),
                $c->get(AuthorizationCodeService::class)
            );
        },

        // OpenID-Connect-Provider. Durchweg von Hand zusammengesetzt statt
        // autoverdrahtet: Jeder dieser Dienste nimmt den Logger als optionalen
        // letzten Parameter, und PHP-DI füllt optionale Parameter nicht aus dem
        // Container - die Ereignisse oidc.* kämen sonst nie im Log an. Denselben
        // Fall tragen Mailer und MailCredentialCryptoService weiter oben.
        OidcSigningKeyService::class => function (ContainerInterface $c): OidcSigningKeyService {
            return new OidcSigningKeyService(
                new SecretBoxCryptoService(
                    OidcSigningKeyService::KEY_ENV,
                    null,
                    $c->get(LoggerInterface::class),
                    'oidc.signing_key.decrypt.failed'
                ),
                $c->get(LoggerInterface::class)
            );
        },
        IdTokenSigner::class => function (ContainerInterface $c): IdTokenSigner {
            return new IdTokenSigner($c->get(OidcSigningKeyService::class));
        },
        // Die Fabrik statt der fertigen Instanz: OidcSigningKeyService wirft im
        // Konstruktor, wenn OIDC_SIGNING_KEY_SECRET fehlt - genau der Fall, den
        // diese Klasse beantworten soll. Als Abhängigkeit scheiterte schon das
        // Auflösen des Authorize-Controllers.
        OidcSigningReadiness::class => function (ContainerInterface $c): OidcSigningReadiness {
            return new OidcSigningReadiness(
                fn(): OidcSigningKeyService => $c->get(OidcSigningKeyService::class),
                $c->get(LoggerInterface::class)
            );
        },
        OidcClientService::class => function (ContainerInterface $c): OidcClientService {
            return new OidcClientService($c->get(LoggerInterface::class));
        },
        AuthorizationCodeService::class => function (ContainerInterface $c): AuthorizationCodeService {
            return new AuthorizationCodeService($c->get(LoggerInterface::class));
        },
        // Nicht autowire(): Der Client-Dienst steht als optionaler Parameter mit
        // Vorgabe, und PHP-DI überspringt optionale Parameter. Der Token-Dienst
        // bekäme sonst einen zweiten OidcClientService statt des registrierten.
        AccessTokenService::class => function (ContainerInterface $c): AccessTokenService {
            return new AccessTokenService($c->get(OidcClientService::class));
        },
        OidcClaimsBuilder::class => \DI\autowire(),
        OidcAdminService::class => \DI\autowire(),
        DiscoveryController::class => \DI\autowire(),
        AuthorizeController::class => function (ContainerInterface $c): AuthorizeController {
            return new AuthorizeController(
                $c->get(OidcClientService::class),
                $c->get(AuthorizationCodeService::class),
                $c->get(UserQuery::class),
                $c->get(OidcSigningReadiness::class),
                $c->get(RateLimiterService::class),
                $c->get(LoggerInterface::class)
            );
        },
        TokenController::class => function (ContainerInterface $c): TokenController {
            return new TokenController(
                $c->get(OidcClientService::class),
                $c->get(AuthorizationCodeService::class),
                $c->get(AccessTokenService::class),
                $c->get(IdTokenSigner::class),
                $c->get(OidcClaimsBuilder::class),
                $c->get(UserQuery::class),
                $c->get(RateLimiterService::class),
                $c->get(LoggerInterface::class)
            );
        },
        UserinfoController::class => function (ContainerInterface $c): UserinfoController {
            return new UserinfoController(
                $c->get(AccessTokenService::class),
                $c->get(OidcClaimsBuilder::class),
                $c->get(OidcClientService::class),
                $c->get(UserQuery::class),
                $c->get(SessionAuthService::class),
                $c->get(RememberLoginService::class),
                $c->get(LoggerInterface::class)
            );
        },
        RegistrationReminderService::class => \DI\autowire(),
        // Nicht `autowire()`: Der Dienst braucht die Modul-Flags aus den
        // Einstellungen, und die sind kein Klassentyp, den der Container
        // auflösen könnte. Ohne sie liefe jeder modulgebundene Anlass ins Leere.
        // Diese drei Controller nehmen den Benachrichtigungsdienst als letzten,
        // optionalen Parameter - er musste ans Ende, weil zahlreiche Tests sie
        // mit festen Positionsargumenten bauen. PHP-DI füllt optionale
        // Parameter nicht aus dem Container, deshalb werden sie hier von Hand
        // zusammengesetzt statt autoverdrahtet. Ohne diese drei Einträge
        // verschickte der Betrieb still keine Benachrichtigung;
        // `NotificationWiringFeatureTest` prüft genau das.
        TaskController::class => function (ContainerInterface $c): TaskController {
            return new TaskController(
                $c->get(Twig::class),
                $c->get(HtmlSanitizer::class),
                $c->get(TaskPolicy::class),
                $c->get(NameFormatterService::class),
                $c->get(LoggerInterface::class),
                $c->get(NotificationService::class),
                $c->get(EntityAttachmentService::class),
                $c->get(EntityCleanupService::class)
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
                $c->get(ProjectQuery::class),
                $c->get(NotificationService::class),
                $c->get(EntityCleanupService::class)
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
                $modules,
                $c->get(InAppNotificationStore::class)
            );
        },
        InAppNotificationStore::class => \DI\autowire(),
        NotificationBadgeViewService::class => \DI\autowire(),
        UserNotificationController::class => \DI\autowire(),
        // Der Logger ist im Konstruktor optional (die Tests kommen ohne aus), und
        // optionale Parameter füllt PHP-DI nicht aus dem Container.
        SponsorController::class => function (ContainerInterface $c): SponsorController {
            return new SponsorController(
                $c->get(Twig::class),
                $c->get(SponsoringPolicy::class),
                $c->get(EntityAttachmentService::class),
                $c->get(LoggerInterface::class)
            );
        },
        SendRegistrationRemindersCommand::class => \DI\autowire(),
        NewsletterAttachmentService::class => \DI\autowire(),
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
                $c->get(TaskPolicy::class),
                $c->get('settings'),
                $c->get(StorageUsageService::class),
                $c->get(LoggerInterface::class)
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
        // den die Autowiring-Reflexion sonst stehen lässt - die Warnung über einen
        // fehlgeschlagenen Token-Einbau käme dann nie im Log an.
        HtmlFormCsrfInjectorMiddleware::class => function (ContainerInterface $c) {
            return new HtmlFormCsrfInjectorMiddleware($c->get(LoggerInterface::class));
        },
        // Der Logger ist optional mit NullLogger-Default (bestehende Tests bauen den
        // Controller mit nur $view), daher hier explizit verdrahten - sonst überspringt
        // die Autowiring-Reflexion den Parameter und der echte Logger kommt nie an.
        SongLibraryController::class => function (ContainerInterface $c) {
            return new SongLibraryController(
                $c->get(Twig::class),
                $c->get(LoggerInterface::class),
                $c->get(EntityAttachmentService::class),
                $c->get(EntityCleanupService::class)
            );
        },
        // Derselbe Fall wie bei SongLibraryController: der optionale Logger-Parameter wird von
        // der Autowiring-Reflexion übersprungen und bliebe der NullLogger - die
        // event_type.*/voice_group.*/sub_voice.*-Einträge eines gescheiterten Schreibvorgangs
        // kämen dann nie im Log an.
        EventTypeController::class => function (ContainerInterface $c) {
            return new EventTypeController($c->get(Twig::class), $c->get(LoggerInterface::class));
        },
        VoiceGroupController::class => function (ContainerInterface $c) {
            return new VoiceGroupController($c->get(Twig::class), $c->get(LoggerInterface::class));
        },
        // Beide Sponsoring-Controller tragen `?LoggerInterface $logger = null`.
        // PHP-DI autowired optionale Parameter nicht, sondern nimmt den
        // Vorgabewert - ohne diese beiden Einträge hätten sie im Betrieb still
        // einen NullLogger. Siehe DependenciesContainerWiringTest.
        SponsorshipController::class => function (ContainerInterface $c) {
            return new SponsorshipController(
                $c->get(SponsoringPolicy::class),
                $c->get(EntityAttachmentService::class),
                $c->get(LoggerInterface::class)
            );
        },
        SponsorPackageController::class => function (ContainerInterface $c) {
            return new SponsorPackageController(
                $c->get(Twig::class),
                $c->get(SponsoringPolicy::class),
                $c->get(LoggerInterface::class)
            );
        },
        MailQueueController::class => function (ContainerInterface $c) {
            return new MailQueueController(
                $c->get(Twig::class),
                $c->get(MailQueueAdminService::class),
                $c->get(LoggerInterface::class)
            );
        },
        // Derselbe Fall wie bei SongLibraryController: der optionale Logger-Parameter fällt in
        // die Autowiring-Lücke und bliebe der NullLogger - die authz.denied-Einträge der
        // Auswertungen kämen dann nie im Log an.
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
        // der Autowiring-Reflexion übersprungen und blieb bislang stets der NullLogger - auch
        // die bestehenden auth.password_reset.* Events (Task 5) kamen dadurch nie im Log an.
        // Der Mailer wird hier bewusst explizit aufgelöst statt `null` durchzureichen: sonst
        // baut der Controller intern per `new Mailer()` seine eigene Instanz und deren
        // Logger-Parameter fällt exakt in dieselbe Autowiring-Lücke, unabhängig davon, dass
        // Mailer::class selbst inzwischen eine echte Factory hat.
        // RateLimiter/PasswordPolicyService/MailQueueService bleiben unverändert bei ihren
        // bisherigen Fallbacks (out of scope für dieses Logging-Ticket).
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
        // Logger-Parameter wird von der Autowiring-Reflexion übersprungen und blieb bislang
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
                $c->get(SessionInvalidationService::class),
                null,
                $c->get(FileBackupService::class)
            );
        },
        BackupController::class => \DI\autowire(),
        // Dateiverwaltung: Einstellungen aus settings['files'] explizit, damit
        // Autowiring die skalaren Grenzen nicht mit Vorgaben füllt.
        LocalFileStorage::class => function (ContainerInterface $c): LocalFileStorage {
            return new LocalFileStorage($c->get('settings')['files']['storage_path']);
        },
        FileStorageRegistry::class => function (ContainerInterface $c): FileStorageRegistry {
            return new FileStorageRegistry($c->get(LocalFileStorage::class));
        },
        FileAccessService::class => \DI\autowire(),
        FileBackupService::class => \DI\autowire(),
        FileQuotaService::class => function (ContainerInterface $c): FileQuotaService {
            return new FileQuotaService(
                $c->get(FileAccessService::class),
                $c->get('settings')['files']['total_quota_bytes']
            );
        },
        // Dateiablage nur mit aktivem Modul - ohne sie gibt es weder Dateien noch ein
        // Ablageverzeichnis, das sich zu zeigen lohnt.
        StorageUsageService::class => function (ContainerInterface $c): StorageUsageService {
            $settings = $c->get('settings');
            $providers = [];
            if ($settings['modules']['files'] ?? false) {
                $providers[] = new FileStorageUsageProvider(
                    $c->get(FileAccessService::class),
                    $c->get(FileQuotaService::class)
                );
            }
            $providers[] = new DatabaseUsageProvider();
            $providers[] = new BackupUsageProvider($settings['backup']['dir']);
            $providers[] = new VarDirectoryUsageProvider(
                $settings['storage']['var_dir'],
                [$settings['files']['storage_path'], $settings['backup']['dir']]
            );

            return new StorageUsageService(
                $providers,
                $settings['storage']['summary_cache'],
                $c->get(LoggerInterface::class)
            );
        },
        StorageController::class => \DI\autowire(),
        FileFolderService::class => \DI\autowire(),
        FileService::class => function (ContainerInterface $c): FileService {
            $files = $c->get('settings')['files'];

            return new FileService(
                $c->get(FileAccessService::class),
                $c->get(FileFolderService::class),
                $c->get(FileQuotaService::class),
                $c->get(FileStorageRegistry::class),
                $c->get(LoggerInterface::class),
                $files['max_upload_bytes'],
                $files['max_versions']
            );
        },
        FileTrashService::class => function (ContainerInterface $c): FileTrashService {
            return new FileTrashService(
                $c->get(FileAccessService::class),
                $c->get(FileService::class),
                $c->get(FileStorageRegistry::class),
                $c->get(LoggerInterface::class),
                $c->get('settings')['files']['trash_days']
            );
        },
        FileZipService::class => function (ContainerInterface $c): FileZipService {
            return new FileZipService(
                $c->get(FileAccessService::class),
                $c->get(FileFolderService::class),
                $c->get(FileStorageRegistry::class),
                $c->get('settings')['files']['max_zip_bytes']
            );
        },
        FileResponseFactory::class => function (ContainerInterface $c): FileResponseFactory {
            return new FileResponseFactory($c->get(FileStorageRegistry::class));
        },
        PurgeFileTrashCommand::class => function (ContainerInterface $c): PurgeFileTrashCommand {
            return new PurgeFileTrashCommand(
                $c->get(FileTrashService::class),
                $c->get(LocalFileStorage::class),
                (bool) ($c->get('settings')['modules']['files'] ?? false)
            );
        },
        // Office-Anbindung. Die App-Adresse kommt aus APP_URL bzw. DDEV - nie aus
        // dem Host-Kopf einer Anfrage, die Collabora schickt.
        OfficeSettings::class => function (ContainerInterface $c): OfficeSettings {
            return OfficeSettings::fromArray(
                $c->get('settings')['office'],
                (string) (AppUrlResolver::configuredBaseUrl() ?? '')
            );
        },
        OfficeDiscovery::class => function (ContainerInterface $c): OfficeDiscovery {
            return new OfficeDiscovery(
                $c->get(OfficeSettings::class),
                OfficeDiscovery::httpFetcher(),
                $c->get('settings')['office']['discovery_cache'],
                $c->get(LoggerInterface::class)
            );
        },
        OfficeTokenService::class => \DI\autowire(),
        WopiController::class => \DI\autowire(),
        OfficeEditorController::class => \DI\autowire(),
        OfficeDocumentCreator::class => function (ContainerInterface $c): OfficeDocumentCreator {
            return new OfficeDocumentCreator(
                $c->get(FileService::class),
                $c->get(OfficeDiscovery::class),
                dirname(__DIR__) . '/assets/office-templates',
                $c->get(LoggerInterface::class)
            );
        },
        SecurityHeadersMiddleware::class => function (ContainerInterface $c): SecurityHeadersMiddleware {
            $officeEnabled = (bool) ($c->get('settings')['modules']['office'] ?? false);

            return new SecurityHeadersMiddleware($officeEnabled ? $c->get(OfficeSettings::class)->serverOrigin() : '');
        },
        CreateBackupCommand::class => \DI\autowire(),
        SheetArchiveService::class => function (ContainerInterface $c) {
            return new SheetArchiveService();
        },
        // Derselbe Fall wie bei Mailer: der optionale Logger-Parameter wird von der
        // Autowiring-Reflexion übersprungen und blieb bislang stets der NullLogger -
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
            // Dienst hängt über NotificationService an Twig, und diese globale
            // Middleware läuft vor der AuthMiddleware. Twig hier zu bauen fror
            // den noch unangemeldeten Sitzungszustand ein.
            return new NotificationReminderMiddleware(
                static fn (): NotificationReminderService => $c->get(NotificationReminderService::class),
                $c->get(LoggerInterface::class)
            );
        },
        // Die Sitzung kommt von hier, nicht aus dem Superglobal in der Policy.
        // Aufgelöst wird erst beim ersten Zugriff eines Controllers, also nach
        // dem Start der Sitzung - derselbe Zeitpunkt wie zuvor im Konstruktor.
        ProjectMemberPolicy::class => static fn (): ProjectMemberPolicy => new ProjectMemberPolicy($_SESSION),
        SponsoringPolicy::class => static fn (): SponsoringPolicy => new SponsoringPolicy($_SESSION),
        EntityAttachmentService::class => \DI\autowire(),
        EntityCleanupService::class => \DI\autowire(),
        TaskPolicy::class => static fn (): TaskPolicy => new TaskPolicy($_SESSION),

        NewsletterPolicy::class => static fn (): NewsletterPolicy => new NewsletterPolicy($_SESSION),
        UserEditPolicy::class => \DI\autowire(),
        AttachmentResponseFactory::class => \DI\autowire(),
        // Die Registry braucht das Modul-Array aus den Settings; PHP-DI kann
        // einen einfachen Array-Parameter nicht selbst auflösen.
        AttachmentAccessRegistry::class => function (ContainerInterface $c): AttachmentAccessRegistry {
            $modules = $c->get('settings')['modules'] ?? [];

            return new AttachmentAccessRegistry(
                $c->get(SponsoringPolicy::class),
                $c->get(TaskPolicy::class),
                is_array($modules) ? $modules : [],
                $c->get(NewsletterPolicy::class)
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
            $environment->addGlobal('upload_limits', UploadValidator::clientLimits());

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

            // Zähler der Glocke - aus demselben Grund erst beim Rendern ermittelt.
            $notificationBadgeView = $c->get(NotificationBadgeViewService::class);
            $environment->addFunction(new TwigFunction(
                'notification_badge',
                static fn (): ?int => $notificationBadgeView->forCurrentUser()
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

            // Ob eine Datei "Im Browser bearbeiten/ansehen" anbietet. Die Discovery
            // wird erst beim ersten Aufruf geladen - Seiten ohne Dateien zahlen nichts.
            $officeEnabled = (bool) ($allSettings['modules']['office'] ?? false);
            $environment->addFunction(new TwigFunction(
                'office_mode',
                static function (string $fileName, int $level) use ($c, $officeEnabled): ?string {
                    if (!$officeEnabled) {
                        return null;
                    }

                    return $c->get(OfficeDiscovery::class)->actionFor($fileName)?->modeFor($level);
                }
            ));
            // Welche neuen Dokumente "Neues Dokument" anbietet - leer ohne Modul
            // oder wenn der Office-Server nicht erreichbar ist.
            $environment->addFunction(new TwigFunction(
                'office_document_types',
                static fn (): array => $officeEnabled ? $c->get(OfficeDocumentCreator::class)->availableTypes() : []
            ));

            $nameFormatter = $c->get(NameFormatterService::class);
            $environment->addGlobal('name_display_format', $nameFormatter->getFormat());
            $environment->addFilter(new TwigFilter(
                'person_name',
                static fn (mixed $person): string => $nameFormatter->formatPerson($person)
            ));
            $environment->addFilter(new TwigFilter(
                'format_bytes',
                static fn (mixed $bytes): string => ByteFormatter::format((int) $bytes)
            ));

            return $twig;
        }
    ]);
};
