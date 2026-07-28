<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Unit\Bootstrap;

/**
 * Transaktionsklammer fuer Tests rund um Termin-Sichtbarkeit: die Tests legen
 * ihre eigenen Termine, Projekte und Nutzer an, statt sich auf vorhandene
 * Seed-Daten zu stuetzen, und machen alles danach wieder rueckgaengig.
 */
trait EventScopeFixtures
{
    protected function beginFixtureTransaction(): void
    {
        Bootstrap::getCapsule()?->connection()->beginTransaction();
    }

    protected function rollBackFixtureTransaction(): void
    {
        $connection = Bootstrap::getCapsule()?->connection();
        if ($connection !== null && $connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
    }
}
