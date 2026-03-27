<?php

declare(strict_types=1);

use FriendsOfTwig\Twigcs;
use FriendsOfTwig\Twigcs\Finder\TemplateFinder;
use FriendsOfTwig\Twigcs\TemplateResolver\FileResolver;

return Twigcs\Config\Config::create()
    ->setName('ChorManager TwigCS')
    ->setSeverity('error')
    ->setReporter('console')
    ->setRuleSet(Twigcs\Ruleset\Official::class)
    ->addFinder(TemplateFinder::create()->in(__DIR__ . '/templates'))
    ->setTemplateResolver(new FileResolver(__DIR__ . '/templates'));
