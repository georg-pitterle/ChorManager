<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\NewsletterLockingService;
use PHPUnit\Framework\TestCase;

/**
 * Unbekannte Platzhalter werden gemeldet, aber nicht aus dem Text entfernt.
 */
final class NewsletterUnknownPlaceholderFeatureTest extends TestCase
{
    use NewsletterControllerTestScaffold;

    public function testUpdateReturnsWarningForUnknownTokens(): void
    {
        $creator = $this->createUser('Anna');
        $recipient = $this->createUser('Georg');
        $newsletter = $this->createNewsletter($creator, $recipient);

        $_SESSION['user_id'] = (int) $creator->id;
        $_SESSION['can_manage_newsletters'] = true;
        (new NewsletterLockingService())->acquireLock($newsletter, (int) $creator->id);

        $request = $this->makeRequest('POST', "/newsletters/{$newsletter->id}", [
            'title' => 'Info {{tippfehler}}',
            'content_html' => '<p>{{anrede}} {{quatsch}}</p>',
            'suppress_flash' => '1',
        ])->withAttribute('id', (string) $newsletter->id);

        $response = $this->controller()->update($request, $this->makeResponse());
        $payload = json_decode((string) $response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNotEmpty($payload['warnings']);
        $this->assertStringContainsString('tippfehler', implode(' ', $payload['warnings']));
        $this->assertStringContainsString('quatsch', implode(' ', $payload['warnings']));
    }

    /**
     * Der Modal-Weg zeigt die Warnung des Speicher-Endpunkts nicht selbst an - er schließt den
     * Dialog und lädt die Seite per window.location.reload() neu (siehe newsletters-edit.js). Der
     * Endpunkt muss die Warnung deshalb zusätzlich in die Sitzung legen, symmetrisch zum
     * Versand-Endpunkt, sonst geht sie beim Neuladen verloren.
     */
    public function testUpdateInModalSetsSessionWarningForUnknownTokens(): void
    {
        $creator = $this->createUser('Anna');
        $recipient = $this->createUser('Georg');
        $newsletter = $this->createNewsletter($creator, $recipient);

        $_SESSION['user_id'] = (int) $creator->id;
        $_SESSION['can_manage_newsletters'] = true;
        (new NewsletterLockingService())->acquireLock($newsletter, (int) $creator->id);

        $request = $this->makeRequest('POST', "/newsletters/{$newsletter->id}", [
            'title' => 'Info {{tippfehler}}',
            'content_html' => '<p>{{anrede}}</p>',
            'is_modal' => '1',
        ])->withAttribute('id', (string) $newsletter->id);

        $this->controller()->update($request, $this->makeResponse());

        $this->assertArrayHasKey('warning', $_SESSION);
        $this->assertStringContainsString('tippfehler', (string) $_SESSION['warning']);
    }

    /**
     * Der automatische Hintergrund-Speicherlauf (alle 30 Sekunden) darf die Sitzung nicht bei
     * jedem Durchlauf erneut mit demselben Hinweis überschütten - er markiert sich über
     * suppress_flash, das schon heute die Erfolgsmeldung unterdrückt.
     */
    public function testUpdateBackgroundSaveDoesNotSetSessionWarning(): void
    {
        $creator = $this->createUser('Anna');
        $recipient = $this->createUser('Georg');
        $newsletter = $this->createNewsletter($creator, $recipient);

        $_SESSION['user_id'] = (int) $creator->id;
        $_SESSION['can_manage_newsletters'] = true;
        (new NewsletterLockingService())->acquireLock($newsletter, (int) $creator->id);

        $request = $this->makeRequest('POST', "/newsletters/{$newsletter->id}", [
            'title' => 'Info {{tippfehler}}',
            'content_html' => '<p>{{anrede}}</p>',
            'is_modal' => '1',
            'suppress_flash' => '1',
        ])->withAttribute('id', (string) $newsletter->id);

        $this->controller()->update($request, $this->makeResponse());

        $this->assertArrayNotHasKey('warning', $_SESSION);
    }

    /**
     * Der klassische, nicht eingebettete Bearbeiten-Aufruf bleibt auf derselben Seite und zeigt
     * die Warnung bereits selbst über die JSON-Antwort an (newsletters-edit.js). Eine zusätzliche
     * Sitzungswarnung würde dort verspätet ein zweites Mal auftauchen, sobald irgendeine andere
     * Seite als Nächstes lädt - deshalb bleibt die Sitzung hier unangetastet.
     */
    public function testUpdateOutsideModalDoesNotSetSessionWarning(): void
    {
        $creator = $this->createUser('Anna');
        $recipient = $this->createUser('Georg');
        $newsletter = $this->createNewsletter($creator, $recipient);

        $_SESSION['user_id'] = (int) $creator->id;
        $_SESSION['can_manage_newsletters'] = true;
        (new NewsletterLockingService())->acquireLock($newsletter, (int) $creator->id);

        $request = $this->makeRequest('POST', "/newsletters/{$newsletter->id}", [
            'title' => 'Info {{tippfehler}}',
            'content_html' => '<p>{{anrede}}</p>',
        ])->withAttribute('id', (string) $newsletter->id);

        $this->controller()->update($request, $this->makeResponse());

        $this->assertArrayNotHasKey('warning', $_SESSION);
    }

    public function testUpdateWithoutUnknownTokensHasNoWarnings(): void
    {
        $creator = $this->createUser('Anna');
        $recipient = $this->createUser('Georg');
        $newsletter = $this->createNewsletter($creator, $recipient);

        $_SESSION['user_id'] = (int) $creator->id;
        $_SESSION['can_manage_newsletters'] = true;
        (new NewsletterLockingService())->acquireLock($newsletter, (int) $creator->id);

        $request = $this->makeRequest('POST', "/newsletters/{$newsletter->id}", [
            'title' => 'Info',
            'content_html' => '<p>{{anrede}}</p>',
            'suppress_flash' => '1',
        ])->withAttribute('id', (string) $newsletter->id);

        $payload = json_decode(
            (string) $this->controller()->update($request, $this->makeResponse())->getBody(),
            true
        );

        $this->assertSame([], $payload['warnings']);
    }

    /**
     * Der Anlegen-Endpunkt hat keinen echten Seitenwechsel: Der einzige verlinkte Weg ist der
     * Modal-Dialog, der den Editor per Anfrage nachlädt, ohne die Seite zu wechseln. Eine
     * Warnung über die Sitzung würde dort nie erscheinen, weil layout_modal.twig den
     * Session-Meldungsbereich gar nicht einbindet (siehe NewsletterModalWarningDeliveryFeatureTest).
     * Die Warnung muss deshalb ausschließlich im JSON stehen.
     */
    public function testStoreReturnsWarningInJsonWithoutTouchingSession(): void
    {
        $creator = $this->createUser('Anna');
        $_SESSION['user_id'] = (int) $creator->id;

        $request = $this->makeRequest('POST', '/newsletters', [
            'title' => 'Info {{tippfehler}}',
            'content_html' => '<p>{{quatsch}}</p>',
        ]);

        $response = $this->controller()->store($request, $this->makeResponse());
        $payload = json_decode((string) $response->getBody(), true);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertNotEmpty($payload['warnings']);
        $this->assertStringContainsString('tippfehler', implode(' ', $payload['warnings']));
        $this->assertStringContainsString('quatsch', implode(' ', $payload['warnings']));
        $this->assertArrayNotHasKey('warning', $_SESSION);
    }

    public function testStoreWithoutUnknownTokensDoesNotSetSessionWarning(): void
    {
        $creator = $this->createUser('Anna');
        $_SESSION['user_id'] = (int) $creator->id;

        $request = $this->makeRequest('POST', '/newsletters', [
            'title' => 'Info',
            'content_html' => '<p>Text ohne Platzhalter</p>',
        ]);

        $response = $this->controller()->store($request, $this->makeResponse());
        $payload = json_decode((string) $response->getBody(), true);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame([], $payload['warnings']);
        $this->assertArrayNotHasKey('warning', $_SESSION);
    }

    public function testUnknownTokenSurvivesInStoredContent(): void
    {
        $creator = $this->createUser('Anna');
        $recipient = $this->createUser('Georg');
        $newsletter = $this->createNewsletter($creator, $recipient);

        $_SESSION['user_id'] = (int) $creator->id;
        $_SESSION['can_manage_newsletters'] = true;
        (new NewsletterLockingService())->acquireLock($newsletter, (int) $creator->id);

        $request = $this->makeRequest('POST', "/newsletters/{$newsletter->id}", [
            'title' => 'Info',
            'content_html' => '<p>{{quatsch}}</p>',
            'suppress_flash' => '1',
        ])->withAttribute('id', (string) $newsletter->id);

        $this->controller()->update($request, $this->makeResponse());

        $this->assertStringContainsString('{{quatsch}}', (string) $newsletter->fresh()->content_html);
    }
}
