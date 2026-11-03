<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Poll;
use App\Models\PollOption;
use App\Models\PollTextAnswer;
use App\Models\User;
use App\Policies\PollPolicy;
use App\Services\Audience\AudienceDescriber;
use App\Services\Audience\AudienceFormInput;
use App\Services\Audience\InvalidAudienceFilterException;
use App\Services\NameFormatterService;
use App\Services\NotificationService;
use App\Services\Notifications\InAppMessage;
use App\Services\Notifications\InAppNotificationStore;
use App\Services\PollAudienceService;
use App\Services\PollLabels;
use App\Services\PollResults;
use App\Services\PollVoteException;
use App\Services\PollVoteService;
use App\Util\AppUrlResolver;
use App\Util\InputValidator;
use App\Util\NotificationType;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as DB;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Slim\Views\Twig;

/**
 * Umfragen: eine Frage mit festen Antworten an eine Zielgruppe.
 *
 * Eine Umfrage, die jemand nicht sehen darf, antwortet überall genau wie eine,
 * die es nicht gibt (notFound()). Das Ergebnis wird nur berechnet und
 * ausgeliefert, wenn die Person es sehen darf - auch nicht versteckt im HTML.
 * Ins Protokoll gehen nur Ids.
 */
class PollController
{
    public const NOT_FOUND = 'Umfrage nicht gefunden.';
    public const DESCRIPTION_MAX_LENGTH = 2000;

    /** Rückmeldung eines abgewiesenen Abstimmens bis zum nächsten Aufruf der Umfrage. */
    private const VOTE_FEEDBACK = 'poll_vote_feedback';

    private LoggerInterface $logger;

    public function __construct(
        private readonly Twig $view,
        private readonly PollPolicy $policy,
        private readonly PollAudienceService $audience,
        private readonly PollVoteService $votes,
        private readonly PollResults $results,
        private readonly NameFormatterService $names,
        private readonly ?NotificationService $notifications = null,
        ?LoggerInterface $logger = null,
        private readonly PollLabels $labels = new PollLabels()
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function index(Request $request, Response $response): Response
    {
        $userId = $this->policy->currentUserId();
        $visibleIds = $this->audience->visiblePollIds($userId);

        $query = Poll::query()->orderBy('closes_at');
        if (!$this->policy->canManage()) {
            $query->whereIn('id', $visibleIds === [] ? [0] : $visibleIds);
        }
        $polls = $query->get();

        $votedIds = DB::table('poll_participants')
            ->where('user_id', $userId)
            ->whereIn('poll_id', $polls->pluck('id')->all() ?: [0])
            ->pluck('poll_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $open = [];
        $closed = [];
        foreach ($polls as $poll) {
            $inAudience = in_array((int) $poll->id, $visibleIds, true);
            $hasVoted = in_array((int) $poll->id, $votedIds, true);
            $row = $this->labels->overviewRow($poll, $inAudience, $hasVoted);
            if ($poll->isClosed()) {
                $closed[] = $row;
            } else {
                $open[] = $row;
            }
        }

        // Fehlende Antworten zuerst, dann nach Frist; beendete die jüngsten zuerst.
        usort($open, static fn (array $a, array $b): int => [$a['missing'] ? 0 : 1, $a['closes_at']]
            <=> [$b['missing'] ? 0 : 1, $b['closes_at']]);
        usort($closed, static fn (array $a, array $b): int => $b['closes_at'] <=> $a['closes_at']);

        return $this->view->render($response, 'polls/index.twig', [
            'open_polls' => $open,
            'closed_polls' => $closed,
            'can_manage' => $this->policy->canManage(),
            'active_nav' => 'polls',
        ]);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $poll = Poll::find((int) ($args['id'] ?? 0));
        $userId = $this->policy->currentUserId();
        $inAudience = $poll !== null && $this->audience->isMember($poll, $userId);
        if ($poll === null || !$this->policy->canView($inAudience)) {
            return $this->notFound($response);
        }

        // Wer die Umfrage öffnet, hat ihren Glocken-Eintrag gesehen.
        (new InAppNotificationStore())->markEntityReadQuietly($userId, 'poll', (int) $poll->id, $this->logger);

        $query = $request->getQueryParams();
        $isClosed = $poll->isClosed();
        $hasVoted = $this->votes->hasVoted($poll, $userId);
        $canVote = $this->policy->canVote($inAudience) && !$isClosed;
        $wantsChange = ($query['change'] ?? '') === '1' && !$poll->is_anonymous;
        $wantsResults = ($query['results'] ?? '') === '1';
        $canSeeResults = $this->policy->canSeeResults($inAudience, $hasVoted, $isClosed);

        $showBallot = $canVote && (!$hasVoted || $wantsChange) && !($wantsResults && $canSeeResults);
        $results = !$showBallot && $canSeeResults ? $this->results->compute($poll, $userId) : null;

        // Anonym und noch offen: keine Zahlen je Antwort, für niemanden. Wer neu
        // lädt, während jemand abstimmt, läse sonst an der Änderung ab, wofür.
        $countsHidden = $poll->is_anonymous && !$isClosed;
        if ($results !== null && $countsHidden) {
            $results['options'] = [];
            // Bei offenen Fragen gilt dasselbe für die Texte: Ein neu
            // auftauchender Text verriete über den Zeitpunkt, von wem er ist.
            $results['answers'] = [];
        }

        [$voteError, $selection, $answerText] = $this->takeVoteFeedback((int) $poll->id);
        if ($voteError === null && $showBallot && $hasVoted) {
            $selection = $this->ownOptionIds($poll, $userId);
            $answerText = $this->votes->ownAnswer($poll, $userId);
        }

        return $this->view->render($response, 'polls/show.twig', [
            'poll' => $poll,
            'options' => $poll->options()->get(),
            'is_closed' => $isClosed,
            'has_voted' => $hasVoted,
            'in_audience' => $inAudience,
            'can_manage' => $this->policy->canManage(),
            'show_ballot' => $showBallot,
            'can_change' => $hasVoted && $canVote && !$poll->is_anonymous,
            // Anonym und offen gäbe es nur die Beteiligung zu sehen; der Knopf
            // verspräche einen Zwischenstand, den es nicht gibt.
            'can_peek' => $showBallot && $this->policy->canManage() && !$countsHidden,
            'can_return_to_ballot' => !$showBallot && $canVote && !$hasVoted,
            'results' => $results,
            'counts_hidden' => $countsHidden,
            'vote_error' => $voteError,
            'selection' => $selection,
            'answer_text' => $answerText ?? '',
            'answer_max_length' => PollTextAnswer::MAX_LENGTH,
            'deadline_label' => $this->labels->deadline($poll),
            'closes_date_label' => $this->labels->date($poll),
            'active_nav' => 'polls',
        ]);
    }

    public function vote(Request $request, Response $response, array $args): Response
    {
        $poll = Poll::find((int) ($args['id'] ?? 0));
        $userId = $this->policy->currentUserId();
        $inAudience = $poll !== null && $this->audience->isMember($poll, $userId);
        if ($poll === null || !$this->policy->canView($inAudience)) {
            return $this->notFound($response);
        }

        $back = '/polls/' . $poll->id;
        $data = (array) $request->getParsedBody();
        $raw = $data['option_ids'] ?? [];
        $optionIds = array_map(
            static fn (mixed $id): int => (int) InputValidator::asString($id),
            is_array($raw) ? array_values($raw) : [$raw]
        );
        $answerText = InputValidator::asString($data['answer'] ?? null);
        $hadVoted = $this->votes->hasVoted($poll, $userId);

        try {
            if ($poll->isOpenQuestion()) {
                $this->votes->answer($poll, $userId, $answerText);
            } else {
                $this->votes->vote($poll, $userId, $optionIds);
            }
        } catch (PollVoteException $exception) {
            if (in_array($exception->reason, [PollVoteException::CLOSED, PollVoteException::NOT_IN_AUDIENCE], true)) {
                $_SESSION['error'] = $exception->getMessage();
                return $this->redirect($response, $back);
            }

            // An die Umfrage gebunden: Öffnet die Sitzung zuerst eine andere, bleibt
            // die Meldung dort weg und wartet auf diese.
            $_SESSION[self::VOTE_FEEDBACK] = [
                'poll_id' => (int) $poll->id,
                'error' => $exception->getMessage(),
                'selection' => array_values(array_filter($optionIds, static fn (int $id): bool => $id > 0)),
                // Der geschriebene Text bleibt, damit niemand ihn neu tippen muss.
                'answer' => $answerText,
            ];

            return $this->redirect($response, $back . ($hadVoted && !$poll->is_anonymous ? '?change=1' : ''));
        }

        return $this->redirect($response, $back);
    }

    public function createForm(Request $request, Response $response): Response
    {
        return $this->renderForm($response, null, [
            'title' => '',
            'description' => '',
            'answer_kind' => Poll::KIND_CHOICE,
            'options' => ['', ''],
            'allows_multiple' => false,
            'is_anonymous' => false,
            'closes_on' => Carbon::now()->addDays(7)->format('Y-m-d'),
            'notify_members' => true,
        ], null, [[]]);
    }

    public function create(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        $form = $this->readForm($data, null);
        if (isset($form['error'])) {
            return $this->renderForm($response, null, $form['values'], $form['error'], null, $data['audience'] ?? [], 422);
        }

        $values = $form['values'];
        $poll = DB::connection()->transaction(function () use ($values, $form): Poll {
            $poll = Poll::create([
                'title' => $values['title'],
                'description' => $values['description'] === '' ? null : $values['description'],
                'answer_kind' => $values['answer_kind'],
                'allows_multiple' => $values['allows_multiple'],
                'is_anonymous' => $values['is_anonymous'],
                'closes_at' => $form['closes_at'],
                'created_by_user_id' => $this->policy->currentUserId() ?: null,
            ]);
            $this->replaceOptions($poll, $values['options']);
            $this->audience->setAudience($poll, $form['audience']);

            return $poll;
        });

        $this->log('poll.created', $poll);
        if ($values['notify_members']) {
            $this->notifyAudience($request, $poll);
        }
        $_SESSION['success'] = 'Umfrage gestartet.';

        return $this->redirect($response, '/polls/' . $poll->id);
    }

    public function editForm(Request $request, Response $response, array $args): Response
    {
        $poll = Poll::find((int) ($args['id'] ?? 0));
        if ($poll === null) {
            return $this->notFound($response);
        }

        return $this->renderForm($response, $poll, [
            'title' => (string) $poll->title,
            'description' => (string) ($poll->description ?? ''),
            'answer_kind' => (string) $poll->answer_kind,
            'options' => $poll->options()->pluck('label')->all(),
            'allows_multiple' => (bool) $poll->allows_multiple,
            'is_anonymous' => (bool) $poll->is_anonymous,
            'closes_on' => $poll->closes_at?->format('Y-m-d') ?? '',
            'notify_members' => false,
        ], null, $this->audience->conditionSets($poll));
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $poll = Poll::find((int) ($args['id'] ?? 0));
        if ($poll === null) {
            return $this->notFound($response);
        }

        $data = (array) $request->getParsedBody();
        $form = $this->readForm($data, $poll);
        if (isset($form['error'])) {
            return $this->renderForm($response, $poll, $form['values'], $form['error'], null, $data['audience'] ?? [], 422);
        }

        $values = $form['values'];
        DB::connection()->transaction(function () use ($poll, $values, $form): void {
            // Gesperrt wird in der Transaktion geprüft: Kommt die erste Stimme
            // zwischen Formularaufruf und Speichern, bleiben die Antworten.
            Poll::query()->whereKey($poll->id)->lockForUpdate()->first();
            $locked = $poll->hasVotes();

            $changes = [
                'title' => $values['title'],
                'description' => $values['description'] === '' ? null : $values['description'],
                'closes_at' => $form['closes_at'],
            ];
            if (!$locked) {
                $changes['allows_multiple'] = $values['allows_multiple'];
                $this->replaceOptions($poll, $values['options']);
            }
            $poll->update($changes);
            $this->audience->setAudience($poll, $form['audience']);
        });

        $this->log('poll.updated', $poll);
        $_SESSION['success'] = 'Umfrage gespeichert.';

        return $this->redirect($response, '/polls/' . $poll->id);
    }

    public function close(Request $request, Response $response, array $args): Response
    {
        $poll = Poll::find((int) ($args['id'] ?? 0));
        if ($poll === null) {
            return $this->notFound($response);
        }

        if (!$poll->isClosed()) {
            $poll->update(['closes_at' => Carbon::now()]);
            $this->log('poll.closed', $poll);
        }
        $_SESSION['success'] = 'Umfrage beendet.';

        return $this->redirect($response, '/polls/' . $poll->id);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $poll = Poll::find((int) ($args['id'] ?? 0));
        if ($poll === null) {
            return $this->notFound($response);
        }

        $this->log('poll.deleted', $poll);
        $poll->delete();
        $_SESSION['success'] = 'Umfrage gelöscht.';

        return $this->redirect($response, '/polls');
    }

    public function notFound(Response $response): Response
    {
        $response->getBody()->write(self::NOT_FOUND);

        return $response->withStatus(404);
    }

    /**
     * Formular lesen und prüfen. Beim Bearbeiten bleibt Anonym immer, wie es
     * ist; Antworten und Auswahlart werden erst beim Speichern gegen die erste
     * Stimme geprüft.
     *
     * @param array<string, mixed> $data
     * @return array{values: array<string, mixed>, error?: string, closes_at?: Carbon,
     *     audience?: list<array<string, list<int>>>}
     */
    private function readForm(array $data, ?Poll $poll): array
    {
        $rawOptions = is_array($data['options'] ?? null) ? $data['options'] : [];
        $options = array_values(array_filter(
            array_map(static fn (mixed $value): string => trim(InputValidator::asString($value)), $rawOptions),
            static fn (string $label): bool => $label !== ''
        ));

        // Die Art steht wie die Anonymität mit dem Start fest.
        $kind = $poll !== null
            ? (string) $poll->answer_kind
            : (InputValidator::asString($data['answer_kind'] ?? null) === Poll::KIND_TEXT ? Poll::KIND_TEXT : Poll::KIND_CHOICE);
        $isText = $kind === Poll::KIND_TEXT;

        $values = [
            'title' => trim(InputValidator::asString($data['title'] ?? null)),
            'description' => trim(InputValidator::asString($data['description'] ?? null)),
            'answer_kind' => $kind,
            // Eine offene Frage hat keine Antworten zur Auswahl; was im versteckten
            // Teil des Formulars steht, zählt nicht.
            'options' => $isText ? [] : $options,
            'allows_multiple' => !$isText && !empty($data['allows_multiple']),
            'is_anonymous' => $poll !== null ? (bool) $poll->is_anonymous : !empty($data['is_anonymous']),
            'closes_on' => trim(InputValidator::asString($data['closes_on'] ?? null)),
            'notify_members' => $poll === null && !empty($data['notify_members']),
        ];

        $locked = $poll !== null && $poll->hasVotes();
        if ($locked) {
            $values['options'] = $poll->options()->pluck('label')->all();
            $values['allows_multiple'] = (bool) $poll->allows_multiple;
        }

        $error = $this->validationError($values, $poll === null);
        if ($error !== null) {
            $values['options'] = $this->padOptions($values['options']);

            return ['values' => $values, 'error' => $error];
        }

        try {
            $audience = $this->audience->readRows($data);
        } catch (InvalidAudienceFilterException $exception) {
            $values['options'] = $this->padOptions($values['options']);

            return ['values' => $values, 'error' => $exception->getMessage()];
        }

        // Das Formular kennt nur das Datum. Bleibt es gleich, bleibt die Frist
        // genau, wie sie ist: Eine mit "Jetzt beenden" geschlossene Umfrage ginge
        // sonst beim Korrigieren eines Tippfehlers bis Mitternacht wieder auf.
        $current = $poll?->closes_at;
        if ($current !== null && $current->format('Y-m-d') === $values['closes_on']) {
            $closesAt = $current->copy();
        } else {
            $closesAt = Carbon::createFromFormat('!Y-m-d', $values['closes_on']);
            assert($closesAt instanceof Carbon);
            $closesAt->setTime(23, 59, 59);
        }

        return [
            'values' => $values,
            'closes_at' => $closesAt,
            'audience' => $audience,
        ];
    }

    /** @param array<string, mixed> $values */
    private function validationError(array $values, bool $isNew): ?string
    {
        if ($values['title'] === '') {
            return 'Bitte eine Frage eingeben.';
        }
        if (mb_strlen($values['title']) > Poll::TITLE_MAX_LENGTH) {
            return 'Die Frage darf höchstens 255 Zeichen lang sein.';
        }
        if (mb_strlen($values['description']) > self::DESCRIPTION_MAX_LENGTH) {
            return 'Die Erklärung darf höchstens 2000 Zeichen lang sein.';
        }
        if ($values['answer_kind'] === Poll::KIND_TEXT) {
            return $this->deadlineError($values, $isNew);
        }
        if (count($values['options']) < Poll::MIN_OPTIONS) {
            return 'Bitte mindestens zwei Antworten angeben.';
        }
        if (count($values['options']) > Poll::MAX_OPTIONS) {
            return 'Höchstens 20 Antworten.';
        }
        foreach ($values['options'] as $label) {
            if (mb_strlen($label) > Poll::OPTION_MAX_LENGTH) {
                return 'Eine Antwort darf höchstens 200 Zeichen lang sein.';
            }
        }
        // Zwei gleiche Antworten teilten die Stimmen und verfälschten den Sieger.
        $normalized = array_map(static fn (string $label): string => mb_strtolower($label), $values['options']);
        if (count(array_unique($normalized)) !== count($normalized)) {
            return 'Jede Antwort darf nur einmal vorkommen.';
        }

        return $this->deadlineError($values, $isNew);
    }

    /** @param array<string, mixed> $values */
    private function deadlineError(array $values, bool $isNew): ?string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $values['closes_on']);
        if ($date === false || $date->format('Y-m-d') !== $values['closes_on']) {
            return 'Bitte ein gültiges Datum angeben.';
        }
        if ($isNew && $values['closes_on'] < Carbon::now()->format('Y-m-d')) {
            return 'Die Frist darf nicht in der Vergangenheit liegen.';
        }

        return null;
    }

    /**
     * @param list<string> $options
     * @return list<string>
     */
    private function padOptions(array $options): array
    {
        while (count($options) < Poll::MIN_OPTIONS) {
            $options[] = '';
        }

        return $options;
    }

    /** @param list<string> $labels */
    private function replaceOptions(Poll $poll, array $labels): void
    {
        $current = $poll->options()->pluck('label')->all();
        if ($current === $labels) {
            return;
        }

        PollOption::query()->where('poll_id', $poll->id)->delete();
        foreach ($labels as $index => $label) {
            PollOption::create(['poll_id' => $poll->id, 'label' => $label, 'sort_order' => $index]);
        }
    }

    /**
     * @param array<string, mixed> $values
     * @param list<array<string, list<int>>>|null $storedSets
     */
    private function renderForm(
        Response $response,
        ?Poll $poll,
        array $values,
        ?string $error,
        ?array $storedSets,
        mixed $submittedRows = null,
        int $status = 200
    ): Response {
        $describer = new AudienceDescriber($this->names);
        $rows = $storedSets !== null
            ? $describer->describeSets($storedSets)
            : $this->rowsForRedisplay(AudienceFormInput::rows(is_array($submittedRows) ? $submittedRows : []), $describer);
        $sets = array_map(static fn (array $row): array => $row['conditions'], $rows);

        return $this->view->render($response->withStatus($status), 'polls/form.twig', [
            'poll' => $poll,
            'form' => $values,
            'form_error' => $error,
            'is_locked' => $poll !== null && $poll->hasVotes(),
            'audience_rows' => $rows,
            'audience_options' => $describer->options(
                AudienceDescriber::selectedIds($sets, 'project'),
                AudienceDescriber::selectedIds($sets, 'user')
            ),
            'min_date' => Carbon::now()->format('Y-m-d'),
            'max_options' => Poll::MAX_OPTIONS,
            'active_nav' => 'polls',
        ]);
    }

    /**
     * Formularzeilen nach einem Fehler wieder anzeigen, ohne sie zu prüfen -
     * wie im Termin-Formular.
     *
     * @param list<array{level: int, all: bool, conditions: array<string, list<string>>}> $rows
     * @return list<array<string, mixed>>
     */
    private function rowsForRedisplay(array $rows, AudienceDescriber $describer): array
    {
        return array_map(static function (array $row) use ($describer): array {
            $conditions = [];
            foreach ($row['conditions'] as $category => $ids) {
                $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0));
                if ($ids !== [] && in_array($category, \App\Models\AudienceFilterCondition::CATEGORIES, true)) {
                    $conditions[$category] = $ids;
                }
            }
            $label = $conditions === [] ? 'Neue Zielgruppe' : $describer->summarize($conditions);

            return [
                'label' => $row['all'] ? 'Alle Mitglieder' : $label,
                'conditions' => $conditions,
                'all' => $row['all'],
                'missing' => [],
            ];
        }, $rows);
    }

    /**
     * Meldung und Auswahl des letzten abgewiesenen Abstimmens - nur, wenn sie zu
     * dieser Umfrage gehören; dann einmalig.
     *
     * @return array{0: ?string, 1: list<int>, 2: ?string}
     */
    private function takeVoteFeedback(int $pollId): array
    {
        $feedback = $_SESSION[self::VOTE_FEEDBACK] ?? null;
        if (!is_array($feedback) || (int) ($feedback['poll_id'] ?? 0) !== $pollId) {
            return [null, [], null];
        }
        unset($_SESSION[self::VOTE_FEEDBACK]);

        $error = $feedback['error'] ?? null;
        $answer = $feedback['answer'] ?? null;

        return [
            is_string($error) ? $error : null,
            array_values(array_map('intval', (array) ($feedback['selection'] ?? []))),
            is_string($answer) ? $answer : null,
        ];
    }

    /** @return list<int> */
    private function ownOptionIds(Poll $poll, int $userId): array
    {
        return DB::table('poll_votes')
            ->where('poll_id', $poll->id)
            ->where('user_id', $userId)
            ->pluck('option_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    private function notifyAudience(Request $request, Poll $poll): void
    {
        if ($this->notifications === null) {
            return;
        }

        $recipients = User::query()
            ->whereIn('id', $this->audience->membersQuery($poll)->pluck('users.id')->all() ?: [0])
            ->get();
        if ($recipients->isEmpty()) {
            return;
        }

        $baseUrl = AppUrlResolver::resolveBaseUrl($request);
        $subject = 'Neue Umfrage: ' . $poll->title;
        $deadline = $this->labels->deadline($poll);

        $this->notifications->notify(
            NotificationType::POLL_CREATED,
            $recipients,
            $subject,
            'emails/notification_poll_created.twig',
            new InAppMessage($subject, $deadline, '/polls/' . $poll->id, 'poll', (int) $poll->id),
            [
                'poll' => $poll,
                'deadline_label' => $deadline,
                'link' => $baseUrl . '/polls/' . $poll->id,
                'profile_url' => $baseUrl . '/profile',
            ],
            $this->policy->currentUserId() ?: null
        );
    }

    private function log(string $event, Poll $poll): void
    {
        $this->logger->info('Poll changed.', [
            'event' => $event,
            'poll_id' => (int) $poll->id,
            'actor_user_id' => $this->policy->currentUserId(),
        ]);
    }

    private function redirect(Response $response, string $location): Response
    {
        return $response->withHeader('Location', $location)->withStatus(302);
    }
}
