<?php
declare(strict_types=1);

date_default_timezone_set('Europe/Sarajevo');
mb_internal_encoding('UTF-8');
foreach (['core', 'auth', 'pricing', 'availability', 'reservations', 'cash', 'clubs', 'events', 'notify', 'pdf', 'calendar'] as $f) require_once __DIR__ . '/lib/' . $f . '.php';
require_once __DIR__ . '/api.php';
