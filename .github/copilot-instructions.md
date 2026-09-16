# Copilot Instructions for InternautenB2BOffer

Diese Anweisungen gelten fuer das gesamte Repository.

## Projektkontext

- Dies ist ein Prestashop-Modul mit dem Modulordner `InternautenB2BOffer`.
- Zielplattformen:
  - Prestashop >= 9.1.4
  - PHP >= 8.3.31
- Das Repo dient auch als Muster fuer AI-gestuetzte Entwicklung.

## Allgemeine Arbeitsweise

- Bevorzuge kleine, nachvollziehbare Aenderungen mit klarem Zweck.
- Aendere keine fachlichen Ablaufe ohne Begruendung im PR-Text oder Commit.
- Bewahre Rueckwaertskompatibilitaet, sofern nicht explizit anders gefordert.
- Fuehre keine destruktiven Massen-Aenderungen durch (z. B. grossflaechiges Reformatting ohne Nutzen).

## Coding-Regeln

- Verwende fuer neue PHP-Dateien `declare(strict_types=1);`.
- Nutze sprechende Namen und klare Verantwortung pro Klasse/Funktion.
- Bevorzuge explizite Typen (Parameter, Rueckgabewerte, Properties), wenn sinnvoll.
- Halte Funktionen kurz und gut testbar.
- Jede neue oder geaenderte Datei soll einen kurzen, sinnvollen Datei-Kommentar zum Zweck der Datei enthalten.
- Position des Datei-Kommentars: In PHP-Dateien direkt unter `declare(strict_types=1);`, in anderen Dateien am Dateianfang.
- Der Datei-Kommentar soll zusaetzlich einen Copyright-Vermerk zugunsten die.internauten.ch GmbH und einen kurzen Hinweis auf die MIT-Lizenz enthalten.
- Inline-Kommentare im Code weiterhin nur dann ergaenzen, wenn komplexe Logik sonst schwer nachvollziehbar ist.

Beispiel fuer einen sinnvollen, kurzen Datei-Kommentar (PHP):

```php
// Stellt die API-Endpunkte fuer den Abgleich externer Lagerbestaende bereit.
// Copyright (c) 2026 die.internauten.ch GmbH
// Lizenz: MIT
```

## Prestashop-Modul-spezifisch

- Behalte die Struktur des Modulordners `InternautenB2BOffer` konsistent.
- Aendere Hook-Namen, Service-IDs, Konfigurations-Keys und Datenbankstrukturen nur mit klarer Migrationsstrategie.
- Beruecksichtige, dass das Modul lokal in die Prestashop-Umgebung aus `WoWGetPrestaLocal` eingebunden wird.
- Fuer Validierungen gegen den Prestashop-Core gilt: Der gesamte relevante Prestashop-PHP-Code liegt relativ zu diesem Repo unter `../WoWGetPrestaLocal/html`.
- Wenn fuer Analyse, Vergleich oder Kompatibilitaetspruefung Prestashop-Implementierungen benoetigt werden, soll dieser Pfad als primaere Referenz verwendet werden.

## Qualitaetssicherung

- Bei Codeaenderungen moeglichst passende Tests ergaenzen oder bestehende Tests anpassen.
- Wenn keine automatischen Tests vorhanden sind, mindestens konkrete manuelle Testschritte dokumentieren.
- Fehlermeldungen sollten fuer Entwickler aussagekraeftig sein und keine sensiblen Daten enthalten.

## Release-Konventionen

- Release-Tags folgen dem Muster `vX.Y.Z`.
- Tag-Erzeugung erfolgt ueber das Script im Ordner `scripts` (siehe README).
- Die GitHub-Action fuer Releases darf durch Codeaenderungen nicht unbeabsichtigt gebrochen werden.

## Copilot-Antwortstil im Repo

- Erklaerungen und Vorschlaege bevorzugt auf Deutsch.
- Bei unklaren Anforderungen zuerst Annahmen benennen.
- Bei groesseren Eingriffen zuerst eine kurze Schrittfolge vorschlagen, dann umsetzen.
