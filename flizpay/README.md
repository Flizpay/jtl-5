# FLIZpay für JTL-Shop 5

Zahlungen mit FLIZpay direkt im JTL-Shop – gebührenfrei für dich, mit Rabatt/Cashback
für deine Kundschaft.

- **Voraussetzungen:** JTL-Shop ab 5.3.0, PHP ab 8.1, ausgehende HTTPS-Verbindungen
  zu `api.flizpay.de`
- **Währung:** EUR
- **Bestellablauf:** Die Bestellung wird vor der Zahlung angelegt; die Zahlung wird
  anschließend auf der FLIZpay-Seite (bzw. in der FLIZpay-App) abgeschlossen.

## Installation

1. Plugin-ZIP im Shop-Backend unter **Plugins → Plugin-Verwaltung → Upload** hochladen
   und installieren.
2. Unter **Plugins → FLIZpay → Einstellungen** den **API-Key** eintragen (zu finden im
   FLIZ-Firmenkonto unter „Installation“) und speichern.
3. Das Plugin registriert daraufhin automatisch die Webhook-URL bei FLIZpay und holt
   sich den Webhook-Schlüssel sowie die Rabattdaten.
4. FLIZpay sendet unmittelbar danach eine Test-Benachrichtigung an den Shop. Im Tab
   **Status** wird die Verbindung dann als *verbunden* angezeigt.
5. Die Zahlungsart in **Zahlungsarten** wie gewohnt Kundengruppen/Versandarten zuordnen.

> **Wichtig:** FLIZpay wird im Checkout erst angeboten, wenn die Test-Benachrichtigung
> angekommen ist. Der Shop muss dafür öffentlich erreichbar sein – ohne Passwortschutz,
> ohne IP-Sperre und mit gültigem SSL-Zertifikat. Auf Staging-Systemen mit Basic-Auth
> funktioniert das nicht.

## Rabatt / Cashback

Der Rabatt wird ausschließlich im FLIZ-Firmenkonto gepflegt. Das Plugin übernimmt ihn
automatisch (beim Speichern der Einstellungen und danach live per Webhook) und zeigt ihn
im Checkout als „FLIZpay – Bis zu X% Rabatt“ an.

Gewährt FLIZpay bei einer Zahlung einen Rabatt, wird die Bestellung nach dem
Zahlungseingang um eine Rabattposition ergänzt und die Bestellsumme entsprechend
korrigiert – **bevor** die Bestellung an JTL-Wawi übergeben wird.

Damit das funktioniert, muss die Einstellung **„Bestellungen bis zur Zahlung vor
JTL-Wawi zurückhalten“** aktiviert bleiben (Standard). Ist sie deaktiviert, kann ein
Rabatt nicht mehr automatisch in die Bestellung übernommen werden; das Plugin vermerkt
ihn dann nur als Hinweis in der Bestellung, damit du ihn in JTL-Wawi manuell anpassen
kannst.

## Zahlungsbestätigung per Webhook

Zahlungen werden ausschließlich durch signierte Webhooks von FLIZpay bestätigt. Die
Rückkehr-Seite fragt keinen Zahlungsstatus bei FLIZpay ab; sie liest nur den lokalen
Status und wartet kurz auf den Webhook.

Eine verlorene Webhook-Benachrichtigung kann deshalb nicht durch den Shop rekonstruiert
werden. FLIZpay muss fehlgeschlagene Zustellungen erneut senden. Solange kein Webhook
ankommt, bleibt die Bestellung unbezahlt und bei aktivierter Wawi-Sperre zurückgehalten.

## Was passiert bei fehlgeschlagenen Zahlungen?

Bricht eine Zahlung ab oder schlägt sie fehl, bleibt die Bestellung **offen**, damit die
Kundschaft sie über die Bestellübersicht erneut bezahlen kann. Das Plugin storniert
unbezahlte Bestellungen nicht automatisch.

## Rückerstattungen

Rückerstattungen sind nicht über den Shop möglich und werden direkt über FLIZpay
abgewickelt.

## Fehlersuche

- **Tab „Status“** zeigt Verbindungszustand, registrierte Webhook-URL, Zeitpunkt der
  letzten Benachrichtigung, Rabattdaten und alle offenen Zahlungen. Die Liste ist
  schreibgeschützt; Zahlungsstatus werden nur durch Webhooks geändert.
- **Zahlungs-Log:** Backend → *System → Log* bzw. das Zahlungsart-Log; alle FLIZpay-
  Ereignisse werden dort mit Bestell- und Transaktions-ID protokolliert.
- **Nach einem Domain-/Shop-URL-Wechsel** muss die Verbindung im Tab „Status“ einmal neu
  aufgebaut werden, damit FLIZpay die neue Webhook-URL kennt.
