<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\NameFormatterService;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;

final class PersonMacroFeatureTest extends TestCase
{
    private function makeTwig(string $format): Environment
    {
        $formatter = new NameFormatterService($format);
        $twig = new Environment(
            new FilesystemLoader(dirname(__DIR__) . '/../templates'),
            ['autoescape' => 'html']
        );
        $twig->addFilter(new TwigFilter(
            'person_name',
            static fn (mixed $person): string => $formatter->formatPerson($person)
        ));

        return $twig;
    }

    private function render(string $format, array $context): string
    {
        $twig = $this->makeTwig($format);
        $template = $twig->createTemplate(
            '{% import "macros/person.twig" as person %}'
            . '{{ person.member_link(member, member.id, can_edit_map) }}'
        );

        return trim($template->render($context));
    }

    public function testRendersLinkWhenMemberIsEditable(): void
    {
        $html = $this->render('first_last', [
            'member' => ['id' => 42, 'first_name' => 'Anna', 'last_name' => 'Müller'],
            'can_edit_map' => [42 => true],
        ]);

        $this->assertStringContainsString('href="/users?edit=42"', $html);
        $this->assertStringContainsString('Anna Müller', $html);
    }

    public function testRendersPlainTextWhenMemberIsNotEditable(): void
    {
        $html = $this->render('last_first', [
            'member' => ['id' => 42, 'first_name' => 'Anna', 'last_name' => 'Müller'],
            'can_edit_map' => [],
        ]);

        $this->assertStringNotContainsString('<a ', $html);
        $this->assertSame('Müller, Anna', $html);
    }

    public function testRendersPlainTextWhenIdIsMissing(): void
    {
        $twig = $this->makeTwig('first_last');
        $template = $twig->createTemplate(
            '{% import "macros/person.twig" as person %}'
            . '{{ person.member_link(member) }}'
        );

        $html = trim($template->render([
            'member' => ['first_name' => 'Anna', 'last_name' => 'Müller'],
        ]));

        $this->assertSame('Anna Müller', $html);
    }
}
