<?php
declare(strict_types=1);
$cleanupDays = (int)setting('cleanup_days', 90);
$maxReceiptMb = max(1, (int)setting('max_receipt_size_mb', 5));
?>
<div style="max-width:760px;margin-inline:auto;">

    <h1 style="margin-bottom:.5rem;">
        <i class="fa-solid fa-circle-question" aria-hidden="true" style="color:var(--color-primary);margin-right:.4rem;"></i>
        Anleitung &amp; FAQ
    </h1>
    <p class="text-muted" style="margin-bottom:.5rem;">
        Wie Zahltag funktioniert – für <strong>Gruppen-Admins</strong> (die eine Gruppe anlegen und verwalten) und
        <strong>Mitglieder</strong> (die Ausgaben eintragen und mitrechnen).
    </p>
    <p class="text-muted text-sm" style="margin-bottom:1.75rem;">
        Kein Account nötig, keine App-Installation – alles läuft über einen Link im Browser.
    </p>

    <!-- ============================================================ -->
    <!-- Grundprinzip                                                  -->
    <!-- ============================================================ -->
    <div class="card" style="margin-bottom:1.5rem;">
        <h2 style="font-size:1.125rem;">
            <i class="fa-solid fa-lightbulb" aria-hidden="true" style="color:var(--color-primary);"></i>
            Grundprinzip
        </h2>
        <p style="margin-bottom:1rem;">
            Für jede Gruppe (WG, Reise, Event …) gibt es eine gemeinsame Liste von Ausgaben. Mitglieder tragen über
            einen Link ein, wer was bezahlt hat und wie es aufgeteilt wird – ohne Registrierung. Zahltag berechnet
            daraus automatisch, wer wem noch was schuldet, mit möglichst wenigen Ausgleichszahlungen.
        </p>
        <div style="overflow-x:auto">
            <table class="table">
                <thead>
                    <tr><th>Rolle</th><th>Was sie tun kann</th></tr>
                </thead>
                <tbody>
                    <tr>
                        <td><i class="fa-solid fa-user-pen" aria-hidden="true"></i> Gruppen-Admin</td>
                        <td>Legt die Gruppe an, verwaltet Mitglieder und Einstellungen, exportiert PDF/CSV</td>
                    </tr>
                    <tr>
                        <td><i class="fa-solid fa-user-group" aria-hidden="true"></i> Mitglied</td>
                        <td>Trägt Ausgaben ein, sieht die Abrechnung, markiert Zahlungen als bezahlt/erhalten</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ============================================================ -->
    <!-- FAQ                                                           -->
    <!-- ============================================================ -->
    <h2 style="font-size:1.25rem;margin-bottom:.875rem;">
        <i class="fa-solid fa-circle-question" aria-hidden="true" style="color:var(--color-primary);"></i>
        Häufige Fragen
    </h2>

    <?php
    $faq = [
        [
            'q' => 'Muss ich mich registrieren, um mitzumachen?',
            'a' => 'Nein. Weder als Gruppen-Admin noch als Mitglied ist ein Konto nötig. Mitglieder tragen sich mit ihrem Namen ein, optional geschützt durch eine PIN (siehe unten).',
        ],
        [
            'q' => 'Ich habe den Verwaltungslink meiner Gruppe verloren – was nun?',
            'a' => 'Da es keinen Login gibt, kann der Verwaltungslink nicht zurückgesetzt oder erneut zugesendet werden, wenn er verloren geht. Link daher gut aufbewahren (z.&nbsp;B. als Lesezeichen).',
        ],
        [
            'q' => 'Sehen andere Mitglieder meine Zahlungsdaten (IBAN, PayPal …)?',
            'a' => 'Zahlungsdaten sind nur für aktive Mitglieder derselben Gruppe sichtbar – nötig, damit Ausgleichszahlungen per GiroCode, PayPal oder Wero abgewickelt werden können. Öffentlich (ohne Gruppenzugang) sind sie nicht einsehbar.',
        ],
        [
            'q' => 'Was passiert, wenn ich mich verrechnet habe oder eine Ausgabe löschen will?',
            'a' => 'Ausgaben lassen sich jederzeit von jedem Mitglied bearbeiten oder löschen – es gibt keine Freigabe durch Dritte. Bei gemeinsam genutzten Gruppen empfiehlt es sich, Änderungen kurz in der Gruppe abzusprechen.',
        ],
        [
            'q' => 'Kann ich Ausgaben in einer anderen Währung eintragen?',
            'a' => 'Falls der Betreiber der Instanz Mehrwährungsunterstützung aktiviert hat: ja. Der Betrag wird automatisch anhand des tagesaktuellen Wechselkurses in die Gruppenwährung umgerechnet.',
        ],
        [
            'q' => 'Wie lange bleibt meine Gruppe online?',
            'a' => "Unbegrenzt, solange sie aktiv genutzt wird. Archivierte Gruppen sowie leere Gruppen ohne Ausgaben werden nach aktuell {$cleanupDays}&nbsp;Tagen automatisch gelöscht (vom Betreiber der Instanz einstellbar).",
        ],
        [
            'q' => 'Kann ich die Abrechnung ausdrucken oder offline mitnehmen?',
            'a' => 'Ja, über den PDF-Export in der Gruppen-Verwaltung – mit Deckblatt, Ausgabentabelle, Salden, Zahlungsvorschlägen (inkl. GiroCode) und allen hochgeladenen Belegen. Alternativ als CSV für die Weiterverarbeitung in Tabellenkalkulationen.',
        ],
        [
            'q' => 'Ist die Gruppe passwortgeschützt?',
            'a' => 'Der Zugriff erfolgt über einen langen, zufälligen Link statt über ein Passwort. Zusätzlich kann der Gruppen-Admin eine PIN-Pflicht für Mitglieder aktivieren (siehe „Einstellungen im Detail“).',
        ],
        [
            'q' => "Wie groß dürfen Belege sein?",
            'a' => "Fotos oder PDFs bis {$maxReceiptMb}&nbsp;MB pro Beleg (vom Betreiber der Instanz einstellbar).",
        ],
    ];
    ?>
    <div style="margin-bottom:1.75rem;">
        <?php foreach ($faq as $item): ?>
        <details class="card" style="padding:0;margin-bottom:.625rem;">
            <summary style="padding:.875rem 1.125rem;cursor:pointer;font-weight:600;list-style:none;display:flex;align-items:center;gap:.5rem;">
                <?= $item['q'] /* fest im Code hinterlegt, kein Benutzer-Input */ ?>
                <i class="fa-solid fa-chevron-down" aria-hidden="true" style="margin-left:auto;font-size:.75rem;color:var(--color-text-muted);"></i>
            </summary>
            <div style="padding:0 1.125rem 1.125rem;color:var(--color-text-muted);">
                <?= $item['a'] ?>
            </div>
        </details>
        <?php endforeach; ?>
    </div>

    <!-- ============================================================ -->
    <!-- Für Gruppen-Admins                                            -->
    <!-- ============================================================ -->
    <h2 style="font-size:1.25rem;margin-bottom:.875rem;">
        <i class="fa-solid fa-user-pen" aria-hidden="true" style="color:var(--color-primary);"></i>
        Für Gruppen-Admins
    </h2>

    <div class="card" style="margin-bottom:1rem;">
        <h3><i class="fa-solid fa-people-group" aria-hidden="true"></i> Eine Gruppe anlegen</h3>
        <ol style="padding-left:1.25rem;line-height:1.75;">
            <li>Auf der Startseite eine neue Gruppe erstellen.</li>
            <li>Pflichtangabe: <strong>Name der Gruppe</strong>. Falls Mehrwährung aktiviert ist, kann zusätzlich die Gruppenwährung gewählt werden.</li>
            <li>Nach dem Absenden wird die Gruppe sofort angelegt – es gibt keinen Login. Stattdessen wird man direkt zur Verwaltungsseite weitergeleitet.</li>
        </ol>
    </div>

    <div class="card" style="margin-bottom:1rem;">
        <h3><i class="fa-solid fa-link" aria-hidden="true"></i> Die beiden Links: Verwaltung vs. Mitglieder-Ansicht</h3>
        <p style="margin-bottom:.75rem;">Nach dem Anlegen (und jederzeit auf der Verwaltungsseite) werden zwei unterschiedliche Links angezeigt:</p>
        <ul style="padding-left:1.25rem;line-height:1.75;margin-bottom:.75rem;">
            <li><strong>Verwaltungslink</strong> (<code>/manage/…</code>) – für den Gruppen-Admin. Damit werden Mitglieder verwaltet, Einstellungen geändert und PDF/CSV exportiert. <strong>Diesen Link nicht an Mitglieder weitergeben!</strong></li>
            <li><strong>Freigabelink</strong> (<code>/group/…</code>) – der Link, der an alle Mitglieder verschickt wird (z.&nbsp;B. per WhatsApp, E-Mail oder QR-Code). Darüber tragen Mitglieder Ausgaben ein und sehen die Abrechnung.</li>
        </ul>
        <div style="padding:.75rem 1rem;background:var(--color-primary-light);border-radius:var(--radius);">
            <strong><i class="fa-solid fa-circle-info" aria-hidden="true"></i> Wichtig:</strong>
            Beide Links enthalten ein langes, zufälliges Token statt eines Logins. Wer den Verwaltungslink kennt, kann die Gruppe verwalten – daher diesen Link vertraulich behandeln. Ein Lesezeichen empfiehlt sich, da es kein Passwort-Reset-Verfahren gibt.
        </div>
    </div>

    <div class="card" style="margin-bottom:1rem;">
        <h3><i class="fa-solid fa-paper-plane" aria-hidden="true"></i> Mitglieder einladen</h3>
        <p style="margin-bottom:0;">
            Der Freigabelink lässt sich direkt kopieren und teilen, oder über die Verwaltungsseite per E-Mail an eine
            oder mehrere Adressen (kommagetrennt) versenden.
        </p>
    </div>

    <div class="card" style="margin-bottom:1rem;">
        <h3><i class="fa-solid fa-users-gear" aria-hidden="true"></i> Mitglieder verwalten</h3>
        <p style="margin-bottom:.5rem;">Auf der Mitgliederseite lässt sich pro Mitglied festlegen:</p>
        <ul style="padding-left:1.25rem;line-height:1.75;margin-bottom:.75rem;">
            <li><strong>Deaktivieren</strong> – das Mitglied kann sich nicht mehr einloggen, bleibt aber in bestehenden Ausgaben sichtbar.</li>
            <li><strong>PIN zurücksetzen</strong> – falls ein Mitglied seine PIN vergessen hat.</li>
            <li><strong>Standard-Gewichtung</strong> – der Anteil, den dieses Mitglied bei neuen Ausgaben im Modus „Gewichtet“ automatisch bekommt (1&nbsp;= normal, z.&nbsp;B. 2 für einen Doppelanteil oder 0,5 für einen halben).</li>
        </ul>
    </div>

    <div class="card" style="margin-bottom:1rem;">
        <h3><i class="fa-solid fa-sliders" aria-hidden="true"></i> Einstellungen im Detail</h3>
        <div style="overflow-x:auto">
            <table class="table">
                <thead><tr><th>Einstellung</th><th>Bedeutung</th></tr></thead>
                <tbody>
                    <tr>
                        <td>PIN-Pflicht</td>
                        <td>Wenn aktiv, muss beim ersten Beitritt eine 4–12-stellige PIN vergeben werden, die beim erneuten Einloggen (z.&nbsp;B. an einem anderen Gerät) abgefragt wird. Wird sie deaktiviert, werden alle vergebenen PINs gelöscht.</td>
                    </tr>
                    <tr>
                        <td>Kategorien</td>
                        <td>Erlaubt, Ausgaben zu kategorisieren (z.&nbsp;B. Unterkunft, Essen, Transport). Kann optional oder verpflichtend sein; vordefinierte Kategorien lassen sich ein-/ausblenden, eigene Kategorien frei anlegen und sortieren.</td>
                    </tr>
                    <tr>
                        <td>PDF-Seitenränder</td>
                        <td>Überschreibt für diese Gruppe die instanzweiten Standard-Seitenränder der Abrechnungs-PDF.</td>
                    </tr>
                    <tr>
                        <td>Archivieren</td>
                        <td>Markiert die Gruppe als abgeschlossen. Archivierte Gruppen werden nach der eingestellten Aufbewahrungsfrist automatisch gelöscht (siehe FAQ oben).</td>
                    </tr>
                    <tr>
                        <td>Löschen</td>
                        <td>Löscht die Gruppe sofort und unwiderruflich, inklusive aller Ausgaben und Belege. Erfordert die Bestätigung durch erneute Eingabe des Gruppennamens.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card" style="margin-bottom:1.75rem;">
        <h3><i class="fa-solid fa-file-export" aria-hidden="true"></i> Export als PDF oder CSV</h3>
        <p style="margin-bottom:.5rem;">Über die Verwaltungsseite lässt sich die komplette Abrechnung exportieren:</p>
        <ul style="padding-left:1.25rem;line-height:1.75;margin-bottom:0;">
            <li>Die <strong>PDF-Abrechnung</strong> enthält Deckblatt, Ausgabentabelle, Salden, Zahlungsvorschläge mit GiroCode (bei EUR-Gruppen) sowie alle hochgeladenen Belege – Fotos werden als Bildseiten eingebettet, PDF-Belege seitengenau ins Dokument eingefügt.</li>
            <li>Die <strong>CSV-Datei</strong> enthält alle Ausgaben zur Weiterverarbeitung in einer Tabellenkalkulation.</li>
        </ul>
    </div>

    <!-- ============================================================ -->
    <!-- Für Mitglieder                                                -->
    <!-- ============================================================ -->
    <h2 style="font-size:1.25rem;margin-bottom:.875rem;">
        <i class="fa-solid fa-user-group" aria-hidden="true" style="color:var(--color-primary);"></i>
        Für Mitglieder
    </h2>

    <div class="card" style="margin-bottom:1rem;">
        <h3><i class="fa-solid fa-hand-pointer" aria-hidden="true"></i> Einer Gruppe beitreten</h3>
        <ol style="padding-left:1.25rem;line-height:1.75;margin-bottom:.75rem;">
            <li>Den vom Gruppen-Admin erhaltenen <strong>Freigabelink</strong> öffnen.</li>
            <li>Eigenen Namen eintragen – falls PIN-Pflicht aktiv ist, zusätzlich eine PIN (4–12 Zeichen) vergeben.</li>
            <li>Beim erneuten Besuch (z.&nbsp;B. an einem anderen Gerät) den eigenen Namen aus der Liste wählen und mit der PIN anmelden.</li>
        </ol>
        <p class="text-muted text-sm" style="margin-bottom:0;">
            Keine Registrierung, keine E-Mail-Adresse nötig. Die Anmeldung gilt browserbasiert für die Sitzung.
        </p>
    </div>

    <div class="card" style="margin-bottom:1rem;">
        <h3><i class="fa-solid fa-receipt" aria-hidden="true"></i> Eine Ausgabe eintragen</h3>
        <p style="margin-bottom:.5rem;">Beim Erfassen einer Ausgabe werden festgelegt:</p>
        <ul style="padding-left:1.25rem;line-height:1.75;margin-bottom:.75rem;">
            <li><strong>Beschreibung, Betrag, Datum</strong> und wer bezahlt hat.</li>
            <li><strong>Aufteilung</strong> – drei Modi stehen zur Wahl:</li>
        </ul>
        <div style="overflow-x:auto;margin-bottom:.75rem;">
            <table class="table">
                <thead><tr><th>Modus</th><th>Bedeutung</th></tr></thead>
                <tbody>
                    <tr><td>Gleichmäßig</td><td>Der Betrag wird zu gleichen Teilen auf alle ausgewählten Mitglieder aufgeteilt.</td></tr>
                    <tr><td>Gewichtet</td><td>Aufteilung nach individueller Gewichtung je Mitglied (z.&nbsp;B. Kinder zählen halb).</td></tr>
                    <tr><td>Benutzerdefiniert</td><td>Jeder Betrag pro Mitglied wird frei von Hand eingetragen.</td></tr>
                </tbody>
            </table>
        </div>
        <p style="margin-bottom:0;">
            Optional lässt sich ein <strong>Beleg</strong> (Foto oder PDF) sowie – falls aktiviert – eine
            <strong>Kategorie</strong> hinzufügen. Jede Ausgabe kann später von jedem Mitglied bearbeitet oder
            gelöscht werden.
        </p>
    </div>

    <div class="card" style="margin-bottom:1rem;">
        <h3><i class="fa-solid fa-scale-balanced" aria-hidden="true"></i> Die Abrechnung verstehen</h3>
        <p style="margin-bottom:0;">
            Auf der Abrechnungsseite zeigt Zahltag für jedes Mitglied den Saldo (bezahlt minus Anteil) sowie eine
            Liste konkreter Ausgleichszahlungen – berechnet mit einem Algorithmus, der die Anzahl der nötigen
            Überweisungen minimiert, statt dass jeder mit jedem einzeln abrechnet.
        </p>
    </div>

    <div class="card" style="margin-bottom:1rem;">
        <h3><i class="fa-solid fa-money-bill-transfer" aria-hidden="true"></i> Zahlungen markieren &amp; bestätigen</h3>
        <p style="margin-bottom:.5rem;">Das Begleichen einer Ausgleichszahlung läuft in zwei Schritten:</p>
        <ol style="padding-left:1.25rem;line-height:1.75;margin-bottom:0;">
            <li>Wer gezahlt hat, markiert die Zahlung in der Abrechnung als <strong>„bezahlt“</strong>.</li>
            <li>Der Empfänger bestätigt den Erhalt – erst dann gilt die Zahlung in der Abrechnung als
                <strong>„bestätigt“</strong> und fließt entsprechend in die weitere Berechnung ein.</li>
        </ol>
    </div>

    <div class="card" style="margin-bottom:1rem;">
        <h3><i class="fa-solid fa-qrcode" aria-hidden="true"></i> Bezahlen per GiroCode, PayPal oder Wero</h3>
        <p style="margin-bottom:0;">
            Jedes Mitglied kann in den eigenen Zahlungsdaten IBAN (für den SEPA-GiroCode-QR, nur in EUR-Gruppen),
            einen PayPal- sowie einen Wero-Link hinterlegen. Andere Mitglieder sehen diese Angaben bei den
            Zahlungsvorschlägen und können direkt per QR-Code oder Link überweisen.
        </p>
    </div>

    <!-- ============================================================ -->
    <!-- Datenschutz & Sicherheit                                      -->
    <!-- ============================================================ -->
    <div class="card" style="margin-bottom:1rem;">
        <h2 style="font-size:1.125rem;">
            <i class="fa-solid fa-shield-halved" aria-hidden="true" style="color:var(--color-primary);"></i>
            Datenschutz &amp; Sicherheit
        </h2>
        <ul style="padding-left:1.25rem;line-height:1.8;margin-bottom:0;">
            <li>Es ist <strong>keine Registrierung</strong> erforderlich, weder für Gruppen-Admins noch für Mitglieder.</li>
            <li>Der Zugriff erfolgt über lange, zufällige Links (Tokens) statt über Benutzerkonten. Wer einen Link kennt, hat Zugriff auf die jeweilige Ansicht – Links daher nicht öffentlich teilen.</li>
            <li>Belegfotos und -PDFs sind nicht direkt über das Internet abrufbar, sondern nur für angemeldete Mitglieder der jeweiligen Gruppe.</li>
            <li>Zahlungsdaten (IBAN, PayPal, Wero) sind nur für aktive Mitglieder derselben Gruppe sichtbar.</li>
            <li>Archivierte und leere Gruppen werden nach Ablauf der Aufbewahrungsfrist automatisch inklusive aller Daten gelöscht.</li>
        </ul>
    </div>

</div>
