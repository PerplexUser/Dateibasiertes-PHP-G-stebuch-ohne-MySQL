<?php
// guestbook.php - www.perplex.click
// PHP >= 7.4 empfohlen
declare(strict_types=1);
session_start();
header('Content-Type: text/html; charset=utf-8');

// --------------------------------------------------
// Konfiguration
// --------------------------------------------------
$dataDir  = __DIR__ . '/guestbook_data';     // Wird bei Bedarf mit 0777 angelegt
$dataFile = $dataDir . '/entries.jsonl';     // Eine JSON-Zeile pro Eintrag
$rateDir  = $dataDir . '/rate';              // Rate-Limit-Dateien

$maxNameLen   = 50;      // max. Zeichen im Namen
$maxMsgLen    = 2000;    // max. Zeichen in der Nachricht
$showLimit    = 200;     // Anzahl anzuzeigender Einträge (neueste zuerst)
$cooldownSecs = 30;      // Wartezeit zwischen zwei Posts pro IP (Sekunden)

date_default_timezone_set('Europe/Berlin');  // bei Bedarf anpassen

// --------------------------------------------------
// Datenverzeichnisse anlegen (mit 0777, falls gefordert)
// --------------------------------------------------
$oldUmask = umask(0); // damit mkdir 0777 wirklich setzt
if (!is_dir($dataDir)) { @mkdir($dataDir, 0777, true); }
if (!is_dir($rateDir)) { @mkdir($rateDir, 0777, true); }
umask($oldUmask);

// Sicherheitshalber nochmal setzen (falls bereits vorhanden)
@chmod($dataDir, 0777);
@chmod($rateDir, 0777);

// Datei initialisieren (0666 -> weltbeschreibbar, sofern vom System erlaubt)
if (!file_exists($dataFile)) {
    @touch($dataFile);
    @chmod($dataFile, 0666);
}

// --------------------------------------------------
// Hilfsfunktionen
// --------------------------------------------------
function e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function u8len(string $s): int {
    return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
}
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}
function csrf_valid(?string $t): bool {
    return is_string($t) && isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $t);
}
function client_ip(): string {
    // Einfach gehalten; bei Proxies/X-Forwarded-For mit Bedacht erweitern
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}
function now(): int { return time(); }

function store_entry(array $entry, string $file): bool {
    $fp = @fopen($file, 'ab');
    if (!$fp) return false;
    if (!flock($fp, LOCK_EX)) { fclose($fp); return false; }

    $line = json_encode($entry, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    $ok   = fwrite($fp, $line) !== false;
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return $ok;
}

function read_entries(string $file, int $limit): array {
    $entries = [];
    $fp = @fopen($file, 'rb');
    if ($fp) {
        if (flock($fp, LOCK_SH)) {
            while (($line = fgets($fp)) !== false) {
                $line = trim($line);
                if ($line === '') continue;
                $row = json_decode($line, true);
                if (is_array($row)) $entries[] = $row;
            }
            flock($fp, LOCK_UN);
        }
        fclose($fp);
    }
    // Neueste zuerst
    return array_slice(array_reverse($entries), 0, $limit);
}

// --------------------------------------------------
// Form-Handling (POST)
// --------------------------------------------------
$errors = [];
$flash  = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name    = trim((string)($_POST['name']    ?? ''));
    $message = trim((string)($_POST['message'] ?? ''));
    $token   = $_POST['csrf'] ?? '';
    $hp      = trim((string)($_POST['hp_web']  ?? '')); // Honigtopf: soll leer bleiben

    // Ggf. Rate-Limit ermitteln
    $ip         = client_ip();
    $ipKey      = hash('sha256', $ip);
    $rateFile   = $rateDir . '/' . $ipKey . '.lock';
    $tooFastMsg = null;

    if (file_exists($rateFile)) {
        $elapsed = now() - (int)filemtime($rateFile);
        if ($elapsed < $cooldownSecs) {
            $tooFastMsg = 'Bitte warte ' . ($cooldownSecs - $elapsed) . ' Sekunde(n) vor dem nächsten Eintrag.';
        }
    }

    // Validierungen
    if ($hp !== '') {
        $errors[] = 'Spamverdacht – Formular ungültig.';
    }
    if (!csrf_valid($token)) {
        $errors[] = 'Sicherheits-Token ungültig. Bitte Formular erneut senden.';
    }
    if ($name === '' || u8len($name) > $maxNameLen) {
        $errors[] = 'Bitte einen Namen angeben (max. ' . $maxNameLen . ' Zeichen).';
    }
    if ($message === '' || u8len($message) > $maxMsgLen) {
        $errors[] = 'Bitte eine Nachricht eingeben (max. ' . $maxMsgLen . ' Zeichen).';
    }
    if ($tooFastMsg) {
        $errors[] = $tooFastMsg;
    }

    if (!$errors) {
        $entry = [
            'id'       => bin2hex(random_bytes(8)),
            'ts'       => now(),
            'name'     => $name,          // Roh speichern, beim Ausgeben escapen
            'message'  => $message,       // Roh speichern, beim Ausgeben escapen
            'ip_hash'  => substr(hash('sha256', $ip . '|' . ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 16),
        ];

        if (store_entry($entry, $dataFile)) {
            // Rate-Limit „stempeln“
            @touch($rateFile);
            @chmod($rateFile, 0666);

            $_SESSION['flash'] = 'Danke! Dein Eintrag wurde gespeichert.';
            // PRG-Pattern: Redirect, um Doppelposts bei Reload zu vermeiden
            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
            exit;
        } else {
            $errors[] = 'Speichern fehlgeschlagen. Schreibrechte für ' . e($dataDir) . ' prüfen.';
        }
    }
}

// Einträge laden (für GET und nach POST-Redirect)
$entries = read_entries($dataFile, $showLimit);

// --------------------------------------------------
// Ausgabe (HTML)
// --------------------------------------------------
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Gästebuch</title>
<style>
  :root { --w: 760px; }
  * { box-sizing: border-box; }
  body {
    font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
    margin: 0; padding: 2rem 1rem; line-height: 1.4;
    background: #f6f7f9;
  }
  .wrap { max-width: var(--w); margin: 0 auto; }
  h1 { margin: 0 0 1rem 0; font-size: 1.6rem; }
  .card {
    background: #fff; border: 1px solid #e5e7eb; border-radius: 8px;
    padding: 1rem; margin-bottom: 1rem; box-shadow: 0 1px 0 rgba(0,0,0,.04);
  }
  form .row { display: grid; gap: .75rem; margin-bottom: .75rem; }
  input[type="text"], textarea {
    width: 100%; padding: .6rem .7rem; border: 1px solid #d1d5db; border-radius: 6px;
    font: inherit; background: #fff;
  }
  textarea { min-height: 140px; resize: vertical; }
  .actions { display: flex; align-items: center; gap: .75rem; }
  button {
    appearance: none; border: 1px solid #111827; background: #111827; color: #fff;
    padding: .6rem 1rem; border-radius: 6px; cursor: pointer;
  }
  .muted { color: #6b7280; font-size: .9rem; }
  .error { background: #FEF2F2; border-color: #FECACA; }
  .success { background: #ECFDF5; border-color: #A7F3D0; }
  .hp { position: absolute; left: -99999px; width: 1px; height: 1px; overflow: hidden; }
  .entry { padding: .8rem 0; border-top: 1px solid #f0f2f5; }
  .entry:first-child { border-top: 0; }
  .entry h3 { margin: 0 0 .25rem 0; font-size: 1rem; }
  .entry .time { font-size: .85rem; color: #6b7280; }
  .counter { margin-left: auto; font-size: .85rem; color: #6b7280; }
</style>
</head>
<body>
<div class="wrap">
  <h1>Gästebuch</h1>

  <?php if ($flash): ?>
    <div class="card success"><?= e($flash) ?></div>
  <?php endif; ?>

  <?php if ($errors): ?>
    <div class="card error">
      <strong>Bitte korrigiere Folgendes:</strong>
      <ul>
        <?php foreach ($errors as $err): ?>
          <li><?= e($err) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <div class="card">
    <form method="post" autocomplete="off" novalidate>
      <div class="row">
        <label>
          Name<br>
          <input type="text" name="name" maxlength="<?= (int)$maxNameLen ?>" required
                 value="<?= isset($name) ? e($name) : '' ?>">
        </label>
      </div>
      <div class="row">
        <label>
          Nachricht<br>
          <textarea name="message" maxlength="<?= (int)$maxMsgLen ?>" required
                    oninput="updateCounter(this)"><?= isset($message) ? e($message) : '' ?></textarea>
        </label>
      </div>

      <!-- Honigtopf gegen Bots -->
      <div class="hp">
        <label>Website <input type="text" name="hp_web" tabindex="-1"></label>
      </div>

      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

      <div class="actions">
        <button type="submit">Eintragen</button>
        <span class="counter" id="counter">0 / <?= (int)$maxMsgLen ?></span>
      </div>
      <p class="muted">Max. <?= (int)$maxMsgLen ?> Zeichen. Zwischen zwei Einträgen sind <?= (int)$cooldownSecs ?> Sekunden Pause nötig.</p>
    </form>
  </div>

  <div class="card">
    <h2 style="margin:0 0 .5rem 0; font-size:1.2rem;">Einträge</h2>
    <?php if (!$entries): ?>
      <p class="muted">Noch keine Einträge.</p>
    <?php else: ?>
      <?php foreach ($entries as $e): ?>
        <div class="entry">
          <h3><?= e($e['name'] ?? 'Anonym') ?></h3>
          <div class="time"><?= date('d.m.Y H:i', (int)($e['ts'] ?? 0)) ?></div>
          <div class="msg"><?= nl2br(e($e['message'] ?? '')) ?></div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <p class="muted">Hinweis: Beiträge werden im Verzeichnis <code><?= e($dataDir) ?></code> als JSON‑Zeilen gespeichert.</p>
</div>

<script>
  // Zeichen-Zähler für das Textfeld
  function updateCounter(el) {
    var max = parseInt(el.getAttribute('maxlength') || '0', 10);
    var len = el.value.length;
    var c = document.getElementById('counter');
    if (c) c.textContent = len + ' / ' + max;
  }
  (function init() {
    var ta = document.querySelector('textarea[name="message"]');
    if (ta) updateCounter(ta);
  })();
</script>
</body>
</html>
