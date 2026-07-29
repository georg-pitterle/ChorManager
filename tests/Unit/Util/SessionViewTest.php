<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use App\Util\SessionView;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * The Twig `session` global must observe $_SESSION live. A by-value array copy
 * taken at Twig construction time misses every session write that happens
 * afterwards in the same request (e.g. the remember-me restore in
 * AuthMiddleware), which silently dropped the whole navbar from the layout.
 */
final class SessionViewTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $originalSession = [];

    protected function setUp(): void
    {
        $this->originalSession = $_SESSION ?? [];
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->originalSession;
    }

    public function testReadsValuesWrittenAfterConstruction(): void
    {
        $view = new SessionView();

        $this->assertFalse(isset($view['user_id']));
        $this->assertNull($view['user_id']);

        $_SESSION['user_id'] = 42;

        $this->assertTrue(isset($view['user_id']));
        $this->assertSame(42, $view['user_id']);
    }

    public function testReflectsRemovedValues(): void
    {
        $_SESSION['user_id'] = 7;
        $view = new SessionView();

        unset($_SESSION['user_id']);

        $this->assertFalse(isset($view['user_id']));
        $this->assertNull($view['user_id']);
    }

    public function testMissingKeyReturnsNullInsteadOfFailing(): void
    {
        $view = new SessionView();

        $this->assertNull($view['does_not_exist']);
    }

    public function testIsIterableAndCountableForTemplateUse(): void
    {
        $_SESSION = ['user_id' => 1, 'can_manage_users' => true];
        $view = new SessionView();

        $this->assertCount(2, $view);
        $this->assertSame(
            ['user_id' => 1, 'can_manage_users' => true],
            iterator_to_array($view)
        );
    }

    public function testWritesAreRejected(): void
    {
        $view = new SessionView();

        $this->expectException(LogicException::class);
        $view['user_id'] = 1;
    }

    public function testUnsetIsRejected(): void
    {
        $_SESSION['user_id'] = 1;
        $view = new SessionView();

        $this->expectException(LogicException::class);
        unset($view['user_id']);
    }
}
