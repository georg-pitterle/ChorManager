<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\PasswordPolicyService;
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
}
