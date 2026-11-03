<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Poll;
use App\Models\PollTextAnswer;
use App\Models\PollVote;
use Illuminate\Database\Capsule\Manager as DB;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Nimmt eine Stimme an - oder weist sie ab, ohne eine Spur zu hinterlassen.
 *
 * Alles läuft in einer Transaktion mit gesperrter Umfragezeile: Frist, Auswahl
 * und Zielgruppe werden geprüft, bevor irgendetwas geschrieben wird.
 * poll_participants ist die Sperre gegen doppelte Stimmen: Ist die Zeile schon
 * da, wird eine anonyme Stimme abgewiesen und eine namentliche ersetzt.
 *
 * Bei anonymen Umfragen bleibt poll_votes.user_id leer. Das Protokoll nennt
 * nur die Umfrage, nie die Person und nie die Antwort.
 */
class PollVoteService
{
    private LoggerInterface $logger;

    public function __construct(
        private readonly PollAudienceService $audience,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * @param list<int> $optionIds
     * @throws PollVoteException
     */
    public function vote(Poll $poll, int $userId, array $optionIds): void
    {
        $optionIds = array_values(array_unique(array_filter(
            array_map('intval', $optionIds),
            static fn (int $id): bool => $id > 0
        )));

        DB::connection()->transaction(function () use ($poll, $userId, $optionIds): void {
            /** @var Poll $locked */
            $locked = Poll::query()->whereKey($poll->id)->lockForUpdate()->firstOrFail();

            if ($locked->isClosed()) {
                throw new PollVoteException(PollVoteException::CLOSED);
            }

            if ($locked->isOpenQuestion()) {
                throw new PollVoteException(PollVoteException::WRONG_KIND);
            }

            if ($optionIds === []) {
                throw new PollVoteException(PollVoteException::EMPTY_CHOICE);
            }

            $known = $locked->options()->whereIn('id', $optionIds)->count();
            if ($known !== count($optionIds)) {
                throw new PollVoteException(PollVoteException::INVALID_OPTION);
            }

            if (!$locked->allows_multiple && count($optionIds) > 1) {
                throw new PollVoteException(PollVoteException::TOO_MANY);
            }

            if (!$this->audience->isMember($locked, $userId)) {
                throw new PollVoteException(PollVoteException::NOT_IN_AUDIENCE);
            }

            $isNew = DB::table('poll_participants')->insertOrIgnore([
                'poll_id' => (int) $locked->id,
                'user_id' => $userId,
            ]) === 1;

            if (!$isNew) {
                if ($locked->is_anonymous) {
                    throw new PollVoteException(PollVoteException::ALREADY_VOTED);
                }

                PollVote::query()->where('poll_id', $locked->id)->where('user_id', $userId)->delete();
            }

            foreach ($optionIds as $optionId) {
                PollVote::create([
                    'poll_id' => (int) $locked->id,
                    'option_id' => $optionId,
                    'user_id' => $locked->is_anonymous ? null : $userId,
                ]);
            }
        });

        $this->logger->info('Poll vote cast.', ['event' => 'poll.voted', 'poll_id' => (int) $poll->id]);
    }

    /**
     * Die eigene Antwort auf eine offene Frage - nach denselben Regeln wie eine
     * Stimme: erst alles prüfen, dann schreiben; anonym endgültig, namentlich
     * ersetzt.
     *
     * @throws PollVoteException
     */
    public function answer(Poll $poll, int $userId, string $text): void
    {
        // Zeilenumbrüche vereinheitlichen, damit ein Formular aus Windows nicht
        // mit \r\n mehr Zeichen zählt, als zu sehen sind.
        $body = trim(str_replace(["\r\n", "\r"], "\n", $text));

        DB::connection()->transaction(function () use ($poll, $userId, $body): void {
            /** @var Poll $locked */
            $locked = Poll::query()->whereKey($poll->id)->lockForUpdate()->firstOrFail();

            if ($locked->isClosed()) {
                throw new PollVoteException(PollVoteException::CLOSED);
            }

            if (!$locked->isOpenQuestion()) {
                throw new PollVoteException(PollVoteException::WRONG_KIND);
            }

            if ($body === '') {
                throw new PollVoteException(PollVoteException::EMPTY_ANSWER);
            }

            if (mb_strlen($body) > PollTextAnswer::MAX_LENGTH) {
                throw new PollVoteException(PollVoteException::ANSWER_TOO_LONG);
            }

            if (!$this->audience->isMember($locked, $userId)) {
                throw new PollVoteException(PollVoteException::NOT_IN_AUDIENCE);
            }

            $isNew = DB::table('poll_participants')->insertOrIgnore([
                'poll_id' => (int) $locked->id,
                'user_id' => $userId,
            ]) === 1;

            if (!$isNew) {
                if ($locked->is_anonymous) {
                    throw new PollVoteException(PollVoteException::ALREADY_VOTED);
                }

                PollTextAnswer::query()->where('poll_id', $locked->id)->where('user_id', $userId)->delete();
            }

            PollTextAnswer::create([
                'poll_id' => (int) $locked->id,
                'user_id' => $locked->is_anonymous ? null : $userId,
                'body' => $body,
            ]);
        });

        // Wie bei der Stimme: nur die Umfrage, nie die Person und nie der Text.
        $this->logger->info('Poll vote cast.', ['event' => 'poll.voted', 'poll_id' => (int) $poll->id]);
    }

    /** Der eigene Text einer namentlichen Antwort, für "Antwort ändern". */
    public function ownAnswer(Poll $poll, int $userId): ?string
    {
        if ($poll->is_anonymous || $userId <= 0) {
            return null;
        }

        $body = PollTextAnswer::query()->where('poll_id', $poll->id)->where('user_id', $userId)->value('body');

        return is_string($body) ? $body : null;
    }

    public function hasVoted(Poll $poll, int $userId): bool
    {
        return $userId > 0 && DB::table('poll_participants')
            ->where('poll_id', $poll->id)
            ->where('user_id', $userId)
            ->exists();
    }
}
