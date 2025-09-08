# Dateibasiertes-PHP-G-stebuch-ohne-MySQL
Hier ist ein kleines, robustes Gästebuch als einzelne PHP‑Datei, das Einträge in Textdateien (JSON‑Zeilenformat) in einem Verzeichnis mit CHMOD 777 speichert. Es nutzt Dateisperren (flock), CSRF‑Schutz, ein Honigtopf‑Feld gegen Spam und ein simples Rate‑Limit.

Sicherheit: Auch wenn das Verzeichnis 777 hat, schütze es per .htaccess (oben) und veröffentliche keine direkten Links zu den Dateien. Noch besser: Lege guestbook_data/ oberhalb des Webroots ab.
Moderation: Du kannst optional ein Flag approved in jedem Eintrag speichern und nur freigegebene Einträge anzeigen.
Backup/Export: Das JSON‑Zeilenformat (entries.jsonl) lässt sich leicht sichern oder in andere Systeme importieren.
Leistung: Für sehr große Dateien könntest du beim Lesen einen Cursor von hinten nutzen (oder pro Eintrag eine Datei ablegen). Für ein übliches Gästebuch reicht die obige Variante aber aus.
