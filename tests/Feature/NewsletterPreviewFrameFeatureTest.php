<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\NewsletterArchive;
use PHPUnit\Framework\TestCase;

/**
 * previewFrame() liefert das vollständige Mail-HTML eines gespeicherten Newsletters unmittelbar
 * aus - denselben Rahmen (Logo bzw. Markenfarbe, Betreff als Überschrift, Inhalt, Fußbereich)
 * wie beim Versand. Die Route dient preview.twig als Quelle des eingebetteten Rahmens und trägt
 * dieselbe Berechtigungsprüfung wie preview() selbst.
 */
final class NewsletterPreviewFrameFeatureTest extends TestCase
{
    use NewsletterControllerTestScaffold;

    public function testRecipientSeesOwnDataInFrame(): void
    {
        $creator = $this->createUser('Anna');
        $recipient = $this->createUser('Georg');
        $newsletter = $this->createNewsletter($creator, $recipient);

        NewsletterArchive::create([
            'newsletter_id' => $newsletter->id,
            'user_id' => $recipient->id,
            'email' => $recipient->email,
            'sent_at' => '2026-08-18 10:00:00',
        ]);

        $_SESSION['user_id'] = (int) $recipient->id;
        $_SESSION['can_manage_newsletters'] = false;

        $request = $this->makeRequest('GET', "/newsletters/{$newsletter->id}/preview-frame")
            ->withAttribute('id', (string) $newsletter->id);
        $response = $this->controller()->previewFrame($request, $this->makeResponse());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('text/html', $response->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('Hallo Georg', (string) $response->getBody());
    }

    public function testManagerCanPreviewFrameAsResolvedRecipient(): void
    {
        $creator = $this->createUser('Anna');
        $recipient = $this->createUser('Georg');
        $newsletter = $this->createNewsletter($creator, $recipient);

        $_SESSION['user_id'] = (int) $creator->id;
        $_SESSION['can_manage_newsletters'] = true;

        $request = $this->makeRequest(
            'GET',
            "/newsletters/{$newsletter->id}/preview-frame",
            [],
            ['recipient_id' => (string) $recipient->id]
        )->withAttribute('id', (string) $newsletter->id);
        $response = $this->controller()->previewFrame($request, $this->makeResponse());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Hallo Georg', (string) $response->getBody());
    }

    public function testManagerCannotPreviewFrameAsUnrelatedUser(): void
    {
        $creator = $this->createUser('Anna');
        $recipient = $this->createUser('Georg');
        $outsider = $this->createUser('Fremd');
        $newsletter = $this->createNewsletter($creator, $recipient);

        $_SESSION['user_id'] = (int) $creator->id;
        $_SESSION['can_manage_newsletters'] = true;

        $request = $this->makeRequest(
            'GET',
            "/newsletters/{$newsletter->id}/preview-frame",
            [],
            ['recipient_id' => (string) $outsider->id]
        )->withAttribute('id', (string) $newsletter->id);
        $response = $this->controller()->previewFrame($request, $this->makeResponse());

        $this->assertSame(403, $response->getStatusCode());
    }

    /**
     * Ohne Verwaltungsrecht und ohne eigenen Archiveintrag zu diesem Newsletter bekommt der
     * eingebettete Rahmen nichts - genau dieselbe Sperre wie bei der bestehenden Vorschau.
     */
    public function testStrangerWithoutRightAndWithoutArchiveEntryGetsNothing(): void
    {
        $creator = $this->createUser('Anna');
        $recipient = $this->createUser('Georg');
        $stranger = $this->createUser('Fremd');
        $newsletter = $this->createNewsletter($creator, $recipient);

        $_SESSION['user_id'] = (int) $stranger->id;
        $_SESSION['can_manage_newsletters'] = false;

        $request = $this->makeRequest('GET', "/newsletters/{$newsletter->id}/preview-frame")
            ->withAttribute('id', (string) $newsletter->id);
        $response = $this->controller()->previewFrame($request, $this->makeResponse());

        $this->assertSame(403, $response->getStatusCode());
    }

    /**
     * Regressionsschutz: Ohne das Recht zum Verwalten von Newslettern wird der
     * recipient_id-Parameter vollständig ignoriert, auch wenn der Betrachter selbst zu den
     * Empfängern zählt und dadurch über einen eigenen Archiveintrag Zugriff auf die Route hat.
     */
    public function testPreviewFrameIgnoresRecipientParameterWithoutManagementRight(): void
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
            "/newsletters/{$newsletter->id}/preview-frame",
            [],
            ['recipient_id' => (string) $outsider->id]
        )->withAttribute('id', (string) $newsletter->id);
        $response = $this->controller()->previewFrame($request, $this->makeResponse());

        $body = (string) $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Hallo Georg', $body);
        $this->assertStringNotContainsString('Hallo Fremd', $body);
    }

    /**
     * Belegt, dass die gespeicherte Vorschau denselben vollständigen Mail-Rahmen trägt wie der
     * echte Versand: Markenfarbe, Kopfbereich, Betreff als Überschrift und Inhalt. Der Link
     * "Diesen Newsletter im Browser ansehen" fehlt hier bewusst, siehe
     * testPreviewFrameOmitsBrowseLink().
     */
    public function testPreviewFrameCarriesTheBrandFrame(): void
    {
        $creator = $this->createUser('Anna');
        $recipient = $this->createUser('Georg');
        $newsletter = $this->createNewsletter($creator, $recipient);
        $newsletter->update(['title' => 'Herbstkonzert-Neuigkeiten']);

        $_SESSION['user_id'] = (int) $creator->id;
        $_SESSION['can_manage_newsletters'] = true;

        $request = $this->makeRequest('GET', "/newsletters/{$newsletter->id}/preview-frame")
            ->withAttribute('id', (string) $newsletter->id);
        $response = $this->controller()->previewFrame($request, $this->makeResponse());
        $html = (string) $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('#E8A817', $html, 'Markenfarbe fehlt.');
        $this->assertStringContainsString('#1d2836', $html, 'Kopfbereich fehlt.');
        $this->assertStringContainsString('Herbstkonzert-Neuigkeiten', $html, 'Betreff als Überschrift fehlt.');
    }

    /**
     * Wer die Vorschau ansieht, ist bereits in der Browser-Ansicht: Ein Klick auf "im Browser
     * ansehen" würde die Anwendungsseite samt Navigation und einem erneut verschachtelten Rahmen
     * in den kleinen eingebetteten Rahmen laden, weil die Sandbox zwar fremde Navigation
     * verhindert, die Selbstnavigation des Rahmens aber erlaubt bleibt. Deshalb entfällt der
     * Link hier, bleibt aber im echten Versand und in der Testmail erhalten - siehe
     * NewsletterPersonalizedSendFeatureTest::testQueuedMailCarriesTheBrandFrame() und
     * NewsletterTestMailFeatureTest::testTestMailCarriesTheBrandFrame().
     */
    public function testPreviewFrameOmitsBrowseLink(): void
    {
        $creator = $this->createUser('Anna');
        $recipient = $this->createUser('Georg');
        $newsletter = $this->createNewsletter($creator, $recipient);

        $_SESSION['user_id'] = (int) $creator->id;
        $_SESSION['can_manage_newsletters'] = true;

        $request = $this->makeRequest('GET', "/newsletters/{$newsletter->id}/preview-frame")
            ->withAttribute('id', (string) $newsletter->id);
        $response = $this->controller()->previewFrame($request, $this->makeResponse());
        $html = (string) $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringNotContainsString('Diesen Newsletter im Browser ansehen', $html);
        $this->assertStringNotContainsString('/newsletters/' . $newsletter->id . '/preview"', $html);
    }

    /**
     * preview.twig muss den vollständigen Rahmen über einen eingebetteten, streng sandboxten
     * Rahmen einbinden statt den Inhalt roh in die Seite zu schreiben - und weiterhin erkennen
     * lassen, mit wessen Daten die Vorschau gefüllt ist.
     */
    public function testPreviewPageEmbedsSandboxedFrameAndKeepsDataHint(): void
    {
        $creator = $this->createUser('Anna');
        $recipient = $this->createUser('Georg');
        $newsletter = $this->createNewsletter($creator, $recipient);

        $_SESSION['user_id'] = (int) $creator->id;
        $_SESSION['can_manage_newsletters'] = true;

        $request = $this->makeRequest('GET', "/newsletters/{$newsletter->id}/preview")
            ->withAttribute('id', (string) $newsletter->id);
        $response = $this->controller()->preview($request, $this->makeResponse());
        $body = (string) $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString(
            'src="/newsletters/' . $newsletter->id . '/preview-frame"',
            $body
        );
        $this->assertStringContainsString('sandbox=""', $body);
        $this->assertStringContainsString('eigenen Daten', $body);
        $this->assertStringNotContainsString('{{anrede}}', $body);
    }
}
