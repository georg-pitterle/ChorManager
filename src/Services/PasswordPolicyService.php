<?php

declare(strict_types=1);

namespace App\Services;

class PasswordPolicyService
{
    public const MIN_LENGTH = 12;

    public function validate(string $password): ?string
    {
        if (mb_strlen($password) < self::MIN_LENGTH) {
            // Die Zahl aus der Konstante, nicht als Ziffer im Text: Wird die Mindestlänge
            // angehoben, nannte die Meldung sonst weiter die alte - und wer sie befolgt,
            // bekommt dieselbe Meldung erneut.
            return 'Das Passwort muss mindestens ' . self::MIN_LENGTH . ' Zeichen lang sein.';
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

        // `\p{L}` statt `A-Za-z`: Geprüft wurde nur gegen das lateinische
        // Grundalphabet, womit ä, ö, ü und ß als Sonderzeichen galten - "Grüßgottäöü1"
        // erfüllte die Regel, ohne eines zu enthalten. Wer "mindestens ein
        // Sonderzeichen" liest, meint damit nicht seinen eigenen Namen. Ein Buchstabe
        // ist ein Buchstabe, in jeder Schrift.
        //
        // `!== 1` statt `!`: Bei ungültigem UTF-8 gibt preg_match() false zurück, und
        // das soll wie "kein Sonderzeichen gefunden" wirken, nicht wie ein Treffer.
        if (preg_match('/[^\p{L}\p{N}]/u', $password) !== 1) {
            return 'Das Passwort muss mindestens ein Sonderzeichen enthalten.';
        }

        return null;
    }
}
