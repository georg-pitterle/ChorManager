<?php

declare(strict_types=1);

use App\Util\Timezone;

require_once __DIR__ . '/../vendor/autoload.php';

// Dieselbe Zeitzone wie im Web-Einstieg (public/index.php). Ohne das laufen CLI-Skripte in der
// Zeitzone aus der php.ini (UTC), waehrend ueber die Oberflaeche geschriebene Zeitstempel in der
// App-Zeitzone stehen. Der Mail-Worker verglich dadurch faellige Eintraege gegen eine um den
// Zonenversatz zurueckliegende Uhrzeit und liess frisch eingereihte Mails bis zu zwei Stunden
// liegen.
date_default_timezone_set(Timezone::resolveAppTimezone());
