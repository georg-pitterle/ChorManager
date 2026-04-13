<?php

declare(strict_types=1);

namespace App\Services;

class PasswordPolicyService
{
    public const MIN_LENGTH = 12;

    public function validate(string $password): ?string
    {
        if (mb_strlen($password) < self::MIN_LENGTH) {
            return 'Das Passwort muss mindestens 12 Zeichen lang sein.';
        }

        if (!preg_match('/[A-Z]/', $password)) {
            return 'Das Passwort muss mindestens einen Großbuchstaben enthalten.';
        }

        if (!preg_match('/[a-z]/', $password)) {
            return 'Das Passwort muss mindestens einen Kleinbuchstaben enthalten.';
        }

        if (!preg_match('/\d/', $password)) {
            return 'Das Passwort muss mindestens eine Zahl enthalten.';
        }

        if (!preg_match('/[^A-Za-z0-9]/', $password)) {
            return 'Das Passwort muss mindestens ein Sonderzeichen enthalten.';
        }

        return null;
    }
}
