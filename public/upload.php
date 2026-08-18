<?php
/**
 * Foto-Einreichung für den Stamm Hubertus Siegen e.V.
 *
 * Nimmt Fotos vom Formular /fotos-einreichen entgegen und legt sie in
 * fotoeingang/<datum>_<veranstaltung>/ ab. Der Ordner ist per .htaccess
 * komplett gesperrt – Abholung durch den Verein per FTP/SFTP.
 */

declare(strict_types=1);

const MAX_DATEIEN = 30;
const MAX_GROESSE = 20 * 1024 * 1024; // 20 MB
const ERLAUBT = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
    'image/heic' => 'heic',
    'image/heif' => 'heif',
];

function zurueck(string $fehler): never
{
    header('Location: /fotos-einreichen/?fehler=' . rawurlencode($fehler), true, 303);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: /fotos-einreichen/', true, 303);
    exit;
}

// Honeypot: Bots füllen das versteckte Feld – dann still "Erfolg" melden
if (!empty($_POST['webseite'])) {
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
$anzahl = count($dateien['name']);
if ($anzahl > MAX_DATEIEN) {
    zurueck('zu-viele');
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
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
    $mime = $finfo->file($dateien['tmp_name'][$i]) ?: '';
    if (!isset(ERLAUBT[$mime])) {
        zurueck('kein-bild');
    }
    $geprueft[] = ['tmp' => $dateien['tmp_name'][$i], 'endung' => ERLAUBT[$mime]];
}

$slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $veranstaltung) ?? 'einreichung');
$slug = trim(substr($slug, 0, 40), '-') ?: 'einreichung';
$ordner = __DIR__ . '/fotoeingang/' . date('Y-m-d') . '_' . $slug . '_' . substr(bin2hex(random_bytes(4)), 0, 6);

if (!is_dir(__DIR__ . '/fotoeingang') && !mkdir(__DIR__ . '/fotoeingang', 0755)) {
    zurueck('technik');
}
if (!mkdir($ordner, 0755)) {
    zurueck('technik');
}

foreach ($geprueft as $i => $datei) {
    $ziel = sprintf('%s/foto-%02d.%s', $ordner, $i + 1, $datei['endung']);
    if (!move_uploaded_file($datei['tmp'], $ziel)) {
        zurueck('technik');
    }
}

file_put_contents($ordner . '/einreichung.txt', implode("\n", [
    'Eingereicht:   ' . date('d.m.Y H:i'),
    'Name:          ' . $name,
    'E-Mail:        ' . $email,
    'Veranstaltung: ' . $veranstaltung,
    'Fotos:         ' . count($geprueft),
    'Nachricht:',
    $nachricht,
]) . "\n");

header('Location: /fotos-einreichen/danke/', true, 303);
exit;
