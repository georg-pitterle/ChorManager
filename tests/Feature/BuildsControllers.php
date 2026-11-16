<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\EventController;
use App\Controllers\AttendanceController;
use App\Controllers\DashboardController;
use App\Controllers\EvaluationController;
use App\Controllers\FinanceController;
use App\Controllers\PasswordResetController;
use App\Controllers\ProfileController;
use App\Controllers\RegistrationController;
use App\Controllers\SponsorController;
use App\Controllers\TaskController;
use App\Controllers\UserController;
use App\Controllers\VoiceGroupController;
use App\Persistence\ProjectPersistence;
use App\Persistence\UserPersistence;
use App\Policies\NewsletterPolicy;
use App\Policies\SponsoringPolicy;
use App\Policies\TaskPolicy;
use App\Policies\UserEditPolicy;
use App\Queries\ProjectQuery;
use App\Queries\UserQuery;
use App\Services\AttendanceScopeService;
use App\Services\BankStatementImportService;
use App\Services\BudgetService;
use App\Services\EntityAttachmentService;
use App\Services\FinanceAccountService;
use App\Services\FinanceCsvExportService;
use App\Services\FinanceJournalService;
use App\Services\FinanceReportPdfService;
use App\Services\HtmlSanitizer;
use App\Services\MailCredentialCryptoService;
use App\Services\MailQueueAdminService;
use App\Services\MailQueueService;
use App\Services\NameFormatterService;
use App\Services\PasswordPolicyService;
use App\Services\Pdf\TcLibPdfCanvas;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionMethod;
use ReflectionParameter;

/**
 * Baut Controller für Tests: Abhängigkeiten beim Namen, der Rest aus Vorgaben.
 *
 * Bis November 2026 bauten 97 Testdateien zwölf Controller mit festen
 * Positionsargumenten. Die Parameterreihenfolge war damit eine Schnittstelle:
 * Eine neue Abhängigkeit musste optional sein und hinten stehen, bekam einen
 * Rückfall `?? new Foo()` im Konstruktor und - weil PHP-DI optionale Parameter
 * nicht füllt - einen Handeintrag in Dependencies.php.
 *
 * Die Schlüssel von `$with` sind die Parameternamen des Konstruktors. Eine
 * Vorgabe gibt es nur für Pflichtparameter und nie für `view`. Bekommt ein
 * Konstruktor einen neuen Pflichtparameter, wird hier eine Zeile ergänzt -
 * nicht jede Testdatei.
 */
trait BuildsControllers
{
    /** @param array<string, mixed> $with */
    protected function eventController(array $with = []): EventController
    {
        return $this->buildController(EventController::class, $with, [
            'nameFormatter' => static fn (): NameFormatterService => new NameFormatterService(),
            'logger' => static fn (): LoggerInterface => new NullLogger(),
            'projectQuery' => static fn (): ProjectQuery => new ProjectQuery(new NameFormatterService()),
        ]);
    }

    /** @param array<string, mixed> $with */
    protected function sponsorController(array $with = []): SponsorController
    {
        return $this->buildController(SponsorController::class, $with, [
            'policy' => static fn (): SponsoringPolicy => new SponsoringPolicy($_SESSION),
            'attachments' => static fn (): EntityAttachmentService => new EntityAttachmentService(new NullLogger()),
        ]);
    }

    /** @param array<string, mixed> $with */
    protected function registrationController(array $with = []): RegistrationController
    {
        return $this->buildController(RegistrationController::class, $with, [
            'scopeService' => static fn (): AttendanceScopeService => new AttendanceScopeService($_SESSION),
            'logger' => static fn (): LoggerInterface => new NullLogger(),
            'nameFormatter' => static fn (): NameFormatterService => new NameFormatterService(),
        ]);
    }

    /** @param array<string, mixed> $with */
    protected function attendanceController(array $with = []): AttendanceController
    {
        return $this->buildController(AttendanceController::class, $with, [
            'scopeService' => static fn (): AttendanceScopeService => new AttendanceScopeService($_SESSION),
            'logger' => static fn (): LoggerInterface => new NullLogger(),
            'nameFormatter' => static fn (): NameFormatterService => new NameFormatterService(),
        ]);
    }

    /** @param array<string, mixed> $with */
    protected function evaluationController(array $with = []): EvaluationController
    {
        return $this->buildController(EvaluationController::class, $with, [
            'projectQuery' => static fn (): ProjectQuery => new ProjectQuery(new NameFormatterService()),
        ]);
    }

    /** @param array<string, mixed> $with */
    protected function profileController(array $with = []): ProfileController
    {
        return $this->buildController(ProfileController::class, $with, [
            'userQuery' => static fn (): UserQuery => new UserQuery(new NameFormatterService()),
            'passwordPolicyService' => static fn (): PasswordPolicyService => new PasswordPolicyService(),
            'logger' => static fn (): LoggerInterface => new NullLogger(),
            // `final` und damit nicht doublebar: der echte Dienst, die Tests verschlüsseln nichts.
            'crypto' => static fn (): MailCredentialCryptoService => new MailCredentialCryptoService(),
        ]);
    }

    /** @param array<string, mixed> $with */
    protected function passwordResetController(array $with = []): PasswordResetController
    {
        return $this->buildController(PasswordResetController::class, $with, []);
    }

    /** @param array<string, mixed> $with */
    protected function dashboardController(array $with = []): DashboardController
    {
        return $this->buildController(DashboardController::class, $with, [
            'mailQueueAdminService' => static fn (): MailQueueAdminService => new MailQueueAdminService(),
            'taskPolicy' => static fn (): TaskPolicy => new TaskPolicy($_SESSION),
            'newsletterPolicy' => static fn (): NewsletterPolicy => new NewsletterPolicy($_SESSION),
        ]);
    }

    /** @param array<string, mixed> $with */
    protected function voiceGroupController(array $with = []): VoiceGroupController
    {
        return $this->buildController(VoiceGroupController::class, $with, []);
    }

    /** @param array<string, mixed> $with */
    protected function taskController(array $with = []): TaskController
    {
        return $this->buildController(TaskController::class, $with, [
            'htmlSanitizer' => static fn (): HtmlSanitizer => new HtmlSanitizer(),
            'policy' => static fn (): TaskPolicy => new TaskPolicy($_SESSION),
            'nameFormatter' => static fn (): NameFormatterService => new NameFormatterService(),
            'logger' => static fn (): LoggerInterface => new NullLogger(),
        ]);
    }

    /** @param array<string, mixed> $with */
    protected function financeController(array $with = []): FinanceController
    {
        return $this->buildController(FinanceController::class, $with, [
            'budgetService' => static fn (): BudgetService => new BudgetService(),
            'logger' => static fn (): LoggerInterface => new NullLogger(),
            'pdfService' => static fn (): FinanceReportPdfService => new FinanceReportPdfService(new TcLibPdfCanvas()),
            'importService' => static fn (): BankStatementImportService => new BankStatementImportService(
                new NullLogger()
            ),
            'accountService' => static fn (): FinanceAccountService => new FinanceAccountService(),
            'journal' => static fn (): FinanceJournalService => new FinanceJournalService(),
            'csvExportService' => static fn (): FinanceCsvExportService => new FinanceCsvExportService(),
        ]);
    }

    /** @param array<string, mixed> $with */
    protected function userController(array $with = []): UserController
    {
        return $this->buildController(UserController::class, $with, [
            'userQuery' => static fn (): UserQuery => new UserQuery(new NameFormatterService()),
            'userPersistence' => static fn (): UserPersistence => new UserPersistence(new NullLogger()),
            'projectPersistence' => static fn (): ProjectPersistence => new ProjectPersistence(),
            'mailQueueService' => fn (): MailQueueService => $this->createStub(MailQueueService::class),
            'logger' => static fn (): LoggerInterface => new NullLogger(),
            'userEditPolicy' => static fn (): UserEditPolicy => new UserEditPolicy($_SESSION),
        ]);
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @param array<string, mixed> $with
     * @param array<string, \Closure(): mixed> $defaults
     * @return T
     */
    private function buildController(string $class, array $with, array $defaults): object
    {
        $known = array_map(
            static fn (ReflectionParameter $parameter): string => $parameter->getName(),
            (new ReflectionMethod($class, '__construct'))->getParameters()
        );

        $unknown = array_diff(array_keys($with), $known);
        if ($unknown !== []) {
            throw new InvalidArgumentException(sprintf(
                '%s kennt keinen Konstruktor-Parameter %s.',
                $class,
                implode(', ', $unknown)
            ));
        }

        $arguments = $with;
        foreach ($defaults as $name => $default) {
            if (!array_key_exists($name, $arguments)) {
                $arguments[$name] = $default();
            }
        }

        return new $class(...$arguments);
    }
}
