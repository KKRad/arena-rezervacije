<?php
// Pozadinski poslovi, svakih 5 minuta. Dva načina (isti rezultat):
//   CLI:      php /putanja/cron.php all
//   web-cron: wget -q -O /dev/null https://<domena>/rezervacije/api/cron   (kad CLI php na hostingu ne radi)
// Backup jednom dnevno poslije 03:00 je dio istog prolaza.
require __DIR__ . '/app/bootstrap.php';
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
echo json_encode(Jobs::run('cli'), JSON_UNESCAPED_UNICODE) . "\n";
