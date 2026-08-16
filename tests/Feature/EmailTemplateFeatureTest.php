<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;

/**
 * Deckt das gemeinsame Mail-Layout ab: alle Systemmails teilen Huelle, Kopfbereich und Fuss.
 * Unterschieden werden sie nur ueber das Eyebrow-Label und die Toenung der Hinweisbox.
 */
class EmailTemplateFeatureTest extends TestCase
{
    private const BRAND_COLOR = '#E8A817';
    private const HEADER_COLOR = '#18212b';
    private const ACTION_TEXT_COLOR = '#2b2b2b';

    private function twig(): Twig
    {
        return Twig::create(dirname(__DIR__, 2) . '/templates');
    }

    /**
     * @param array<string, mixed> $context
     */
    private function render(string $template, array $context): string
    {
        return $this->twig()->fetch('emails/' . $template, $context);
    }

    /**
     * @return array<string, mixed>
     */
    private function invitationContext(): array
    {
        return [
            'user' => ['first_name' => 'Katharina'],
            'invite_link' => 'https://chor.example.org/invite?token=abc123',
            'app_name' => 'Chor-Manager',
            'primary_color' => self::BRAND_COLOR,
            'logo_src' => 'data:image/png;base64,AAAA',
            'handoff_url' => '',
            'handoff_label' => '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function passwordResetContext(): array
    {
        return [
            'user' => ['first_name' => 'Katharina'],
            'reset_link' => 'https://chor.example.org/reset-password?token=abc123',
            'app_name' => 'Chor-Manager',
            'primary_color' => self::BRAND_COLOR,
            'logo_src' => '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reminderContext(): array
    {
        return [
            'user' => ['first_name' => 'Katharina'],
            'event' => [
                'title' => 'Probenwochenende Herbstkonzert',
                'starts_at' => new \DateTimeImmutable('2026-10-24 09:30:00'),
                'location' => 'Pfarrsaal St. Michael',
            ],
            'deadline' => '10.10.2026 23:59',
            'link' => 'https://chor.example.org/registrations/1284',
            'app_name' => 'Chor-Manager',
            'primary_color' => self::BRAND_COLOR,
            'logo_src' => '',
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function mailTemplateProvider(): array
    {
        return [
            'Einladung' => ['invitation.twig', 'invitationContext'],
            'Passwort zurücksetzen' => ['password_reset.twig', 'passwordResetContext'],
            'Anmelde-Erinnerung' => ['registration_reminder.twig', 'reminderContext'],
        ];
    }

    /**
     * Alle Mails nutzen dieselbe Huelle: dunkler Kopf wie die Topbar, Amber-Akzentlinie, Fuss.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('mailTemplateProvider')]
    public function testEveryMailSharesTheCommonLayout(string $template, string $contextMethod): void
    {
        $html = $this->render($template, $this->{$contextMethod}());

        $this->assertStringContainsString(self::HEADER_COLOR, $html, 'Kopfbereich fehlt.');
        $this->assertStringContainsString(self::BRAND_COLOR, $html, 'Akzentfarbe fehlt.');
        $this->assertStringContainsString('Automatisch versendet', $html, 'Fussbereich fehlt.');
        $this->assertStringContainsString('max-width:600px', $html, 'Kartenbreite fehlt.');
    }

    /**
     * Der Aktionsbutton traegt anthrazitfarbene Schrift. Weiss auf Amber erreicht nur 2,0:1.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('mailTemplateProvider')]
    public function testActionButtonUsesAccessibleTextColor(string $template, string $contextMethod): void
    {
        $html = $this->render($template, $this->{$contextMethod}());

        $this->assertStringContainsString('color:' . self::ACTION_TEXT_COLOR, $html);
        $this->assertStringNotContainsString('color:#ffffff !important', $html);
    }

    /**
     * Mails duerfen zur Laufzeit nichts nachladen: keine CDNs, keine Web-Fonts, kein Skript.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('mailTemplateProvider')]
    public function testMailsLoadNoExternalResources(string $template, string $contextMethod): void
    {
        $html = $this->render($template, $this->{$contextMethod}());

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('@import', $html);
        $this->assertStringNotContainsString('<link', $html);
        $this->assertDoesNotMatchRegularExpression('/src="https?:/i', $html);
    }

    /**
     * Jede Mail liefert einen Preheader, damit die Vorschauzeile im Postfach nicht leer bleibt.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('mailTemplateProvider')]
    public function testEveryMailProvidesAPreheader(string $template, string $contextMethod): void
    {
        $html = $this->render($template, $this->{$contextMethod}());

        $this->assertMatchesRegularExpression('/max-height:\s*0/', $html, 'Preheader-Block fehlt.');
    }

    /**
     * Die Markenfarbe ist pro Installation konfigurierbar und muss durchschlagen.
     */
    public function testConfiguredBrandColorReplacesTheDefault(): void
    {
        $context = $this->invitationContext();
        $context['primary_color'] = '#2f6f4f';

        $html = $this->render('invitation.twig', $context);

        $this->assertStringContainsString('#2f6f4f', $html);
        $this->assertStringNotContainsString(self::BRAND_COLOR, $html);
    }

    public function testInvitationShowsHandoffLinkOnlyWhenConfigured(): void
    {
        $without = $this->render('invitation.twig', $this->invitationContext());
        $this->assertStringNotContainsString('handoff', $without);

        $context = $this->invitationContext();
        $context['handoff_url'] = 'https://wiki.example.org/onboarding';
        $context['handoff_label'] = 'Übergabedokument';

        $with = $this->render('invitation.twig', $context);
        $this->assertStringContainsString('https://wiki.example.org/onboarding', $with);
        $this->assertStringContainsString('Übergabedokument', $with);
    }

    public function testLogoBlockIsOmittedWhenNoLogoIsConfigured(): void
    {
        $withLogo = $this->render('invitation.twig', $this->invitationContext());
        $this->assertStringContainsString('<img', $withLogo);

        $context = $this->invitationContext();
        $context['logo_src'] = '';

        $withoutLogo = $this->render('invitation.twig', $context);
        $this->assertStringNotContainsString('<img', $withoutLogo);
    }

    public function testInvitationNamesRecipientAndValidity(): void
    {
        $html = $this->render('invitation.twig', $this->invitationContext());

        $this->assertStringContainsString('Hallo Katharina', $html);
        $this->assertStringContainsString('7 Tage', $html);
        $this->assertStringContainsString('https://chor.example.org/invite?token=abc123', $html);
    }

    public function testPasswordResetCarriesValidityAndSecurityNote(): void
    {
        $html = $this->render('password_reset.twig', $this->passwordResetContext());

        $this->assertStringContainsString('2 Stunden', $html);
        $this->assertStringContainsString('unverändert', $html);
        $this->assertStringContainsString('https://chor.example.org/reset-password?token=abc123', $html);
    }

    public function testReminderShowsEventDetailsAndDeadline(): void
    {
        $html = $this->render('registration_reminder.twig', $this->reminderContext());

        $this->assertStringContainsString('Probenwochenende Herbstkonzert', $html);
        $this->assertStringContainsString('24.10.2026 09:30', $html);
        $this->assertStringContainsString('Pfarrsaal St. Michael', $html);
        $this->assertStringContainsString('10.10.2026 23:59', $html);
    }

    public function testReminderOmitsLocationRowWhenEventHasNoLocation(): void
    {
        $context = $this->reminderContext();
        $context['event']['location'] = '';

        $html = $this->render('registration_reminder.twig', $context);

        $this->assertStringNotContainsString('Ort', $html);
        $this->assertStringContainsString('Probenwochenende Herbstkonzert', $html);
    }

    /**
     * Ohne Branding-Kontext darf keine Mail brechen - der Standard traegt.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('mailTemplateProvider')]
    public function testMailsRenderWithoutBrandingContext(string $template, string $contextMethod): void
    {
        $context = $this->{$contextMethod}();
        unset($context['app_name'], $context['primary_color'], $context['logo_src']);

        $html = $this->render($template, $context);

        $this->assertStringContainsString('Chor-Manager', $html);
        $this->assertStringContainsString(self::BRAND_COLOR, $html);
    }
}
