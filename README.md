# Dateibasiertes-PHP-G-stebuch-ohne-MySQL
Hier ist ein kleines, robustes Gästebuch als einzelne PHP‑Datei, das Einträge in Textdateien (JSON‑Zeilenformat) in einem Verzeichnis mit CHMOD 777 speichert. Es nutzt Dateisperren (flock), CSRF‑Schutz, ein Honigtopf‑Feld gegen Spam und ein simples Rate‑Limit.
