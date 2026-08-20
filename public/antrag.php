<?php
/**
 * Anträge rund ums Schließsystem (Zugang, Karte, Löschung).
 * Ablage in antraege/ (gesperrt, in Nextcloud gemountet) + Mail an den Stamm.
 */

declare(strict_types=1);

const EMPFAENGER = 'kontakt@stamm-hubertus-siegen.de';
const ABSENDER   = 'website@stamm-hubertus-siegen.de';
const MAX_PRO_TAG = 5;

const ARTEN = [
    'zugang'   => 'Neuer Zugang (Smartphone-App)',
    'karte'    => 'Schlüsselkarte / Chip',
    'loeschung' => 'Zugang löschen / Karte zurückgeben',
];

$basis = __DIR__ . '/antraege';

function zurueck(string $status): never
{
    header('Location: /zugangsantrag/?' . $status . '#antrag', true, 303);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: /zugangsantrag/', true, 303);
    exit;
}

// Honeypot + JS-Interaktions-Token (wie beim Kontaktformular)
if (!empty($_POST['webseite'])) {
    zurueck('gesendet=1');
}
if (($_POST['pruef'] ?? '') !== 'gut-pfad-1907') {
    zurueck('gesendet=1');
}

$art        = (string)($_POST['art'] ?? '');
$name       = trim((string)($_POST['name'] ?? ''));
$email      = trim((string)($_POST['email'] ?? ''));
$telefon    = trim((string)($_POST['telefon'] ?? ''));
$betroffene = trim((string)($_POST['betroffene'] ?? ''));
$bemerkung  = trim((string)($_POST['bemerkung'] ?? ''));

if (!isset(ARTEN[$art]) || $name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    zurueck('fehler=unvollstaendig');
}
if (mb_strlen($name) > 100 || mb_strlen($betroffene) > 150 || mb_strlen($bemerkung) > 2000) {
    zurueck('fehler=zu-lang');
}

// Tageslimit pro IP
if (!is_dir($basis)) {
    mkdir($basis, 0755);
}
if (!is_file($basis . '/.htaccess')) {
    file_put_contents($basis . '/.htaccess', "Require all denied\n");
}
$schutzdir = $basis . '/.schutz';
if (!is_dir($schutzdir)) {
    mkdir($schutzdir, 0755);
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

$inhalt = implode("\n", [
    'Antrag Schließsystem – ' . ARTEN[$art],
    str_repeat('=', 55),
    'Eingegangen:   ' . date('d.m.Y H:i'),
    'Antragsart:    ' . ARTEN[$art],
    'Name:          ' . $name,
    'E-Mail:        ' . $email,
    'Telefon:       ' . $telefon,
    'Betrifft:      ' . ($betroffene !== '' ? $betroffene : $name),
    '',
    'Bemerkung:',
    $bemerkung,
    '',
    'Bearbeitung: im KleverKey-Portal (portal.kleverkey.com) umsetzen,',
    'danach diese Datei in "erledigt" verschieben oder löschen.',
]) . "\n";

$slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $name) ?? 'antrag');
$slug = trim(substr($slug, 0, 30), '-') ?: 'antrag';
$datei = sprintf('%s/%s_%s_%s_%s.txt', $basis, date('Y-m-d_Hi'), $art, $slug, substr(bin2hex(random_bytes(3)), 0, 4));
file_put_contents($datei, $inhalt);

@mail(
    EMPFAENGER,
    mb_encode_mimeheader('Schließsystem-Antrag: ' . ARTEN[$art] . " ($name)", 'UTF-8'),
    $inhalt . "\nDer Antrag liegt auch in der Nextcloud unter Verein/Schließsystem/Anträge.\n",
    implode("\r\n", [
        'From: Website Stamm Hubertus <' . ABSENDER . '>',
        'Reply-To: ' . preg_replace('/[\r\n]/', '', $name) . ' <' . $email . '>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
    ])
);

zurueck('gesendet=1');
