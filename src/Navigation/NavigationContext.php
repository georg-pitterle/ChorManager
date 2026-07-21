<?php

declare(strict_types=1);

namespace App\Navigation;

/**
 * Immutable snapshot of everything that decides nav visibility and active state.
 * Keeps $_SESSION and $settings out of the builder so it is testable in isolation.
 */
final class NavigationContext
{
    /**
     * @param array<string,bool> $permissions can_* capability flags
     * @param array<string,bool> $modules      settings.modules.* toggles
     */
    public function __construct(
        public readonly array $permissions,
        public readonly array $modules,
        public readonly string $path,
        public readonly string $navKey = ''
    ) {
    }

    /**
     * @param array<string,mixed> $session
     * @param array<string,mixed> $settings
     */
    public static function fromSession(
        array $session,
        array $settings,
        string $path,
        string $navKey = ''
    ): self {
        // Every session key prefixed "can_" is a capability flag. Copying the
        // prefix wholesale (instead of an explicit allowlist) means a new
        // `$c->can('can_x')` predicate added to NavigationBuilder can never
        // silently read as false just because this list was not updated too.
        $permissions = [];
        foreach ($session as $key => $value) {
            if (is_string($key) && str_starts_with($key, 'can_')) {
                $permissions[$key] = (bool) $value;
            }
        }

        $modules = [];
        foreach ((array) ($settings['modules'] ?? []) as $name => $enabled) {
            $modules[(string) $name] = (bool) $enabled;
        }

        return new self($permissions, $modules, $path, $navKey);
    }

    public function can(string $flag): bool
    {
        return (bool) ($this->permissions[$flag] ?? false);
    }

    public function module(string $name): bool
    {
        return (bool) ($this->modules[$name] ?? false);
    }
}
