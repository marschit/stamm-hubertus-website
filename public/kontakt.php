<?php
/**
 * Kontaktformular des Stamm Hubertus Siegen e.V.
 * Versendet die Nachricht per E-Mail an die Vereinsadresse.
 * Es wird nichts auf dem Server gespeichert (außer Spamschutz-Tageszähler).
 */

declare(strict_types=1);

const EMPFAENGER = 'stamm-hubertus-bdp@outlook.de';
const ABSENDER   = 'website@stamm-hubertus-siegen.de';
const MAX_PRO_TAG = 5; // Nachrichten pro IP und Tag

$schutzdir = __DIR__ . '/kontaktschutz';

function zurueck(string $status): never
{
    header('Location: /kontakt/?' . $status . '#kontaktformular', true, 303);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: /kontakt/', true, 303);
    exit;
}

// Honeypot: Bots still ins Leere laufen lassen
if (!empty($_POST['webseite'])) {
    zurueck('gesendet=1');
}

$name      = trim((string)($_POST['name'] ?? ''));
$email     = trim((string)($_POST['email'] ?? ''));
$betreff   = trim((string)($_POST['betreff'] ?? ''));
$nachricht = trim((string)($_POST['nachricht'] ?? ''));

if ($name === '' || $nachricht === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    zurueck('fehler=unvollstaendig');
}
if (mb_strlen($name) > 100 || mb_strlen($betreff) > 150 || mb_strlen($nachricht) > 5000) {
    zurueck('fehler=zu-lang');
}

// Tageslimit pro IP
if (!is_dir($schutzdir)) {
    mkdir($schutzdir, 0755);
    file_put_contents($schutzdir . '/.htaccess', "Require all denied\n");
}
$zaehlerdatei = $schutzdir . '/' . hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '?') . '-' . date('Y-m-d');
$anzahl = is_file($zaehlerdatei) ? (int)file_get_contents($zaehlerdatei) : 0;
if ($anzahl >= MAX_PRO_TAG) {
    zurueck('fehler=rate-limit');
}
file_put_contents($zaehlerdatei, (string)($anzahl + 1));
foreach (glob($schutzdir . '/*-*') ?: [] as $alt) {
    if (filemtime($alt) < time() - 2 * 86400) {
        @unlink($alt);
    }
}

$betreffZeile = 'Kontaktformular: ' . ($betreff !== '' ? $betreff : $name);
$body = "Neue Nachricht über das Kontaktformular der Website\n"
      . str_repeat('-', 50) . "\n"
      . "Name:    $name\n"
      . "E-Mail:  $email\n"
      . ($betreff !== '' ? "Betreff: $betreff\n" : '')
      . str_repeat('-', 50) . "\n\n"
      . $nachricht . "\n";

$headers = [
    'From: Website Stamm Hubertus <' . ABSENDER . '>',
    'Reply-To: ' . preg_replace('/[\r\n]/', '', $name) . ' <' . $email . '>',
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'Content-Transfer-Encoding: 8bit',
];

$ok = mail(
    EMPFAENGER,
    mb_encode_mimeheader($betreffZeile, 'UTF-8'),
    $body,
    implode("\r\n", $headers)
);

zurueck($ok ? 'gesendet=1' : 'fehler=technik');
