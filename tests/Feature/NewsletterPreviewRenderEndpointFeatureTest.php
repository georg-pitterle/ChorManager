<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Newsletter;
use App\Models\NewsletterArchive;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\TestCase;

/**
 * Der Editor-Stand wird serverseitig gerendert, bevor er in der Vorschau erscheint.
 */
final class NewsletterPreviewRenderEndpointFeatureTest extends TestCase
{
    use NewsletterControllerTestScaffold;

    public function testUnsavedContentIsRenderedForSelectedRecipient(): void
    {
        $creator = $this->createUser('Anna');
        $recipient = $this->createUser('Georg');
        $newsletter = $this->createNewsletter($creator, $recipient);

        $_SESSION['user_id'] = (int) $creator->id;
        $_SESSION['can_manage_newsletters'] = true;

        $request = $this->makeRequest('POST', "/newsletters/{$newsletter->id}/preview-render", [
            'title' => 'Info für {{vorname}}',
            'content_html' => '<p>{{anrede}}</p>',
            'recipient_id' => (string) $recipient->id,
        ])->withAttribute('id', (string) $newsletter->id);

        $response = $this->controller()->previewRender($request, $this->makeResponse());
        $payload = json_decode((string) $response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Info für Georg', $payload['title']);
        $this->assertSame('<p>Hallo Georg</p>', $payload['content_html']);
    }

    /**
     * Schärfeprobe für die Sanitisierung: Skript-Element und Ereignis-Attribut dürfen die
     * Vorschau nicht erreichen, der harmlose Textanteil aber schon. Ohne den Sanitizer-
     * Aufruf im Endpunkt bliebe beides erhalten und dieser Test würde fehlschlagen.
     */
    public function testPreviewRenderSanitizesScriptAndEventAttribute(): void
    {
        $creator = $this->createUser('Anna');
        $recipient = $this->createUser('Georg');
        $newsletter = $this->createNewsletter($creator, $recipient);

        $_SESSION['user_id'] = (int) $creator->id;
        $_SESSION['can_manage_newsletters'] = true;

        $request = $this->makeRequest('POST', "/newsletters/{$newsletter->id}/preview-render", [
            'title' => 'Info',
            'content_html' => '<p>Harmloser Text</p><script>alert(1)</script>'
                . '<p onclick="alert(2)">Klick mich</p>',
        ])->withAttribute('id', (string) $newsletter->id);

        $response = $this->controller()->previewRender($request, $this->makeResponse());
        $payload = json_decode((string) $response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Harmloser Text', $payload['content_html']);
        $this->assertStringContainsString('Klick mich', $payload['content_html']);
        $this->assertStringNotContainsString('<script', $payload['content_html']);
        $this->assertStringNotContainsString('onclick', $payload['content_html']);
    }

    public function testRenderEndpointRejectsUnrelatedRecipient(): void
    {
        $creator = $this->createUser('Anna');
        $recipient = $this->createUser('Georg');
        $outsider = $this->createUser('Fremd');
        $newsletter = $this->createNewsletter($creator, $recipient);

        $_SESSION['user_id'] = (int) $creator->id;
        $_SESSION['can_manage_newsletters'] = true;

        $request = $this->makeRequest('POST', "/newsletters/{$newsletter->id}/preview-render", [
            'title' => 'Info',
            'content_html' => '<p>{{anrede}}</p>',
            'recipient_id' => (string) $outsider->id,
        ])->withAttribute('id', (string) $newsletter->id);

        $this->assertSame(403, $this->controller()->previewRender($request, $this->makeResponse())->getStatusCode());
    }

    public function testRenderEndpointRequiresManagementRight(): void
    {
        $creator = $this->createUser('Anna');
        $recipient = $this->createUser('Georg');
        $newsletter = $this->createNewsletter($creator, $recipient);

        $_SESSION['user_id'] = (int) $creator->id;
        $_SESSION['can_manage_newsletters'] = false;

        $request = $this->makeRequest('POST', "/newsletters/{$newsletter->id}/preview-render", [
            'title' => 'Info',
            'content_html' => '<p>{{anrede}}</p>',
        ])->withAttribute('id', (string) $newsletter->id);

        $this->assertSame(403, $this->controller()->previewRender($request, $this->makeResponse())->getStatusCode());
    }

    /**
     * Regressionsschutz: Ohne das Recht zum Verwalten von Newslettern darf der
     * recipient_id-Parameter des neuen Endpunkts keine fremden Empfängerdaten
     * liefern – der Endpunkt muss vorher schon mit 403 abweisen.
     */
    public function testRenderEndpointRejectsRecipientParameterWithoutManagementRight(): void
    {
        $creator = $this->createUser('Anna');
        $recipient = $this->createUser('Georg');
        $outsider = $this->createUser('Fremd');
        $newsletter = $this->createNewsletter($creator, $recipient);

        $_SESSION['user_id'] = (int) $recipient->id;
        $_SESSION['can_manage_newsletters'] = false;

        $request = $this->makeRequest('POST', "/newsletters/{$newsletter->id}/preview-render", [
            'title' => 'Info',
            'content_html' => '<p>{{anrede}}</p>',
            'recipient_id' => (string) $outsider->id,
        ])->withAttribute('id', (string) $newsletter->id);

        $this->assertSame(403, $this->controller()->previewRender($request, $this->makeResponse())->getStatusCode());
    }

    /**
     * Regressionsschutz für die bestehende Vorschau-Route: Ohne das Recht zum
     * Verwalten von Newslettern wird der recipient_id-Parameter vollständig
     * ignoriert, auch wenn der Betrachter selbst zu den Empfängern zählt und
     * dadurch über einen eigenen Archiveintrag Zugriff auf die Route hat.
     */
    public function testPreviewRouteIgnoresRecipientParameterWithoutManagementRight(): void
    {
        $creator = $this->createUser('Anna');
        $recipient = $this->createUser('Georg');
        $outsider = $this->createUser('Fremd');
        $newsletter = $this->createNewsletter($creator, $recipient);

        NewsletterArchive::create([
            'newsletter_id' => $newsletter->id,
            'user_id' => $recipient->id,
            'email' => $recipient->email,
            'sent_at' => '2026-08-18 10:00:00',
        ]);

        $_SESSION['user_id'] = (int) $recipient->id;
        $_SESSION['can_manage_newsletters'] = false;

        $request = $this->makeRequest(
            'GET',
            "/newsletters/{$newsletter->id}/preview",
            [],
            ['recipient_id' => (string) $outsider->id]
        )->withAttribute('id', (string) $newsletter->id);
        $response = $this->controller()->preview($request, $this->makeResponse());

        $body = (string) $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Hallo Georg', $body);
        $this->assertStringNotContainsString('Hallo Fremd', $body);
    }

    /**
     * Das Vorschau-Auswahlfeld im Editor darf ausschließlich die aufgelösten Empfänger
     * dieses Newsletters anbieten; eine aktive, aber nicht zugeordnete Person wie
     * $outsider würde sonst 403 provozieren, sobald sie ausgewählt wird.
     */
    public function testEditFormOffersOnlyResolvedRecipientsForPreview(): void
    {
        $creator = $this->createUser('Anna');
        $recipient = $this->createUser('Georg');
        $outsider = $this->createUser('Fremd');
        $newsletter = $this->createNewsletter($creator, $recipient);

        $_SESSION['user_id'] = (int) $creator->id;
        $_SESSION['can_manage_newsletters'] = true;

        $request = $this->makeRequest('GET', "/newsletters/{$newsletter->id}/edit")
            ->withAttribute('id', (string) $newsletter->id);

        $response = $this->controller()->edit($request, $this->makeResponse());
        $optionValues = $this->previewRecipientOptionValues((string) $response->getBody());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertContains((string) $recipient->id, $optionValues);
        $this->assertNotContains((string) $outsider->id, $optionValues);
    }

    /**
     * Frischer Entwurf ohne konfigurierte Empfängerquellen: Die aufgelöste Menge ist leer,
     * das Vorschau-Auswahlfeld darf daher außer der Option "eigene Daten" niemanden zeigen.
     */
    public function testEditFormOffersNoPreviewRecipientWithoutConfiguredSources(): void
    {
        $creator = $this->createUser('Anna');
        $this->createUser('Georg');
        $newsletter = Newsletter::create([
            'project_id' => null,
            'title' => 'Entwurf ohne Empfängerquellen',
            'content_html' => '<p>Hallo</p>',
            'status' => Newsletter::STATUS_DRAFT,
            'created_by' => $creator->id,
        ]);

        $_SESSION['user_id'] = (int) $creator->id;
        $_SESSION['can_manage_newsletters'] = true;

        $request = $this->makeRequest('GET', "/newsletters/{$newsletter->id}/edit")
            ->withAttribute('id', (string) $newsletter->id);

        $response = $this->controller()->edit($request, $this->makeResponse());
        $optionValues = $this->previewRecipientOptionValues((string) $response->getBody());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([], $optionValues);
    }

    /**
     * Werte des value-Attributs aller nicht-leeren Optionen im Vorschau-Auswahlfeld,
     * ermittelt über das tatsächlich gerenderte DOM statt über eine Suche im Rohtext.
     *
     * @return array<int, string>
     */
    private function previewRecipientOptionValues(string $html): array
    {
        $document = new DOMDocument();
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();

        $xpath = new DOMXPath($document);
        $options = $xpath->query('//select[@id="preview-recipient"]/option[@value!=""]');

        $values = [];
        if ($options !== false) {
            foreach ($options as $option) {
                $values[] = $option->getAttribute('value');
            }
        }

        return $values;
    }
}
