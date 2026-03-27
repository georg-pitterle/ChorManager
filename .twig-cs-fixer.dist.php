<?php

declare(strict_types=1);

use TwigCsFixer\Config\Config;
use TwigCsFixer\File\Finder;

return (new Config('ChorManager Twig'))
    ->setFinder((new Finder())->in(__DIR__ . '/templates'));
