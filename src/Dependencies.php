<?php
declare(strict_types=1)
;

use DI\ContainerBuilder;
use Psr\Container\ContainerInterface;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;
use App\Queries\ProjectQuery;
use App\Queries\UserQuery;
use App\Persistence\UserPersistence;
use App\Persistence\ProjectPersistence;
use Illuminate\Database\Capsule\Manager as Capsule;

return function (ContainerBuilder $containerBuilder) {
    $containerBuilder->addDefinitions([
        Capsule::class => function (ContainerInterface $c) {
            $settings = $c->get('settings')['db'];

            $capsule = new Capsule;
            $capsule->addConnection($settings);

            // Make this Capsule instance available globally via static methods
            $capsule->setAsGlobal();

            // Setup the Eloquent ORM
            $capsule->bootEloquent();

            return $capsule;
        }
        ,
        UserQuery::class => \DI\autowire(),
        UserPersistence::class => \DI\autowire(),
        ProjectQuery::class => \DI\autowire(),
        ProjectPersistence::class => \DI\autowire(),

        Twig::class => function (ContainerInterface $c) {
            $settings = $c->get('settings')['view'];
            $twig = Twig::create($settings['template_path'], ['cache' => $settings['cache_path']]);

            // Add session to twig global environment
            $environment = $twig->getEnvironment();
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            $environment->addGlobal('session', $_SESSION);

            // Add App Settings to Twig
            try {
                $appSettings = \App\Models\AppSetting::all()->pluck('setting_value', 'setting_key')->toArray();
            } catch (\Exception $e) {
                $appSettings = [];
            }
            $environment->addGlobal('app_settings', $appSettings);

            return $twig;
        }
    ]);
};
