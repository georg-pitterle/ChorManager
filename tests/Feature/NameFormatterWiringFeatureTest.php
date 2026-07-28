<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\NameFormatterService;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\TwigFilter;

final class NameFormatterWiringFeatureTest extends TestCase
{
    public function testDependenciesRegisterServiceAndTwigFilter(): void
    {
        $dependencies = file_get_contents(dirname(__DIR__) . '/../src/Dependencies.php');

        $this->assertIsString($dependencies);
        $this->assertStringContainsString('NameFormatterService::class', $dependencies);
        $this->assertStringContainsString("'name_display_format'", $dependencies);
        $this->assertStringContainsString("'person_name'", $dependencies);
    }

    public function testPersonNameFilterRendersConfiguredOrder(): void
    {
        $formatter = new NameFormatterService(NameFormatterService::FORMAT_LAST_FIRST);
        $twig = new Environment(new ArrayLoader(['t' => '{{ person|person_name }}']));
        $twig->addFilter(new TwigFilter(
            'person_name',
            static fn (mixed $person): string => $formatter->formatPerson($person)
        ));

        $rendered = $twig->render('t', [
            'person' => ['first_name' => 'Anna', 'last_name' => 'Müller'],
        ]);

        $this->assertSame('Müller, Anna', $rendered);
    }

    public function testSeedProvidesNameDisplayFormat(): void
    {
        $seed = file_get_contents(dirname(__DIR__) . '/../src/Services/DevSeedService.php');

        $this->assertIsString($seed);
        $this->assertStringContainsString('name_display_format', $seed);
    }
}
