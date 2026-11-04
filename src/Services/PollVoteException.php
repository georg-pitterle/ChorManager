<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PollTextAnswer;
use RuntimeException;

/** Eine abgewiesene Stimme; der Grund steht in $reason, die Meldung ist für die Oberfläche. */
final class PollVoteException extends RuntimeException
{
    public const EMPTY_CHOICE = 'empty_choice';
    public const TOO_MANY = 'too_many';
    public const INVALID_OPTION = 'invalid_option';
    public const NOT_IN_AUDIENCE = 'not_in_audience';
    public const CLOSED = 'closed';
    public const ALREADY_VOTED = 'already_voted';
    public const EMPTY_ANSWER = 'empty_answer';
    public const ANSWER_TOO_LONG = 'answer_too_long';
    public const WRONG_KIND = 'wrong_kind';

    private const MESSAGES = [
        self::EMPTY_ANSWER => 'Bitte eine Antwort eingeben.',
        self::ANSWER_TOO_LONG => 'Eine Antwort darf höchstens '
            . PollTextAnswer::MAX_LENGTH . ' Zeichen lang sein.',
        self::WRONG_KIND => 'Diese Umfrage erwartet eine andere Art von Antwort.',
        self::EMPTY_CHOICE => 'Bitte eine Antwort wählen.',
        self::TOO_MANY => 'Bei dieser Umfrage ist nur eine Antwort möglich.',
        self::INVALID_OPTION => 'Diese Antwort gehört nicht zur Umfrage.',
        self::NOT_IN_AUDIENCE => 'Du gehörst nicht zur Zielgruppe.',
        self::CLOSED => 'Die Abstimmung ist bereits beendet.',
        self::ALREADY_VOTED => 'Deine Stimme wurde bereits anonym gezählt und lässt sich nicht mehr ändern.',
    ];

    public function __construct(public readonly string $reason)
    {
        parent::__construct(self::MESSAGES[$reason] ?? 'Ungültige Stimme.');
    }
}
