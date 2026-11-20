<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Models\Event;
use App\Policies\EventPolicy;
use App\Services\EventAudienceService;
use PHPUnit\Framework\TestCase;

/**
 * Wer einen Termin sieht und wer ihn ändern darf.
 *
 * Ob jemand zur Zielgruppe gehört, kommt von außen (EventAudienceService) und
 * wird hier gestellt; die Policy verknüpft es nur mit dem Recht
 * `can_manage_events`.
 *
 * Die Reihe war die einzige Policy ohne eigene Prüfung. Gedeckt war sie nur
 * mittelbar über die Serien-Feature-Tests, und zwar genau in der Richtung, die
 * dort gerade gebraucht wird: dass ein Verwalter durchkommt. Dass ein
 * Zielgruppenmitglied ohne Recht den Termin sieht, ihn aber nicht ändert, hing
 * an keiner Prüfung - und das ist die Grenze, auf die es hier ankommt.
 */
final class EventPolicyTest extends TestCase
{
    public function testDieZielgruppeSiehtDenTerminAendertIhnAberNicht(): void
    {
        $policy = new EventPolicy(['user_id' => 7], $this->audienceFor(eligible: true));

        $this->assertTrue($policy->canAccessEvent(new Event()));
        $this->assertFalse($policy->canEditEvent(new Event()));
        $this->assertFalse($policy->canManageEvents());
        $this->assertSame(7, $policy->currentUserId());
    }

    public function testWerNichtZurZielgruppeGehoertSiehtDenTerminNicht(): void
    {
        $policy = new EventPolicy(['user_id' => 7], $this->audienceFor(eligible: false));

        $this->assertFalse($policy->canAccessEvent(new Event()));
        $this->assertFalse($policy->canEditEvent(new Event()));
    }

    /**
     * Das Recht trägt über die eigene Zielgruppe hinaus - sonst könnte die
     * Terminverwaltung genau die Termine nicht pflegen, für die sie zuständig
     * ist. Die Zielgruppe wird dafür gar nicht erst befragt.
     */
    public function testDieTerminverwaltungSiehtUndAendertAuchFremdeTermine(): void
    {
        $audience = $this->createMock(EventAudienceService::class);
        $audience->expects($this->never())->method('isUserEligible');

        $policy = new EventPolicy(
            ['user_id' => 7, 'can_manage_events' => 1],
            $audience
        );

        $this->assertTrue($policy->canManageEvents());
        $this->assertTrue($policy->canAccessEvent(new Event()));
        $this->assertTrue($policy->canEditEvent(new Event()));
    }

    /**
     * Ohne angemeldete Person wird die Zielgruppe nicht befragt: Eine
     * Zielgruppenzeile, die auf die 0 zutrifft, öffnete sonst jeden Abruf ohne
     * Sitzung.
     */
    public function testOhneAnmeldungKeinZugang(): void
    {
        $audience = $this->createMock(EventAudienceService::class);
        $audience->expects($this->never())->method('isUserEligible');

        $policy = new EventPolicy([], $audience);

        $this->assertSame(0, $policy->currentUserId());
        $this->assertFalse($policy->canAccessEvent(new Event()));
        $this->assertFalse($policy->canEditEvent(new Event()));
    }

    /**
     * Das Recht wird auf Wahrheitswert geprüft, nicht strikt auf true - die
     * Sitzung trägt es als 1, und RoleMiddleware liest denselben Schlüssel
     * ebenfalls nur truthy.
     */
    public function testDasRechtZaehltAuchAlsEins(): void
    {
        $policy = new EventPolicy(
            ['user_id' => 7, 'can_manage_events' => '1'],
            $this->audienceFor(eligible: false)
        );

        $this->assertTrue($policy->canManageEvents());
    }

    /**
     * Ein Stub, kein Mock: Diese Prüfungen sagen nur, was die Zielgruppe
     * antwortet, und erwarten nichts von ihr.
     */
    private function audienceFor(bool $eligible): EventAudienceService
    {
        $audience = $this->createStub(EventAudienceService::class);
        $audience->method('isUserEligible')->willReturn($eligible);

        return $audience;
    }
}
