<?php

declare(strict_types=1);

use DI\ContainerBuilder;
use Psr\Container\ContainerInterface;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;
use App\Queries\ProjectQuery;
use App\Queries\UserQuery;
use App\Persistence\UserPersistence;
use App\Persistence\ProjectPersistence;
use App\Services\Mailer;
use App\Services\NewsletterService;
use App\Services\NewsletterLockingService;
use App\Services\NewsletterRecipientService;
use Illuminate\Database\Capsule\Manager as Capsule;
use Twig\TwigFunction;

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
        Mailer::class => \DI\autowire(),
        NewsletterRecipientService::class => \DI\autowire(),
        NewsletterLockingService::class => \DI\autowire(),
        NewsletterService::class => \DI\autowire(),

        Twig::class => function (ContainerInterface $c) {
            $settings = $c->get('settings')['view'];
            $twig = Twig::create($settings['template_path'], ['cache' => $settings['cache_path']]);

            // Add session to twig global environment
            $environment = $twig->getEnvironment();
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            $environment->addGlobal('session', $_SESSION);

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
