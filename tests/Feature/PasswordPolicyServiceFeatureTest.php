<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\PasswordPolicyService;
use App\Util\InputValidator;
use PHPUnit\Framework\TestCase;

class PasswordPolicyServiceFeatureTest extends TestCase
{
    public function testAcceptsStrongPassword(): void
    {
        $policy = new PasswordPolicyService();
        $this->assertNull($policy->validate('Str0ng!Passw0rd'));
    }

    public function testRejectsWeakPasswords(): void
    {
        $policy = new PasswordPolicyService();

        $this->assertNotNull($policy->validate('short'));
        $this->assertNotNull($policy->validate('alllowercase123!'));
        $this->assertNotNull($policy->validate('ALLUPPERCASE123!'));
        $this->assertNotNull($policy->validate('NoSpecialCharacters1'));
    }

    /**
     * InputValidator hatte eine zweite, schwaechere Passwortregel (6 Zeichen) neben der echten
     * Policy. Die Passwortlaenge darf nur an einer Stelle definiert sein.
     */
    public function testPasswordRulesLiveOnlyInThePolicyService(): void
    {
        $this->assertFalse(
            method_exists(InputValidator::class, 'validatePassword'),
            'Passwortpruefungen gehoeren ausschliesslich in PasswordPolicyService.'
        );
        $this->assertSame(12, PasswordPolicyService::MIN_LENGTH);
    }

    /**
     * Die Mindestlänge stand als Ziffer im Meldungstext, obwohl sie als Konstante daneben
     * steht. Wird sie angehoben, verlangte die Meldung weiter zwölf Zeichen - und wer sie
     * befolgt, bekommt dieselbe Meldung erneut.
     */
    public function testLengthMessageNamesTheConfiguredMinimum(): void
    {
        $policy = new PasswordPolicyService();

        $message = $policy->validate('Kurz1!a');

        $this->assertNotNull($message);
        $this->assertStringContainsString((string) PasswordPolicyService::MIN_LENGTH, $message);
    }

    /**
     * Die Grenze selbst: ein Zeichen darunter ist zu kurz, genau darauf reicht.
     */
    public function testPasswordAtTheMinimumPassesAndOneCharacterShortDoesNot(): void
    {
        $policy = new PasswordPolicyService();

        $exact = str_pad('Aa1!', PasswordPolicyService::MIN_LENGTH, 'x');
        $short = str_pad('Aa1!', PasswordPolicyService::MIN_LENGTH - 1, 'x');

        $this->assertSame(PasswordPolicyService::MIN_LENGTH, mb_strlen($exact));
        $this->assertNull($policy->validate($exact));
        $this->assertNotNull($policy->validate($short));
    }

    /**
     * Gezählt werden Zeichen, nicht Bytes. Dieses Passwort hat genau die Mindestlänge,
     * belegt aber mehr Bytes; mit `strlen()` wäre es als lang genug durchgegangen, und die
     * Grenze läge für Umlaut-Passwörter faktisch niedriger.
     */
    public function testLengthCountsCharactersAndNotBytes(): void
    {
        $policy = new PasswordPolicyService();

        $atTheLimit = 'Grüßgott1!äö';

        $this->assertSame(PasswordPolicyService::MIN_LENGTH, mb_strlen($atTheLimit));
        $this->assertGreaterThan(PasswordPolicyService::MIN_LENGTH, strlen($atTheLimit));
        $this->assertNull($policy->validate($atTheLimit));
    }
}
