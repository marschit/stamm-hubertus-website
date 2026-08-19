<?php
/**
 * Foto-Einreichung für den Stamm Hubertus Siegen e.V.
 *
 * Zwei Wege:
 *  - Modern (JS): a=init legt die Einreichung an und liefert ein Token,
 *    danach lädt der Browser jedes Foto einzeln per a=datei hoch.
 *    So sind auch tausende Fotos möglich, ohne PHP-Limits zu reißen.
 *  - Fallback (ohne JS): klassischer Multipart-Post mit wenigen Fotos.
 *
 * Spamschutz: Honeypot-Feld, Tageslimits pro IP, HMAC-Token pro Einreichung.
 * Ablage in fotoeingang/<datum>_<veranstaltung>_<zufall>/ – der Ordner ist
 * per .htaccess gesperrt und in Nextcloud als "Foto-Einreichungen" gemountet.
 */

declare(strict_types=1);

const MELDUNG_AN = 'kontakt@stamm-hubertus-siegen.de'; // Benachrichtigung bei neuen Einreichungen
const MELDUNG_VON = 'website@stamm-hubertus-siegen.de';

const MAX_DATEIEN_EINREICHUNG = 2000;      // Fotos pro Einreichung
const MAX_GROESSE = 20 * 1024 * 1024;      // 20 MB pro Foto
const MAX_INITS_PRO_TAG = 10;              // Einreichungen pro IP und Tag
const MAX_DATEIEN_PRO_TAG = 4000;          // Fotos pro IP und Tag
const ERLAUBT = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
    'image/heic' => 'heic',
    'image/heif' => 'heif',
];

$basis = __DIR__ . '/fotoeingang';

function json_antwort(int $code, array $daten): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($daten);
    exit;
}

function zurueck(string $fehler): never
{
    header('Location: /fotos-einreichen/?fehler=' . rawurlencode($fehler), true, 303);
    exit;
}

function basis_sichern(string $basis): void
{
    if (!is_dir($basis)) {
        mkdir($basis, 0755);
    }
    if (!is_file($basis . '/.htaccess')) {
        file_put_contents($basis . '/.htaccess', "Require all denied\n");
    }
}

function geheimnis(string $basis): string
{
    $datei = $basis . '/.geheimnis';
    if (!is_file($datei)) {
        file_put_contents($datei, bin2hex(random_bytes(32)));
        @chmod($datei, 0600);
    }
    return trim((string)file_get_contents($datei));
}

/** Tageszähler pro IP: ['inits' => n, 'dateien' => n] */
function schutz_lade(string $basis): array
{
    $datei = schutz_datei($basis);
    $stand = is_file($datei) ? json_decode((string)file_get_contents($datei), true) : null;
    return is_array($stand) ? $stand : ['inits' => 0, 'dateien' => 0];
}

function schutz_speichere(string $basis, array $stand): void
{
    $dir = $basis . '/.schutz';
    if (!is_dir($dir)) {
        mkdir($dir, 0755);
    }
    // Alte Tageszähler gelegentlich wegräumen
    foreach (glob($dir . '/*.json') ?: [] as $alt) {
        if (filemtime($alt) < time() - 2 * 86400) {
            @unlink($alt);
        }
    }
    file_put_contents(schutz_datei($basis), json_encode($stand));
}

function schutz_datei(string $basis): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unbekannt';
    return $basis . '/.schutz/' . hash('sha256', $ip) . '-' . date('Y-m-d') . '.json';
}

/** Liefert die Dateiendung, wenn die Datei ein erlaubtes Bild ist, sonst null */
function bild_endung(string $tmp): ?string
{
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: '';
    return ERLAUBT[$mime] ?? null;
}

function ordner_anlegen(string $basis, string $veranstaltung): string
{
    $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $veranstaltung) ?? 'einreichung');
    $slug = trim(substr($slug, 0, 40), '-') ?: 'einreichung';
    $ordner = date('Y-m-d') . '_' . $slug . '_' . substr(bin2hex(random_bytes(4)), 0, 6);
    if (!mkdir($basis . '/' . $ordner, 0755)) {
        json_antwort(500, ['fehler' => 'technik']);
    }
    return $ordner;
}

function meldung_senden(string $ordner, int $anzahl): void
{
    $info = @file_get_contents($ordner . '/einreichung.txt') ?: '';
    $body = "Neue Foto-Einreichung über die Website\n"
          . str_repeat('-', 50) . "\n"
          . $info
          . "Fotos angekommen: $anzahl\n"
          . "Ordner: " . basename($ordner) . "\n\n"
          . "Die Bilder liegen in der Nextcloud unter „Foto-Einreichungen“:\n"
          . "https://cloud.stamm-hubertus-siegen.de\n";
    @mail(
        MELDUNG_AN,
        mb_encode_mimeheader("Foto-Einreichung: $anzahl Fotos (" . basename($ordner) . ')', 'UTF-8'),
        $body,
        implode("\r\n", [
            'From: Website Stamm Hubertus <' . MELDUNG_VON . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
        ])
    );
}

function einreichung_notieren(string $pfad, string $name, string $email, string $veranstaltung, string $nachricht): void
{
    file_put_contents($pfad . '/einreichung.txt', implode("\n", [
        'Eingereicht:   ' . date('d.m.Y H:i'),
        'Name:          ' . $name,
        'E-Mail:        ' . $email,
        'Veranstaltung: ' . $veranstaltung,
        'Nachricht:',
        $nachricht,
    ]) . "\n");
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: /fotos-einreichen/', true, 303);
    exit;
}

basis_sichern($basis);
$aktion = $_POST['a'] ?? '';

/* ---------- Schritt 1 (JS): Einreichung anlegen ---------- */
if ($aktion === 'init') {
    if (!empty($_POST['webseite'])) {                       // Honeypot
        json_antwort(200, ['ordner' => 'x', 'token' => 'x']); // Bots ins Leere laufen lassen
    }
    $name          = trim((string)($_POST['name'] ?? ''));
    $email         = trim((string)($_POST['email'] ?? ''));
    $veranstaltung = trim((string)($_POST['veranstaltung'] ?? ''));
    $nachricht     = trim((string)($_POST['nachricht'] ?? ''));
    if ($name === '' || $veranstaltung === '' || ($_POST['einverstaendnis'] ?? '') !== 'ja') {
        json_antwort(422, ['fehler' => 'unvollstaendig']);
    }
    $stand = schutz_lade($basis);
    if ($stand['inits'] >= MAX_INITS_PRO_TAG) {
        json_antwort(429, ['fehler' => 'rate-limit']);
    }
    $stand['inits']++;
    schutz_speichere($basis, $stand);

    $ordner = ordner_anlegen($basis, $veranstaltung);
    einreichung_notieren($basis . '/' . $ordner, $name, $email, $veranstaltung, $nachricht);
    json_antwort(200, [
        'ordner' => $ordner,
        'token'  => hash_hmac('sha256', $ordner, geheimnis($basis)),
    ]);
}

/* ---------- Schritt 2 (JS): einzelnes Foto ---------- */
if ($aktion === 'datei') {
    $ordner = basename((string)($_POST['ordner'] ?? ''));
    $token  = (string)($_POST['token'] ?? '');
    $index  = max(1, min(MAX_DATEIEN_EINREICHUNG, (int)($_POST['index'] ?? 0)));
    $ziel   = $basis . '/' . $ordner;

    if ($ordner === '' || !is_dir($ziel)
        || !hash_equals(hash_hmac('sha256', $ordner, geheimnis($basis)), $token)) {
        json_antwort(403, ['fehler' => 'token']);
    }
    $stand = schutz_lade($basis);
    if ($stand['dateien'] >= MAX_DATEIEN_PRO_TAG) {
        json_antwort(429, ['fehler' => 'rate-limit']);
    }
    $datei = $_FILES['foto'] ?? null;
    if (!$datei || $datei['error'] !== UPLOAD_ERR_OK) {
        json_antwort(422, ['fehler' => 'technik']);
    }
    if ($datei['size'] > MAX_GROESSE) {
        json_antwort(413, ['fehler' => 'zu-gross']);
    }
    $endung = bild_endung($datei['tmp_name']);
    if ($endung === null) {
        json_antwort(422, ['fehler' => 'kein-bild']);
    }
    if (!move_uploaded_file($datei['tmp_name'], sprintf('%s/foto-%04d.%s', $ziel, $index, $endung))) {
        json_antwort(500, ['fehler' => 'technik']);
    }
    $stand['dateien']++;
    schutz_speichere($basis, $stand);
    json_antwort(200, ['ok' => true]);
}

/* ---------- Schritt 3 (JS): Einreichung abschließen ---------- */
if ($aktion === 'fertig') {
    $ordner = basename((string)($_POST['ordner'] ?? ''));
    $token  = (string)($_POST['token'] ?? '');
    $ziel   = $basis . '/' . $ordner;
    if ($ordner === '' || !is_dir($ziel)
        || !hash_equals(hash_hmac('sha256', $ordner, geheimnis($basis)), $token)) {
        json_antwort(403, ['fehler' => 'token']);
    }
    if (is_file($ziel . '/.gemeldet')) {          // Doppelmeldungen vermeiden
        json_antwort(200, ['ok' => true]);
    }
    $anzahl = count(glob($ziel . '/foto-*') ?: []);
    if ($anzahl > 0) {
        file_put_contents($ziel . '/.gemeldet', '1');
        meldung_senden($ziel, $anzahl);
    }
    json_antwort(200, ['ok' => true]);
}

/* ---------- Fallback ohne JavaScript: klassischer Multipart-Post ---------- */

if (!empty($_POST['webseite'])) {                            // Honeypot
    header('Location: /fotos-einreichen/danke/', true, 303);
    exit;
}
$name          = trim((string)($_POST['name'] ?? ''));
$email         = trim((string)($_POST['email'] ?? ''));
$veranstaltung = trim((string)($_POST['veranstaltung'] ?? ''));
$nachricht     = trim((string)($_POST['nachricht'] ?? ''));
if ($name === '' || $veranstaltung === '' || ($_POST['einverstaendnis'] ?? '') !== 'ja') {
    zurueck('unvollstaendig');
}
$dateien = $_FILES['fotos'] ?? null;
if (!$dateien || !is_array($dateien['name']) || count(array_filter($dateien['name'])) === 0) {
    zurueck('keine-dateien');
}
$stand = schutz_lade($basis);
$anzahl = count($dateien['name']);
if ($stand['inits'] >= MAX_INITS_PRO_TAG || $stand['dateien'] + $anzahl > MAX_DATEIEN_PRO_TAG) {
    zurueck('rate-limit');
}

$geprueft = [];
for ($i = 0; $i < $anzahl; $i++) {
    if ($dateien['error'][$i] === UPLOAD_ERR_INI_SIZE || $dateien['error'][$i] === UPLOAD_ERR_FORM_SIZE) {
        zurueck('zu-gross');
    }
    if ($dateien['error'][$i] !== UPLOAD_ERR_OK) {
        zurueck('technik');
    }
    if ($dateien['size'][$i] > MAX_GROESSE) {
        zurueck('zu-gross');
    }
    $endung = bild_endung($dateien['tmp_name'][$i]);
    if ($endung === null) {
        zurueck('kein-bild');
    }
    $geprueft[] = ['tmp' => $dateien['tmp_name'][$i], 'endung' => $endung];
}

$stand['inits']++;
$stand['dateien'] += count($geprueft);
schutz_speichere($basis, $stand);

$ordner = ordner_anlegen($basis, $veranstaltung);
foreach ($geprueft as $i => $datei) {
    if (!move_uploaded_file($datei['tmp'], sprintf('%s/%s/foto-%04d.%s', $basis, $ordner, $i + 1, $datei['endung']))) {
        zurueck('technik');
    }
}
einreichung_notieren($basis . '/' . $ordner, $name, $email, $veranstaltung, $nachricht);
meldung_senden($basis . '/' . $ordner, count($geprueft));
header('Location: /fotos-einreichen/danke/', true, 303);
exit;
