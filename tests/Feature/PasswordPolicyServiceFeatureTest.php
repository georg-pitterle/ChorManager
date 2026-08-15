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
}
