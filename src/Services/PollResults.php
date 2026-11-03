<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Poll;
use App\Models\PollOption;
use App\Models\PollTextAnswer;
use App\Models\User;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Zählt eine Umfrage aus: Stimmen je Antwort, Teilnahme gegen die Zielgruppe,
 * Spitzenreiter und - nur bei namentlichen Umfragen - wer was gewählt hat,
 * in der eingestellten Namensreihenfolge.
 *
 * Prozent beziehen sich auf die Abstimmenden. Bei Einfachauswahl ist das
 * dasselbe wie die Zahl der Stimmen; bei Mehrfachauswahl kann die Summe über
 * 100 liegen.
 */
class PollResults
{
    public function __construct(
        private readonly PollAudienceService $audience,
        private readonly NameFormatterService $names = new NameFormatterService()
    ) {
    }

    /**
     * @return array{
     *     options: list<array{id: int, label: string, count: int, percent: int, is_winner: bool,
     *         is_mine: bool, voters: list<User>}>,
     *     participant_count: int,
     *     audience_count: int,
     *     audience_shrunk: bool,
     *     my_option_ids: list<int>,
     *     answers: list<array{body: string, user: ?User, is_mine: bool}>
     * }
     */
    public function compute(Poll $poll, ?int $viewerUserId): array
    {
        $options = $poll->options()->get();
        $counts = DB::table('poll_votes')
            ->where('poll_id', $poll->id)
            ->groupBy('option_id')
            ->selectRaw('option_id, COUNT(*) AS votes')
            ->pluck('votes', 'option_id')
            ->map(static fn ($value): int => (int) $value)
            ->all();

        $participants = DB::table('poll_participants')->where('poll_id', $poll->id)->count();
        // Stimmen gelöschter Mitglieder bleiben, ihre Teilnahme geht mit dem
        // Konto. Die Basis darf deshalb nie unter die größte Einzelzahl fallen.
        $base = $poll->allows_multiple
            ? max($participants, $counts === [] ? 0 : max($counts))
            : array_sum($counts);
        $top = $counts === [] ? 0 : max($counts);

        $myOptionIds = [];
        $votersByOption = [];
        if (!$poll->is_anonymous) {
            if ($viewerUserId !== null && $viewerUserId > 0) {
                $myOptionIds = DB::table('poll_votes')
                    ->where('poll_id', $poll->id)
                    ->where('user_id', $viewerUserId)
                    ->pluck('option_id')
                    ->map(static fn ($id): int => (int) $id)
                    ->all();
            }
            $votersByOption = $this->votersByOption($poll);
        }

        $rows = [];
        foreach ($options as $option) {
            /** @var PollOption $option */
            $count = $counts[(int) $option->id] ?? 0;
            $rows[] = [
                'id' => (int) $option->id,
                'label' => (string) $option->label,
                'count' => $count,
                'percent' => $base > 0 ? (int) round($count * 100 / $base) : 0,
                'is_winner' => $top > 0 && $count === $top,
                'is_mine' => in_array((int) $option->id, $myOptionIds, true),
                'voters' => $votersByOption[(int) $option->id] ?? [],
            ];
        }

        $audienceCount = $this->audience->memberCount($poll);

        return [
            'options' => $rows,
            'participant_count' => $participants,
            'audience_count' => $audienceCount,
            // Nicht nur "mehr Stimmen als Zielgruppe": Schon eine einzige Stimme
            // von außerhalb machte "x von y" falsch.
            'audience_shrunk' => $participants > 0 && $this->audience->hasParticipantsOutside($poll),
            'my_option_ids' => $myOptionIds,
            'answers' => $poll->isOpenQuestion() ? $this->textAnswers($poll, $viewerUserId) : [],
        ];
    }

    /**
     * Die Antworten einer offenen Frage. Namentlich in der eingestellten
     * Namensreihenfolge; anonym nach der zufälligen Id - also in keiner
     * Reihenfolge, die etwas über die Schreibenden verrät.
     *
     * @return list<array{body: string, user: ?User, is_mine: bool}>
     */
    private function textAnswers(Poll $poll, ?int $viewerUserId): array
    {
        $answers = PollTextAnswer::query()->where('poll_id', $poll->id)->orderBy('id')->get();
        if ($poll->is_anonymous) {
            return $answers
                ->map(static fn (PollTextAnswer $answer): array => [
                    'body' => (string) $answer->body,
                    'user' => null,
                    'is_mine' => false,
                ])
                ->values()
                ->all();
        }

        $query = User::query()->whereIn('id', $answers->pluck('user_id')->filter()->unique()->all() ?: [0]);
        $this->names->applyNameOrder($query);
        $users = $query->get(User::LIST_COLUMNS)->keyBy('id');

        $rows = [];
        // Erst die Antworten von Mitgliedern in Namensreihenfolge, dann die von
        // gelöschten Konten.
        foreach ($users as $user) {
            foreach ($answers->where('user_id', (int) $user->id) as $answer) {
                $rows[] = [
                    'body' => (string) $answer->body,
                    'user' => $user,
                    'is_mine' => (int) $user->id === $viewerUserId,
                ];
            }
        }
        foreach ($answers->whereNull('user_id') as $answer) {
            $rows[] = ['body' => (string) $answer->body, 'user' => null, 'is_mine' => false];
        }

        return $rows;
    }

    /**
     * @return array<int, list<User>>
     */
    private function votersByOption(Poll $poll): array
    {
        $rows = DB::table('poll_votes')
            ->where('poll_id', $poll->id)
            ->whereNotNull('user_id')
            ->get(['option_id', 'user_id']);
        if ($rows->isEmpty()) {
            return [];
        }

        // Reihenfolge wie überall: nach der eingestellten Namensanzeige.
        $query = User::query()->whereIn('id', $rows->pluck('user_id')->unique()->all());
        $this->names->applyNameOrder($query);
        $users = $query->get(User::LIST_COLUMNS);

        $optionsByUser = [];
        foreach ($rows as $row) {
            $optionsByUser[(int) $row->user_id][] = (int) $row->option_id;
        }

        $result = [];
        foreach ($users as $user) {
            foreach ($optionsByUser[(int) $user->id] ?? [] as $optionId) {
                $result[$optionId][] = $user;
            }
        }

        return $result;
    }
}
