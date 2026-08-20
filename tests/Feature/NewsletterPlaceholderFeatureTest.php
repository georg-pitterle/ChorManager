<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\Newsletter;
use App\Models\Project;
use App\Models\SubVoice;
use App\Models\User;
use App\Models\VoiceGroup;
use App\Newsletter\PlaceholderDefinition;
use App\Newsletter\RenderContext;
use App\Services\NameFormatterService;
use App\Services\NewsletterPlaceholderService;
use App\Util\MailBranding;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Platzhalter in Newslettern: Registry, Auflösung, Escaping und Fallbacks.
 */
final class NewsletterPlaceholderFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Bootstrap::getCapsule()?->connection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $connection = Bootstrap::getCapsule()?->connection();
        if ($connection !== null && $connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        parent::tearDown();
    }

    private function service(): NewsletterPlaceholderService
    {
        return new NewsletterPlaceholderService(new NameFormatterService());
    }

    private function createUser(string $firstName = 'Georg', string $lastName = 'Pitterle'): User
    {
        $suffix = bin2hex(random_bytes(6));

        return User::create([
            'email' => "placeholder_{$suffix}@example.test",
            'password' => password_hash('secret', PASSWORD_BCRYPT),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'is_active' => 1,
        ]);
    }

    private function createNewsletter(?Project $project, User $creator, string $title = 'Probenplan'): Newsletter
    {
        return Newsletter::create([
            'project_id' => $project?->id,
            'title' => $title,
            'content_html' => '<p>Inhalt</p>',
            'status' => Newsletter::STATUS_DRAFT,
            'created_by' => $creator->id,
        ]);
    }

    public function testRegistryContainsAllDocumentedTokens(): void
    {
        $keys = array_keys($this->service()->definitions());

        sort($keys);

        $this->assertSame([
            'absender',
            'anrede',
            'app_name',
            'archiv_link',
            'datum',
            'email',
            'login_url',
            'nachname',
            'name',
            'projekt',
            'stimmgruppe',
            'titel',
            'vorname',
        ], $keys);
    }

    public function testEveryDefinitionCarriesGermanMetadataAndValidScope(): void
    {
        $validScopes = [
            PlaceholderDefinition::SCOPE_RECIPIENT,
            PlaceholderDefinition::SCOPE_NEWSLETTER,
            PlaceholderDefinition::SCOPE_GLOBAL,
        ];

        foreach ($this->service()->definitions() as $key => $definition) {
            $this->assertSame($key, $definition->key);
            $this->assertNotSame('', trim($definition->label), "Label fehlt: {$key}");
            $this->assertNotSame('', trim($definition->description), "Beschreibung fehlt: {$key}");
            $this->assertContains($definition->scope, $validScopes, "Ungültiger Scope: {$key}");
        }
    }

    public function testUnknownTokensAreReportedAndKnownOnesAreNot(): void
    {
        $service = $this->service();

        $this->assertSame(
            ['tippfehler'],
            $service->findUnknownTokens('<p>Hallo {{ vorname }}, {{tippfehler}} und {{nachname}}</p>')
        );
        $this->assertSame([], $service->findUnknownTokens('<p>Hallo {{vorname}}</p>'));
    }

    public function testUnknownTokensAreReportedOnlyOncePerKey(): void
    {
        $this->assertSame(
            ['tippfehler'],
            $this->service()->findUnknownTokens('{{tippfehler}} und nochmal {{tippfehler}}')
        );
    }

    public function testContextResolvesNewsletterAndGlobalScopes(): void
    {
        $creator = $this->createUser('Anna', 'Berger');
        $project = Project::create(['name' => 'Frühjahrskonzert']);
        $newsletter = $this->createNewsletter($project, $creator, 'Probenplan Mai');

        $context = RenderContext::fromNewsletter($newsletter, 'https://chor.example', new NameFormatterService());
        $definitions = $this->service()->definitions();

        $this->assertSame('Probenplan Mai', $definitions['titel']->resolve($context, null));
        $this->assertSame('Frühjahrskonzert', $definitions['projekt']->resolve($context, null));
        $this->assertSame('Anna Berger', $definitions['absender']->resolve($context, null));
        $this->assertSame('https://chor.example', $definitions['login_url']->resolve($context, null));
        $this->assertSame(
            '<a href="https://chor.example/newsletters/' . $newsletter->id . '/preview">Im Browser ansehen</a>',
            $definitions['archiv_link']->resolve($context, null)
        );
    }

    public function testProjectlessNewsletterResolvesProjectToEmptyString(): void
    {
        $creator = $this->createUser();
        $newsletter = $this->createNewsletter(null, $creator);

        $context = RenderContext::fromNewsletter($newsletter, 'https://chor.example', new NameFormatterService());

        $this->assertSame('', $this->service()->definitions()['projekt']->resolve($context, null));
    }

    public function testEmailResolvesToTheRealAddressOfTheRecipient(): void
    {
        $creator = $this->createUser();
        $recipient = $this->createUser('Maria', 'Huber');
        $newsletter = $this->createNewsletter(null, $creator);

        $context = RenderContext::fromNewsletter($newsletter, 'https://chor.example', new NameFormatterService());

        $this->assertSame(
            $recipient->email,
            $this->service()->definitions()['email']->resolve($context, $recipient)
        );
    }

    public function testDateResolvesToTheFormattedSentAtOfAStoredNewsletter(): void
    {
        $creator = $this->createUser();
        $newsletter = $this->createNewsletter(null, $creator);
        $newsletter->sent_at = Carbon::create(2026, 3, 15, 9, 30, 0);
        $newsletter->save();

        $context = RenderContext::fromNewsletter($newsletter, 'https://chor.example', new NameFormatterService());

        $this->assertSame('15.03.2026', $this->service()->definitions()['datum']->resolve($context, null));
    }

    public function testDateResolvesToTodayForANewsletterWithoutSentAt(): void
    {
        $creator = $this->createUser();
        $newsletter = $this->createNewsletter(null, $creator);

        $context = RenderContext::fromNewsletter($newsletter, 'https://chor.example', new NameFormatterService());

        $this->assertSame(
            (new \DateTimeImmutable())->format('d.m.Y'),
            $this->service()->definitions()['datum']->resolve($context, null)
        );
    }

    public function testAppNameFallsBackToTheDefaultWithoutAConfiguredSetting(): void
    {
        AppSetting::query()->where('setting_key', 'app_name')->delete();

        $creator = $this->createUser();
        $newsletter = $this->createNewsletter(null, $creator);
        $context = RenderContext::fromNewsletter($newsletter, 'https://chor.example', new NameFormatterService());

        $this->assertSame(
            MailBranding::DEFAULT_APP_NAME,
            $this->service()->definitions()['app_name']->resolve($context, null)
        );
    }

    public function testAppNameUsesTheConfiguredValueFromAppSettings(): void
    {
        AppSetting::query()->updateOrCreate(
            ['setting_key' => 'app_name'],
            ['setting_value' => 'Vereinsblatt Süd', 'binary_content' => '', 'mime_type' => '']
        );

        $creator = $this->createUser();
        $newsletter = $this->createNewsletter(null, $creator);
        $context = RenderContext::fromNewsletter($newsletter, 'https://chor.example', new NameFormatterService());

        $this->assertSame(
            'Vereinsblatt Süd',
            $this->service()->definitions()['app_name']->resolve($context, null)
        );
    }

    public function testUnsavedNewsletterResolvesArchivLinkAndAbsenderEmptyAndDatumToToday(): void
    {
        $newsletter = new Newsletter();

        $context = RenderContext::fromNewsletter($newsletter, 'https://chor.example', new NameFormatterService());
        $definitions = $this->service()->definitions();

        $this->assertSame('', $definitions['archiv_link']->resolve($context, null));
        $this->assertSame('', $definitions['absender']->resolve($context, null));
        $this->assertSame(
            (new \DateTimeImmutable())->format('d.m.Y'),
            $definitions['datum']->resolve($context, null)
        );
    }

    private function createVoiceGroupAssignment(User $user, string $groupName, ?string $subVoiceName): void
    {
        $group = VoiceGroup::create(['name' => $groupName]);
        $subVoiceId = null;

        if ($subVoiceName !== null) {
            $subVoiceId = SubVoice::create([
                'name' => $subVoiceName,
                'voice_group_id' => $group->id,
            ])->id;
        }

        $user->voiceGroups()->attach($group->id, ['sub_voice_id' => $subVoiceId]);
        $user->unsetRelation('voiceGroups');
        $user->unsetRelation('subVoices');
    }

    public function testRenderHtmlReplacesRecipientTokens(): void
    {
        $creator = $this->createUser('Anna', 'Berger');
        $recipient = $this->createUser('Georg', 'Pitterle');
        $this->createVoiceGroupAssignment($recipient, 'Bass', 'Bass 2');
        $newsletter = $this->createNewsletter(null, $creator, 'Probenplan Mai');
        $context = RenderContext::fromNewsletter($newsletter, 'https://chor.example', new NameFormatterService());

        $rendered = $this->service()->renderHtml(
            '<p>{{anrede}}, deine Stimmgruppe: {{stimmgruppe}}. Von {{absender}}.</p>',
            $context,
            $recipient
        );

        $this->assertSame(
            '<p>Hallo Georg, deine Stimmgruppe: Bass (Bass 2). Von Anna Berger.</p>',
            $rendered
        );
    }

    public function testRenderHtmlUsesFallbackForMissingFirstNameAndVoiceGroup(): void
    {
        $creator = $this->createUser();
        $recipient = $this->createUser('', 'Huber');
        $newsletter = $this->createNewsletter(null, $creator);
        $context = RenderContext::fromNewsletter($newsletter, 'https://chor.example', new NameFormatterService());

        $rendered = $this->service()->renderHtml(
            '<p>{{anrede}}! Gruppe: {{stimmgruppe}}</p>',
            $context,
            $recipient
        );

        $this->assertSame('<p>Hallo! Gruppe: ohne Stimmgruppe</p>', $rendered);
    }

    public function testRenderHtmlFallsBackToEmailWhenNameIsEmpty(): void
    {
        $creator = $this->createUser();
        $recipient = $this->createUser('', '');
        $newsletter = $this->createNewsletter(null, $creator);
        $context = RenderContext::fromNewsletter($newsletter, 'https://chor.example', new NameFormatterService());

        $rendered = $this->service()->renderHtml('<p>{{name}}</p>', $context, $recipient);

        $this->assertSame('<p>' . $recipient->email . '</p>', $rendered);
    }

    public function testRenderHtmlEscapesRecipientValues(): void
    {
        $creator = $this->createUser();
        $recipient = $this->createUser('Georg', '<script>alert(1)</script>');
        $newsletter = $this->createNewsletter(null, $creator);
        $context = RenderContext::fromNewsletter($newsletter, 'https://chor.example', new NameFormatterService());

        $rendered = $this->service()->renderHtml('<p>{{nachname}}</p>', $context, $recipient);

        $this->assertStringNotContainsString('<script>', $rendered);
        $this->assertStringContainsString('&lt;script&gt;', $rendered);
    }

    public function testRenderHtmlKeepsArchiveLinkAsMarkup(): void
    {
        $creator = $this->createUser();
        $recipient = $this->createUser();
        $newsletter = $this->createNewsletter(null, $creator);
        $context = RenderContext::fromNewsletter($newsletter, 'https://chor.example', new NameFormatterService());

        $rendered = $this->service()->renderHtml('<p>{{archiv_link}}</p>', $context, $recipient);

        $this->assertSame(
            '<p><a href="https://chor.example/newsletters/' . $newsletter->id . '/preview">Im Browser ansehen</a></p>',
            $rendered
        );
    }

    public function testRenderHtmlLeavesUnknownTokensUntouched(): void
    {
        $creator = $this->createUser();
        $recipient = $this->createUser();
        $newsletter = $this->createNewsletter(null, $creator);
        $context = RenderContext::fromNewsletter($newsletter, 'https://chor.example', new NameFormatterService());

        $rendered = $this->service()->renderHtml('<p>{{tippfehler}}</p>', $context, $recipient);

        $this->assertSame('<p>{{tippfehler}}</p>', $rendered);
    }

    public function testRenderHtmlWithoutRecipientUsesFallbacks(): void
    {
        $creator = $this->createUser();
        $newsletter = $this->createNewsletter(null, $creator);
        $context = RenderContext::fromNewsletter($newsletter, 'https://chor.example', new NameFormatterService());

        $rendered = $this->service()->renderHtml('<p>{{anrede}} {{stimmgruppe}} {{vorname}}</p>', $context, null);

        $this->assertSame('<p>Hallo ohne Stimmgruppe </p>', $rendered);
    }

    public function testRenderSubjectKeepsAmpersandUnencoded(): void
    {
        $creator = $this->createUser();
        $recipient = $this->createUser('Maria', 'Müller & Sohn');
        $newsletter = $this->createNewsletter(null, $creator);
        $context = RenderContext::fromNewsletter($newsletter, 'https://chor.example', new NameFormatterService());

        $subject = $this->service()->renderSubject('Info für {{nachname}}', $context, $recipient);

        $this->assertSame('Info für Müller & Sohn', $subject);
    }

    public function testRenderSubjectStripsLineBreaksToPreventHeaderInjection(): void
    {
        $creator = $this->createUser();
        $recipient = $this->createUser("Georg\r\nBcc: angriff@example.test", 'Pitterle');
        $newsletter = $this->createNewsletter(null, $creator);
        $context = RenderContext::fromNewsletter($newsletter, 'https://chor.example', new NameFormatterService());

        $subject = $this->service()->renderSubject('Hallo {{vorname}}', $context, $recipient);

        $this->assertStringNotContainsString("\r", $subject);
        $this->assertStringNotContainsString("\n", $subject);
        $this->assertSame('Hallo Georg Bcc: angriff@example.test', $subject);
    }

    public function testRenderSubjectReducesMarkupTokensToPlainText(): void
    {
        $creator = $this->createUser();
        $recipient = $this->createUser();
        $newsletter = $this->createNewsletter(null, $creator);
        $context = RenderContext::fromNewsletter($newsletter, 'https://chor.example', new NameFormatterService());

        $subject = $this->service()->renderSubject('Newsletter {{archiv_link}}', $context, $recipient);

        $this->assertSame('Newsletter Im Browser ansehen', $subject);
    }

    public function testRenderSubjectStripsUnicodeLinebreakCharactersAndVerticalTabulator(): void
    {
        $creator = $this->createUser();
        // Name mit Unicode-Zeilentrenner (U+2028) und vertikalem Tabulator (U+000B)
        $recipient = $this->createUser("Georg\u{2028}Test", "Pitterle\x0BHacker");
        $newsletter = $this->createNewsletter(null, $creator);
        $context = RenderContext::fromNewsletter($newsletter, 'https://chor.example', new NameFormatterService());

        $subject = $this->service()->renderSubject('Grüße an {{vorname}} {{nachname}}', $context, $recipient);

        // Beide Umbruchzeichen sollten durch Leerzeichen ersetzt werden
        $this->assertStringNotContainsString("\u{2028}", $subject);
        $this->assertStringNotContainsString("\x0B", $subject);
        $this->assertSame('Grüße an Georg Test Pitterle Hacker', $subject);
    }
}
