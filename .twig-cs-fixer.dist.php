<?php

declare(strict_types=1);

use TwigCsFixer\Config\Config;
use TwigCsFixer\File\Finder;
use TwigCsFixer\Rules\Literal\SingleQuoteRule;
use TwigCsFixer\Rules\Operator\OperatorSpacingRule;
use TwigCsFixer\Ruleset\Ruleset;
use TwigCsFixer\Standard\TwigCsFixer;

$ruleset = new Ruleset();
$ruleset->addStandard(new TwigCsFixer());
// Project standard mandates double quotes in Twig (see instructions/twig-style.md),
// so the fixer must not rewrite strings to single quotes.
$ruleset->removeRule(SingleQuoteRule::class);
// Macro default arguments use no spaces around "=" (project standard, enforced
// by friendsoftwig/twigcs), but "{% set x = y %}" keeps spaces. The fixer cannot
// distinguish the two contexts, so it must not enforce spacing around "=" at all.
$ruleset->overrideRule(new OperatorSpacingRule(
    beforeOverride: ['=' => null],
    afterOverride: ['=' => null],
));

return (new Config('ChorManager Twig'))
    ->setRuleset($ruleset)
    ->setFinder((new Finder())->in(__DIR__ . '/templates'));
