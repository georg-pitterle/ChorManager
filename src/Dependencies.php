<?php

declare(strict_types=1);

use DI\ContainerBuilder;
use Psr\Container\ContainerInterface;
use Slim\Views\Twig;
use App\Queries\ProjectQuery;
use App\Queries\UserQuery;
use App\Queries\NewsletterTemplateQuery;
use App\Persistence\UserPersistence;
use App\Persistence\ProjectPersistence;
use App\Persistence\NewsletterTemplatePersistence;
use App\Services\Mailer;
use App\Services\NewsletterService;
use App\Services\NewsletterLockingService;
use App\Services\NewsletterRecipientService;
use App\Services\MailQueueService;
use App\Services\MailDeliveryService;
use App\Services\MailQueueAdminService;
use App\Services\MailEventMapperService;
use App\Services\ProviderWebhookVerifier;
use App\Controllers\MailDeliveryWebhookController;
use App\Controllers\MailDeliveryDsnController;
use App\Commands\ProcessMailQueueCommand;
use Illuminate\Database\Capsule\Manager as Capsule;
use Twig\TwigFunction;
use App\Util\Csrf;

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

            return $capsule;
        },
        UserQuery::class => \DI\autowire(),
        UserPersistence::class => \DI\autowire(),
        ProjectQuery::class => \DI\autowire(),
        ProjectPersistence::class => \DI\autowire(),
        NewsletterTemplateQuery::class => \DI\autowire(),
        NewsletterTemplatePersistence::class => \DI\autowire(),
        Mailer::class => \DI\autowire(),
        MailQueueService::class => \DI\autowire(),
        MailDeliveryService::class => \DI\autowire(),
        MailQueueAdminService::class => \DI\autowire(),
        MailEventMapperService::class => \DI\autowire(),
        ProviderWebhookVerifier::class => \DI\autowire(),
        MailDeliveryWebhookController::class => \DI\autowire(),
        MailDeliveryDsnController::class => \DI\autowire(),
        ProcessMailQueueCommand::class => \DI\autowire(),
        NewsletterRecipientService::class => \DI\autowire(),
        NewsletterLockingService::class => \DI\autowire(),
        NewsletterService::class => \DI\autowire(),

        Twig::class => function (ContainerInterface $c) {
            $settings = $c->get('settings')['view'];
            $appTimezone = $c->get('settings')['timezone'] ?? 'Europe/Vienna';
            // Explicitly enable autoescape for security (HTML context)
            $twig = Twig::create(
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
            $environment->addGlobal('session', $_SESSION);
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

            $publicRoot = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'public';

            $environment->addFunction(new TwigFunction(
                'asset_path',
                static function (string $path) use ($publicRoot): string {
                    if ($path === '') {
                        return $path;
                    }

                    $normalizedPath = str_starts_with($path, '/') ? $path : '/' . $path;
                    $filePath = $publicRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ltrim($normalizedPath, '/'));

                    if (!is_file($filePath)) {
                        return $normalizedPath;
                    }

                    $separator = str_contains($normalizedPath, '?') ? '&' : '?';

                    return $normalizedPath . $separator . 'v=' . (string) filemtime($filePath);
                }
            ));

            $environment->addFunction(new TwigFunction(
                'nav_active',
                function (
                    string $path,
                    ?string $activeNav = null,
                    array $pathPrefixes = [],
                    array $navKeys = [],
                    array $excludePrefixes = []
                ): bool {
                    foreach ($excludePrefixes as $excludePrefix) {
                        if ($excludePrefix !== '' && str_starts_with($path, $excludePrefix)) {
                            return false;
                        }
                    }

                    if ($activeNav !== null && $activeNav !== '' && in_array($activeNav, $navKeys, true)) {
                        return true;
                    }

                    foreach ($pathPrefixes as $prefix) {
                        if ($prefix === '/' && $path === '/') {
                            return true;
                        }

                        if ($prefix === '/') {
                            continue;
                        }

                        if ($prefix !== '' && str_starts_with($path, $prefix)) {
                            return true;
                        }
                    }

                    return false;
                }
            ));

            return $twig;
        }
    ]);
};
