<?php

// ===========================================================================
// Katasteramt (PHP-Klasse seit 09.09.2026 "Katasteramt", vormals "StrukturHub"
// — Präfix STRUKT_ bleibt unverändert, siehe CLAUDE.md) — macht die
// BESTEHENDE Objektbaum-Struktur einer IP-Symcon-Installation (Etagen →
// Räume → Geräte-Instanzen) maschinenlesbar, damit andere NRG-Stack-Module
// Geräte zuverlässig eingruppieren können, ohne jedes Mal selbst zu raten
// ("jedesmal anders Chaos", Auftraggeber-Zitat).
//
// STATUS v0.1: reines Auskunfts-Modul (read-only). Legt nichts an, baut
// nichts um — der Nutzer zeigt einmal im Formular, wo seine Struktur liegt,
// Katasteramt liefert das als Vertrag (STRUKT_GetStructure). Der Gerüst-
// Generator (v0.2, Kategorien+Links anlegen) baut später darauf auf.
//
// Kein Modul im Verbund wird vorausgesetzt — Katasteramt hat keine
// Partnermodul-Abhängigkeit und funktioniert komplett eigenständig.
// ===========================================================================

class Katasteramt extends IPSModule
{
    public function Create()
    {
        parent::Create();

        // Wurzelkategorie, unter der die Struktur beginnt (z. B. "Neues
        // Zuhause (Räume)"). Beispielwert, keine Vorgabe — jede Installation
        // hat eine andere Kategorie/Namen.
        $this->RegisterPropertyInteger('RootCategoryID', 0);

        // Welche direkten Kinder der Wurzelkategorie Etagen sind (statt z. B.
        // Gewerke-Kategorien wie "Energie"/"Heizung", die auf derselben Ebene
        // liegen können). Muss der Nutzer einmal bestätigen — automatische
        // Erkennung wäre Raterei, siehe CLAUDE.md. Reihen: CategoryID/Name
        // (Anzeige, wird bei jedem Formularaufbau frisch befüllt, siehe
        // SUITE.md Store-Review Punkt 3) + IsLevel (die eigentliche Eingabe).
        $this->RegisterPropertyString('Levels', '[]');

        // v0.2 Baumeister: Eingabe-/Planungszustand für Etagen/Räume,
        // die noch angelegt werden sollen (kein Lesevertrag, keine
        // Stabilitätsgarantie wie beim key in buildStructure() nötig).
        $this->RegisterPropertyString('GenLevels', '[]'); // [{Label:string}]
        $this->RegisterPropertyString('GenRooms', '[]');  // [{Label:string, LevelLabel:string}]

        $this->RegisterAttributeInteger('LastRefreshTs', 0);

        // Persistente Key-Zuordnung categoryID -> key (EIN gemeinsamer
        // Namensraum über levels UND rooms hinweg, MeterHub-Anforderung
        // 28.08.2026). Ein Key wird beim ersten Erfassen einer Kategorie aus
        // ihrem damaligen Namen abgeleitet und danach NIE mehr neu berechnet
        // — Konsumenten leiten daraus stabile Idents/Variablen ab (Archiv-
        // Historie), eine spätere Umbenennung im Objektbaum darf den Key
        // nicht ändern. Siehe resolveKey().
        $this->RegisterAttributeString('KeyRegistry', '{}');

        // Änderungserkennung ohne volles JSON-Diffing beim Konsumenten
        // (MeterHub/EMS-Wunsch 28.08.2026): Hash der zuletzt gelieferten
        // levels/rooms-Struktur + Zeitpunkt der letzten tatsächlichen
        // Änderung. Siehe touchChangeTimestamp().
        $this->RegisterAttributeString('LastStructureHash', '');
        $this->RegisterAttributeInteger('StructureChangedAt', 0);

        // Dismiss-Zustände der Formular-Hinweise (Verbund-Konvention, siehe
        // SUITE.md "Einheitliche Formular-Optik").
        $this->RegisterAttributeString('NewsAckVersion', '');
        $this->RegisterAttributeBoolean('ForumHintDismissed', false);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        if ($this->ReadPropertyInteger('RootCategoryID') <= 0) {
            $this->SetStatus(104);
            return;
        }
        $this->SetStatus(102);
    }

    // -----------------------------------------------------------------
    // Öffentlicher Vertrag
    // -----------------------------------------------------------------

    /**
     * STRUKT_GetStructure($id): string
     *
     * WICHTIG: Die Rückgabe ist ein JSON-STRING, kein PHP-Array (Verbund-
     * Konvention) — beim Konsumenten json_decode(STRUKT_GetStructure($id), true)
     * aufrufen, niemals is_array() direkt auf das Ergebnis prüfen.
     *
     * {
     *   "contractVersion": "1.1",
     *   "instanceID": 12345,
     *   "structureChangedAt": 1787900000,
     *   "levels": [ {"key":"eg","label":"Erdgeschoss","categoryID":23050,"order":0,"number":null}, ... ],
     *   "rooms":  [ {"key":"kueche","label":"101 Küche","level":"eg","categoryID":51304,
     *                "order":0,"roomType":"kueche","number":"101",
     *                "deviceInstanceIDs":[30131,27898,48294]}, ... ]
     * }
     *
     * - "levels" ist leer, wenn keine Etagen-Ebene bestätigt wurde — "rooms[].level"
     *   ist dann ebenfalls "" (Räume liegen direkt unter der Wurzelkategorie).
     *   Existieren Etagen, kann "rooms[].level" TROTZDEM "" sein für Räume, die
     *   der v0.2-Baumeister bewusst ohne Etagen-Zuordnung direkt unter
     *   der Wurzel angelegt hat (gemischte Struktur) — erkennbar am
     *   generator-eigenen Ident-Präfix, nicht jede unflagged Kategorie wird
     *   automatisch zum Raum (sonst kämen Gewerke-Kategorien wieder rein).
     * - "key" (levels UND rooms) ist GARANTIERT: nur Zeichen aus [a-z0-9_]
     *   (Umlaute transliteriert), eindeutig über levels UND rooms HINWEG (ein
     *   gemeinsamer Namensraum, nicht zwei getrennte), und STABIL über eine
     *   Umbenennung im Objektbaum hinweg — der Key wird beim ersten Erfassen
     *   einer categoryID aus deren damaligem Namen abgeleitet und danach
     *   dauerhaft an dieser categoryID festgemacht (siehe resolveKey()), nie
     *   bei jedem Aufruf neu aus dem aktuellen Label berechnet. Konsumenten
     *   dürfen daraus abgeleitete Idents/Variablen dauerhaft anlegen, ohne
     *   dass eine spätere Umbenennung sie verwaist.
     * - "deviceInstanceIDs" ist bereits dedupliziert und um tote/namenlose Links
     *   bereinigt (siehe resolveRoomDevices()) — Konsumenten müssen das nicht
     *   selbst nochmal lösen. Ein Link, dessen Ziel eine VARIABLE (statt einer
     *   Instanz) ist, wird auf deren Elterninstanz aufgelöst (z. B. ein
     *   "Licht"-Link direkt auf eine Schalter-Variable eines Aktors) — nur ein
     *   direkter Link/Variable ohne Instanz-Elternteil wird ignoriert.
     * - "order" ist die vom Nutzer im Symcon-Objektbaum gesetzte Reihenfolge
     *   (Konsolen-Drag&Drop, IPS-Objekt-Position) — verlässlicher als
     *   Array-Reihenfolge oder alphabetisches Sortieren nach "key"/"label"
     *   (sonst würde z. B. "Dachgeschoss" vor "Erdgeschoss" einsortieren).
     *   Aufsteigend sortieren, bei Gleichstand ist die Reihenfolge beliebig.
     * - "roomType" ist eine HEURISTISCHE Best-Effort-Ableitung aus dem
     *   Raumnamen (z. B. für eine Icon-Auswahl) aus einem festen Vokabular
     *   (siehe inferRoomType()) — "null", wenn nicht erkannt. Kein
     *   verlässlicher Fachwert, nur eine Anzeige-Hilfe.
     * - "number" (levels UND rooms, seit contractVersion 1.1) ist eine
     *   HEURISTISCHE Best-Effort-Ableitung einer Geschoss-/Raumnummer aus dem
     *   Namen (siehe extractNumber()) — Zahl kann vor ODER nach dem Namen
     *   stehen ("101 Küche"/"Küche 101"), mit/ohne Trenner. String, nicht
     *   int (führende Nullen bleiben erhalten), "null" wenn keine Nummer
     *   erkennbar. Funktioniert für JEDE Kategorie, nicht nur über den
     *   Baumeister erzeugte. Kein Fachwert — z. B. für Konsumenten
     *   gedacht, die Geräte-Idents aus der Raumnummer ableiten wollen.
     * - "structureChangedAt" (Unix-Zeitstempel) ändert sich NUR, wenn sich
     *   levels/rooms inhaltlich seit dem letzten Aufruf tatsächlich geändert
     *   haben (Hash-Vergleich intern) — Konsumenten können das als billigen
     *   Änderungs-Check pollen, statt das komplette JSON zu diffen. Es gibt
     *   KEINEN Push-Mechanismus (kein Event/keine Nachricht bei Änderung).
     * - "instanceID" ist die ID dieser Katasteramt-Instanz (Debugging/Logging).
     * - Ohne konfigurierte Wurzelkategorie liefert dies leere levels/rooms-Arrays
     *   und "structureChangedAt": 0, kein Fehler.
     * - MEHRERE Katasteramt-Instanzen sind ausdrücklich zulässig (z. B. Haupthaus
     *   + Nebengebäude mit getrennter Wurzelkategorie) — KEINE Singleton-Annahme.
     *   Konsumenten iterieren über ALLE Instanzen von
     *   IPS_GetInstanceListByModuleID('{CA700334-0982-F356-0617-6952868137E9}'),
     *   nicht nur die erste gefundene.
     */
    public function GetStructure(): string
    {
        return json_encode($this->buildStructure(), JSON_UNESCAPED_UNICODE);
    }

    // -----------------------------------------------------------------
    // Formular-Aktion (Muster 1 aus SUITE.md: echo-Rückgabe + zusätzlich
    // Muster 2: persistente Statuszeile per UpdateFormField)
    // -----------------------------------------------------------------

    public function RefreshStructure(): string
    {
        $structure = $this->buildStructure();
        $this->WriteAttributeInteger('LastRefreshTs', time());

        $this->UpdateFormField('StatusLine', 'caption', $this->statusLineText($structure));
        // 'values' erwartet einen JSON-STRING, kein PHP-Array (RPC-Layer
        // konvertiert verschachtelte Arrays nicht automatisch) — Live-Fund
        // Dietmar 28.08.2026: "Cannot auto-convert value for parameter Value".
        $this->UpdateFormField('StructurePreview', 'values', json_encode($this->previewRows($structure)));

        $roomCount = count($structure['rooms']);
        if ($this->ReadPropertyInteger('RootCategoryID') <= 0) {
            return 'ℹ️ Noch keine Wurzelkategorie ausgewählt.';
        }
        if ($roomCount === 0) {
            return 'ℹ️ Keine Räume gefunden — Wurzelkategorie und Etagen-Auswahl prüfen.';
        }

        $deviceCount = array_sum(array_map(fn($r) => count($r['deviceInstanceIDs']), $structure['rooms']));
        $levelCount  = count($structure['levels']);
        $levelTxt    = $levelCount > 0 ? "$levelCount Etage(n), " : '';

        return "✅ {$levelTxt}{$roomCount} Raum/Räume, {$deviceCount} Geräte-Instanz(en) eingelesen.";
    }

    // Formular-Hinweise ausblenden (Muster: EMS' AckNews()/DismissForumHint(),
    // siehe SUITE.md "Einheitliche Formular-Optik").
    public function AckNews(): void
    {
        $lib = @IPS_GetLibrary('{5E0A988D-0222-B254-88BE-61112640BBD5}');
        $this->WriteAttributeString('NewsAckVersion', is_array($lib) ? ($lib['Version'] ?? '') : '');
        $this->UpdateFormField('NewsPanel', 'visible', false);
    }

    public function DismissForumHint(): void
    {
        $this->WriteAttributeBoolean('ForumHintDismissed', true);
        $this->UpdateFormField('ForumHint', 'visible', false);
    }

    // -----------------------------------------------------------------
    // v0.2 Baumeister — legt NUR Kategorien an (Etagen/Räume), keine
    // Geräte-Instanzen/Links. Massen-Hilfe-Felder (Präfix/Start/Ende) haben
    // bewusst keine eigene Property, sondern werden als Formularfeld-Namen
    // direkt an den Button übergeben (Muster MeterHub VirtualPartners/
    // VirtualRole) — Vorschau/Anlegen arbeiten auf der GERADE OFFENEN Maske,
    // ein "Übernehmen" dazwischen ist nicht nötig.
    // WICHTIG: Listen-Parameter (rows/levelRows/roomRows/findings) sind
    // bewusst als "mixed" typisiert, NICHT als "string" (Live-Fund
    // 09.09.2026, siehe CHANGELOG 0.4.2): Wird ein List-Feld im onClick
    // direkt per Feldname referenziert (z. B. "$GenLevels" in
    // AddLevelRows($id, $GenLevels, ...)), übergibt der IPS-Kernel zur
    // Laufzeit ein "IPSList"-Objekt, KEINEN JSON-String — ein "string"-
    // Parameter löst dabei einen Fatal Error (TypeError) aus. Genau dasselbe
    // Muster nutzt Symcons eigenes EnergyManager-Modul
    // (UIUpdateNameAndStatus(mixed $Values, ...) mit dem Kommentar "$Values
    // is IPSList, which is not known by php validation methods, so we just
    // set type to mixed"). normalizeFormList() akzeptiert deshalb sowohl
    // einen JSON-String (Aufruf per php_eval/Skript) als auch ein iterierbares
    // IPSList-Objekt (Aufruf per Formular-Button) — ein IPSList-Objekt selbst
    // verhält sich beim Durchlaufen wie eine Liste assoziativer Arrays
    // (`foreach ($rows as $row) { $row['Label'] ... }` funktioniert direkt).
    // (Frühere Begründung, 28.08.2026, war unvollständig: "hat keinen
    // Datentyp"-Warnungen bei UNGETYPTEN Parametern gab es tatsächlich, aber
    // die Lösung "string" war zu eng — "mixed" vermeidet die Warnung
    // genauso und akzeptiert zusätzlich den echten Laufzeit-Typ.)
    // -----------------------------------------------------------------

    public function AddLevelRows(mixed $rows, string $prefix, int $start, int $end, string $numberPos = 'hinten'): string
    {
        $prefix = trim($prefix);
        if ($prefix === '') {
            return '⛔ Bitte zuerst ein Präfix eintragen (z. B. "Etage").';
        }
        if ($start > $end) {
            return '⛔ „von" muss kleiner oder gleich „bis" sein.';
        }
        if ($end - $start > 500) {
            return '⛔ Maximal 500 Zeilen auf einmal — Bereich eingrenzen.';
        }

        $list = $this->normalizeFormList($rows);
        for ($n = $start; $n <= $end; $n++) {
            $list[] = ['Label' => $this->composeGeneratedLabel($prefix, $n, $numberPos)];
        }
        $this->UpdateFormField('GenLevels', 'values', json_encode($list));

        return '✅ ' . ($end - $start + 1) . ' Etagen-Zeile(n) eingefügt.';
    }

    public function AddRoomRows(mixed $rows, string $prefix, int $start, int $end, string $levelLabel, string $numberPos = 'hinten'): string
    {
        $prefix    = trim($prefix);
        $levelLabel = trim($levelLabel);

        // "etage_raum" braucht KEIN Präfix (die zusammengesetzte Nummer trägt
        // die Bedeutung bereits selbst, z. B. "1.01" ohne weiteren Namen,
        // siehe composeEtageRaumLabel()) — bei allen anderen Modi bleibt das
        // Präfix wie bisher Pflicht.
        if ($prefix === '' && $numberPos !== 'etage_raum') {
            return '⛔ Bitte zuerst ein Präfix eintragen (z. B. "Büro").';
        }
        if ($start > $end) {
            return '⛔ „von" muss kleiner oder gleich „bis" sein.';
        }
        if ($end - $start > 500) {
            return '⛔ Maximal 500 Zeilen auf einmal — Bereich eingrenzen.';
        }

        $floorNumber = null;
        if ($numberPos === 'etage_raum') {
            if ($levelLabel === '') {
                return '⛔ Für „Geschoss.Raum“ muss oben eine Etage angegeben werden.';
            }
            $floorNumber = $this->extractNumber($levelLabel);
            if ($floorNumber === null) {
                return '⛔ Aus der Etage „' . $levelLabel . '“ konnte keine Nummer abgeleitet werden (z. B. „Etage 1“ oder „1. Obergeschoss“).';
            }
        }

        $list  = $this->normalizeFormList($rows);
        $width = strlen((string) $end);
        for ($n = $start; $n <= $end; $n++) {
            $label = $numberPos === 'etage_raum'
                ? $this->composeEtageRaumLabel($floorNumber, $n, $width, $prefix)
                : $this->composeGeneratedLabel($prefix, $n, $numberPos);
            $list[] = ['Label' => $label, 'LevelLabel' => $levelLabel];
        }
        $this->UpdateFormField('GenRooms', 'values', json_encode($list));

        return '✅ ' . ($end - $start + 1) . ' Raum-Zeile(n) eingefügt.';
    }

    // "vorne": "101 Büro" (Nummer zuerst, ohne Punkt) — "vorne_punkt": "1.
    // Etage" (Nummer zuerst, MIT Punkt — übliche deutsche Ordinalschreibweise
    // bei Etagen/Kapiteln, z. B. "1. Obergeschoss") — "hinten" (Default,
    // bisheriges Verhalten): "Büro 101". Dietmar-Wunsch 28.08.2026:
    // Gebäude-Konventionen setzen die Nummer mal vor, mal nach dem Namen;
    // Ergänzung 09.09.2026: vorangestellte Nummern brauchen je nach
    // Konvention zusätzlich einen Punkt (Etagen) oder eben keinen
    // (Raumnummern) — deshalb als dritte, eigene Option statt fest verdrahtet.
    private function composeGeneratedLabel(string $prefix, int $n, string $numberPos): string
    {
        return match ($numberPos) {
            'vorne_punkt' => $n . '. ' . $prefix,
            'vorne'       => $n . ' ' . $prefix,
            default       => $prefix . ' ' . $n,
        };
    }

    // Zusammengesetzte Geschoss.Raum-Nummer ("1.01" … "1.20"), siehe
    // extractNumber()-Erweiterung vom 09.09.2026 — verbreitete Konvention bei
    // öffentlichen Gebäuden/Institutionen (Dietmar-Wunsch 09.09.2026). Nur für
    // Räume sinnvoll (braucht eine zugehörige Etage), NICHT Teil von
    // composeGeneratedLabel(), das auch für Etagen selbst genutzt wird, die
    // keine "eigene" übergeordnete Geschossnummer haben. Raumnummer-Teil wird
    // auf die Ziffernbreite von "bis" gepolstert (z. B. bei 1–20: "01".."20"),
    // damit die Nummern innerhalb einer Etage gleich lang bleiben — bei
    // größeren Bereichen (z. B. 1–150) entsprechend breiter, nie fest auf 2
    // Stellen verdrahtet. Präfix ist hier bewusst optional: die Nummer allein
    // ("1.01") ist bei dieser Konvention oft schon die vollständige, offizielle
    // Raumbezeichnung, ein Name ist nur ein optionaler Zusatz ("1.01 Büro").
    private function composeEtageRaumLabel(string $floorNumber, int $n, int $width, string $prefix): string
    {
        $composite = $floorNumber . '.' . str_pad((string) $n, $width, '0', STR_PAD_LEFT);
        return $prefix === '' ? $composite : $composite . ' ' . $prefix;
    }

    public function PreviewSkeleton(mixed $levelRows, mixed $roomRows): string
    {
        $result = $this->planSkeleton($this->normalizeFormList($levelRows), $this->normalizeFormList($roomRows), true);
        if ($result['error'] !== null) {
            $this->UpdateFormField('GenPreview', 'values', json_encode([]));
            return '⛔ ' . $result['error'];
        }

        $entries = array_merge($result['levelEntries'], $result['roomEntries']);
        $this->UpdateFormField('GenPreview', 'values', json_encode($entries));

        if (!$entries) {
            return 'ℹ️ Nichts einzufügen — zuerst Etagen/Räume eintragen oder die Massen-Hilfe nutzen.';
        }

        return $this->skeletonSummary($result, 'Würde anlegen');
    }

    public function BuildSkeleton(bool $confirmed, mixed $levelRows, mixed $roomRows): string
    {
        if (!$confirmed) {
            return '⛔ Bitte zuerst das Kästchen „Ich habe die Vorschau geprüft" bestätigen.';
        }

        $result = $this->planSkeleton($this->normalizeFormList($levelRows), $this->normalizeFormList($roomRows), false);
        if ($result['error'] !== null) {
            return '⛔ ' . $result['error'];
        }

        $entries = array_merge($result['levelEntries'], $result['roomEntries']);
        $this->UpdateFormField('GenPreview', 'values', json_encode($entries));

        // Komfort-Verzahnung mit v0.1: neu angelegte Etagen-Kategorien direkt
        // in der bestehenden Levels-Tabelle vorhäkeln — NUR in der offenen
        // Maske (UpdateFormField), keine Property-Selbstpersistenz (Store-
        // Review Punkt 1). Der Nutzer bestätigt weiterhin selbst über das
        // normale Formular-"Übernehmen".
        if ($result['levelIDs']) {
            $this->UpdateFormField('Levels', 'values', json_encode($this->buildLevelsRows($result['levelIDs'])));
        }

        $summary = $entries
            ? $this->skeletonSummary($result, 'Angelegt')
            : 'ℹ️ Nichts angelegt — keine Etagen/Räume eingetragen.';
        $this->UpdateFormField('GenStatusLine', 'caption', $summary);

        return $summary;
    }

    private function skeletonSummary(array $result, string $verb): string
    {
        $lc = count($result['levelEntries']);
        $ln = count(array_filter($result['levelEntries'], fn($e) => $e['Status'] === 'neu'));
        $rc = count($result['roomEntries']);
        $rn = count(array_filter($result['roomEntries'], fn($e) => $e['Status'] === 'neu'));

        $parts = [];
        if ($lc > 0) {
            $parts[] = "$lc Etage(n) ($ln neu)";
        }
        if ($rc > 0) {
            $parts[] = "$rc Raum/Räume ($rn neu)";
        }

        return '✅ ' . $verb . ': ' . implode(', ', $parts) . '.';
    }

    private function planSkeleton(array $levelRows, array $roomRows, bool $dryRun): array
    {
        $root = $this->ReadPropertyInteger('RootCategoryID');
        if ($root <= 0 || !IPS_ObjectExists($root)) {
            return [
                'error'        => 'Keine Wurzelkategorie konfiguriert — zuerst oben im Formular festlegen.',
                'levelEntries' => [],
                'roomEntries'  => [],
                'levelIDs'     => [],
            ];
        }

        $levelEntries   = [];
        $levelIDByLabel = [];
        $levelIDs       = [];
        $pos            = 0;
        foreach ($levelRows as $row) {
            $label = trim((string) ($row['Label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $ident      = 'strukt_' . $this->slugify($label, 'etage');
            $existingID = $this->findChildByIdent($root, $ident);
            $catID      = $dryRun ? $existingID : $this->ensureCategory($root, $ident, $label, $pos);
            $status     = $existingID !== null ? 'vorhanden' : 'neu';

            $levelEntries[] = ['Pfad' => $label, 'Status' => $status, 'CategoryID' => $catID];
            $levelIDByLabel[mb_strtolower($label)] = $catID;
            if ($catID !== null) {
                $levelIDs[] = $catID;
            }
            $pos++;
        }

        $roomEntries = [];
        $posByParent = [];
        foreach ($roomRows as $row) {
            $label = trim((string) ($row['Label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $levelLabel = trim((string) ($row['LevelLabel'] ?? ''));
            $parentID   = $levelLabel !== '' ? ($levelIDByLabel[mb_strtolower($levelLabel)] ?? null) : $root;
            $pathPrefix = $levelLabel !== '' ? $levelLabel . ' / ' : '';

            if ($parentID === null) {
                // Zugehörige Etage existiert (noch) nicht (z. B. Vorschau vor
                // der ersten echten Anlage) — Raum kann noch nicht real
                // geprüft werden, gilt als "neu".
                $roomEntries[] = ['Pfad' => $pathPrefix . $label, 'Status' => 'neu', 'CategoryID' => null];
                continue;
            }

            $ident      = 'strukt_' . $this->slugify($label, 'raum');
            $existingID = $this->findChildByIdent($parentID, $ident);
            if ($dryRun) {
                $catID = $existingID;
            } else {
                $roomPos = $posByParent[$parentID] ?? 0;
                $catID   = $this->ensureCategory($parentID, $ident, $label, $roomPos);
                $posByParent[$parentID] = $roomPos + 1;
            }
            $status = $existingID !== null ? 'vorhanden' : 'neu';

            $roomEntries[] = ['Pfad' => $pathPrefix . $label, 'Status' => $status, 'CategoryID' => $catID];
        }

        return ['error' => null, 'levelEntries' => $levelEntries, 'roomEntries' => $roomEntries, 'levelIDs' => $levelIDs];
    }

    // Sucht ein direktes Kind mit gegebenem Ident (NICHT rekursiv — genau
    // die Suchtiefe, die für "hat DIESER Parent dieses Kind schon" richtig
    // ist). Bewusst manuell über IPS_GetChildrenIDs() statt
    // IPS_GetObjectIDByIdent() (dessen Verhalten bei Nichtfund uneindeutig
    // dokumentiert ist) — kein @-Unterdrücker vor einer IPS-API-Funktion,
    // deren Erfolg wir auswerten (SUITE.md Stolperstein 13).
    private function findChildByIdent(int $parentID, string $ident): ?int
    {
        if (!IPS_ObjectExists($parentID)) {
            return null;
        }
        foreach (IPS_GetChildrenIDs($parentID) as $cid) {
            if (IPS_GetObject($cid)['ObjectIdent'] === $ident) {
                return $cid;
            }
        }
        return null;
    }

    // Idempotent: legt nur an, wenn unter $parentID noch kein Kind mit
    // diesem Ident existiert; Name wird bei jedem Aufruf neu gesetzt
    // (Relabeling ohne Neuanlage), Position nur bei echter Neuanlage
    // (Vorbild InverterHub/MeterHub EnsureCategory()).
    private function ensureCategory(int $parentID, string $ident, string $name, int $position): int
    {
        $catID = $this->findChildByIdent($parentID, $ident);
        if ($catID === null) {
            $catID = IPS_CreateCategory();
            IPS_SetParent($catID, $parentID);
            IPS_SetIdent($catID, $ident);
            IPS_SetPosition($catID, $position);
        }
        IPS_SetName($catID, $name);
        return $catID;
    }

    // Formularfeld-Listen können als Array ODER als JSON-String hereinkommen
    // (abhängig vom Aufrufkontext) — analog MigrationsHubs NormalizeFormList().
    private function normalizeFormList(mixed $rows): array
    {
        if (is_string($rows)) {
            $decoded = json_decode($rows, true);
            return is_array($decoded) ? $decoded : [];
        }
        if (is_array($rows)) {
            return $rows;
        }
        if ($rows instanceof \Traversable) {
            // List-Feld direkt per Formularfeld-Namen referenziert
            // (z. B. "$GenLevels" im onClick) — der IPS-Kernel übergibt dann
            // ein IPSList-Objekt statt eines JSON-Strings, siehe Kommentar
            // oberhalb der Baumeister-Methoden.
            $out = [];
            foreach ($rows as $row) {
                $out[] = is_array($row) ? $row : (array) $row;
            }
            return $out;
        }
        return [];
    }

    // -----------------------------------------------------------------
    // GetConfigurationForm — live berechnete Felder (Levels-Auswahl,
    // Statuszeile, Vorschau-Liste), Basisgerüst aus form.json.
    // -----------------------------------------------------------------

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        $this->injectVersionIntoDocPanel($form);
        $this->injectNewsVisibility($form);
        $this->injectForumHintVisibility($form);
        $this->injectLevelsValues($form);
        $this->injectStatusLine($form);
        $this->injectPreview($form);
        $this->injectStandesamtValues($form);

        return json_encode($form);
    }

    private function injectNewsVisibility(array &$form): void
    {
        $lib = @IPS_GetLibrary('{5E0A988D-0222-B254-88BE-61112640BBD5}');
        $cur = is_array($lib) ? ($lib['Version'] ?? '') : '';
        $ack = $this->ReadAttributeString('NewsAckVersion');
        foreach ($form['elements'] as &$el) {
            if (($el['name'] ?? '') === 'NewsPanel') {
                $el['visible'] = ($cur === '' || $ack !== $cur);
                break;
            }
        }
    }

    private function injectForumHintVisibility(array &$form): void
    {
        $dismissed = $this->ReadAttributeBoolean('ForumHintDismissed');
        foreach ($form['elements'] as &$el) {
            if (($el['name'] ?? '') === 'ForumHint') {
                $el['visible'] = !$dismissed;
                break;
            }
        }
    }

    private function injectVersionIntoDocPanel(array &$form): void
    {
        $lib    = @IPS_GetLibrary('{5E0A988D-0222-B254-88BE-61112640BBD5}');
        $verTxt = (is_array($lib) && isset($lib['Version']))
            ? 'ℹ️ Katasteramt Version ' . $lib['Version'] . ' (Build ' . ($lib['Build'] ?? '?') . ')'
            : 'ℹ️ Katasteramt';
        foreach ($form['elements'] as &$el) {
            if (($el['type'] ?? '') === 'ExpansionPanel' && str_contains($el['caption'] ?? '', 'Dokumentation')) {
                array_unshift($el['items'], ['type' => 'Label', 'caption' => $verTxt]);
                break;
            }
        }
    }

    // Direkte Kinder der Wurzelkategorie live einsammeln, bisherige IsLevel-
    // Auswahl (per CategoryID) aus der gespeicherten Property übernehmen,
    // Name/Anzahl-Spalten aber immer frisch anzeigen (Store-Review Punkt 3 —
    // berechnete Anzeigespalten nie aus der Konfiguration nachladen).
    // $forceLevelIDs erzwingt IsLevel=true für bestimmte categoryIDs, unabhängig
    // von der gespeicherten Auswahl — genutzt vom v0.2-Baumeister, um
    // frisch angelegte Etagen-Kategorien direkt vorzuhäkeln.
    private function buildLevelsRows(array $forceLevelIDs = []): array
    {
        $root  = $this->ReadPropertyInteger('RootCategoryID');
        $prev  = $this->levelFlags();
        $force = array_flip($forceLevelIDs);

        $rows = [];
        if ($root > 0 && IPS_ObjectExists($root)) {
            foreach (IPS_GetChildrenIDs($root) as $cid) {
                if (!$this->isCategory($cid)) {
                    continue;
                }
                $rows[] = [
                    'IsLevel'    => isset($force[$cid]) ? true : ($prev[$cid] ?? false),
                    'CategoryID' => $cid,
                    'Name'       => IPS_GetName($cid),
                    'Kinder'     => count(IPS_GetChildrenIDs($cid)),
                ];
            }
        }
        return $rows;
    }

    private function injectLevelsValues(array &$form): void
    {
        $el = &$this->findFormElementByName($form['elements'], 'Levels');
        if ($el !== null) {
            $el['values'] = $this->buildLevelsRows();
        }
    }

    private function injectStatusLine(array &$form): void
    {
        $el = &$this->findFormElementByName($form['elements'], 'StatusLine');
        if ($el !== null) {
            $el['caption'] = $this->statusLineText($this->buildStructure());
        }
    }

    private function injectPreview(array &$form): void
    {
        $structure = $this->buildStructure();
        $el = &$this->findFormElementByName($form['elements'], 'StructurePreview');
        if ($el !== null) {
            $el['values'] = $this->previewRows($structure);
        }
    }

    private function injectStandesamtValues(array &$form): void
    {
        $findings = $this->analyzeNamingConventions();

        $status = &$this->findFormElementByName($form['elements'], 'StandesamtStatus');
        if ($status !== null) {
            $status['caption'] = $this->standesamtStatusText($findings);
        }

        $list = &$this->findFormElementByName($form['elements'], 'NamingFindings');
        if ($list !== null) {
            $list['values'] = $this->namingFindingRows($findings);
        }
    }

    // Sucht ein Formularelement anhand seines 'name' rekursiv, auch wenn es
    // in einem ExpansionPanel/RowLayout verschachtelt ist. Live-Fund
    // 09.09.2026: 'StructurePreview' liegt im Panel "🔍 Eingelesene Struktur"
    // und wurde deshalb beim ERSTEN Formular-Öffnen nie befüllt (nur
    // nachträglich über UpdateFormField() bei einem Button-Klick, der die
    // Verschachtelung ignoriert). Seit 28.09.2026 nutzen alle Injektoren
    // (injectLevelsValues/injectStatusLine/injectPreview/
    // injectStandesamtValues) diese Funktion, damit eine spätere
    // Formular-Umstrukturierung nicht wieder eine nur oberste Ebene
    // durchsuchende Injektion stillschweigend kaputt macht. Gibt eine
    // Referenz zurück, damit der Aufrufer das Element direkt verändern kann;
    // null, wenn nichts gefunden wurde.
    private function &findFormElementByName(array &$elements, string $name): ?array
    {
        foreach ($elements as &$el) {
            if (($el['name'] ?? '') === $name) {
                return $el;
            }
            if (isset($el['items']) && is_array($el['items'])) {
                $found = &$this->findFormElementByName($el['items'], $name);
                if ($found !== null) {
                    return $found;
                }
            }
        }
        $notFound = null;
        return $notFound;
    }

    private function statusLineText(array $structure): string
    {
        $ts = $this->ReadAttributeInteger('LastRefreshTs');
        if ($this->ReadPropertyInteger('RootCategoryID') <= 0) {
            return 'ℹ️ Noch keine Wurzelkategorie ausgewählt.';
        }
        if ($ts === 0) {
            return 'ℹ️ Noch nicht eingelesen — Button „Struktur jetzt einlesen“ nutzen.';
        }

        $roomCount   = count($structure['rooms']);
        $levelCount  = count($structure['levels']);
        $deviceCount = array_sum(array_map(fn($r) => count($r['deviceInstanceIDs']), $structure['rooms']));
        $icon        = $roomCount > 0 ? '✅' : '⚠️';
        $levelTxt    = $levelCount > 0 ? "$levelCount Etage(n), " : '';

        return "$icon {$levelTxt}{$roomCount} Raum/Räume, {$deviceCount} Geräte gefunden (zuletzt " . date('H:i:s', $ts) . ' Uhr).';
    }

    private function previewRows(array $structure): array
    {
        $rows = [];
        foreach ($structure['rooms'] as $room) {
            $rows[] = [
                'Raum'     => $room['label'],
                'Etage'    => $room['level'] !== '' ? $this->levelLabel($structure, $room['level']) : '—',
                'Typ'      => $room['roomType'] ?? '—',
                'Nummer'   => $room['number'] ?? '—',
                'Reihe'    => $room['order'],
                'Geraete'  => count($room['deviceInstanceIDs']),
            ];
        }
        return $rows;
    }

    private function levelLabel(array $structure, string $key): string
    {
        foreach ($structure['levels'] as $level) {
            if ($level['key'] === $key) {
                return $level['label'];
            }
        }
        return $key;
    }

    // -----------------------------------------------------------------
    // Struktur-Aufbau
    // -----------------------------------------------------------------

    private function buildStructure(): array
    {
        $root = $this->ReadPropertyInteger('RootCategoryID');
        if ($root <= 0 || !IPS_ObjectExists($root)) {
            return [
                'contractVersion'    => '1.1',
                'instanceID'         => $this->InstanceID,
                'structureChangedAt' => 0,
                'levels'             => [],
                'rooms'              => [],
            ];
        }

        $levelFlags = $this->levelFlags();
        $levelCatIDs = array_filter(
            IPS_GetChildrenIDs($root),
            fn($cid) => $this->isCategory($cid) && !empty($levelFlags[$cid])
        );

        // EIN gemeinsamer Key-Namensraum über levels UND rooms hinweg,
        // persistiert je categoryID (MeterHub-Anforderung 28.08.2026) —
        // siehe resolveKey().
        $registry = $this->loadKeyRegistry();

        $levels = [];
        $rooms  = [];

        if (empty($levelCatIDs)) {
            // Keine Etagen-Ebene bestätigt: Kinder der Wurzelkategorie sind
            // direkt Räume.
            foreach (IPS_GetChildrenIDs($root) as $cid) {
                if (!$this->isCategory($cid)) {
                    continue;
                }
                $rooms[] = $this->buildRoom($cid, '', $registry);
            }
        } else {
            foreach ($levelCatIDs as $lcid) {
                $key = $this->resolveKey($registry, $lcid, IPS_GetName($lcid));
                $levelLabel = IPS_GetName($lcid);
                $levels[] = [
                    'key'        => $key,
                    'label'      => $levelLabel,
                    'categoryID' => $lcid,
                    'order'      => $this->objectOrder($lcid),
                    'number'     => $this->extractNumber($levelLabel),
                ];
                foreach (IPS_GetChildrenIDs($lcid) as $rcid) {
                    if (!$this->isCategory($rcid)) {
                        continue;
                    }
                    $rooms[] = $this->buildRoom($rcid, $key, $registry);
                }
            }

            // Räume, die der v0.2-Baumeister BEWUSST ohne Etagen-
            // Zuordnung direkt unter der Wurzel angelegt hat (auch wenn
            // andere Räume derselben Struktur Etagen haben) — Live-Fund
            // 29.08.2026: wurden bislang komplett übersehen, weil dieser
            // Zweig nur die Kinder JE Etage durchsucht. Erkennbar am
            // strukt_-Ident-Präfix des Generators, NICHT einfach jede
            // unflagged Kategorie — sonst kämen hier wieder Gewerke-
            // Kategorien (Energie/Heizung/Test) als Räume rein, genau das
            // Problem, das die Etagen-Häkchen lösen sollen.
            $levelCatIDSet = array_flip($levelCatIDs);
            foreach (IPS_GetChildrenIDs($root) as $cid) {
                if (isset($levelCatIDSet[$cid]) || !$this->isCategory($cid)) {
                    continue;
                }
                if (strpos(IPS_GetObject($cid)['ObjectIdent'], 'strukt_') === 0) {
                    $rooms[] = $this->buildRoom($cid, '', $registry);
                }
            }
        }

        $this->pruneAndSaveKeyRegistry($registry);

        return [
            'contractVersion'    => '1.1',
            'instanceID'         => $this->InstanceID,
            'structureChangedAt' => $this->touchChangeTimestamp($levels, $rooms),
            'levels'             => $levels,
            'rooms'              => $rooms,
        ];
    }

    private function buildRoom(int $categoryID, string $levelKey, array &$registry): array
    {
        $label = IPS_GetName($categoryID);
        return [
            'key'               => $this->resolveKey($registry, $categoryID, $label),
            'label'             => $label,
            'level'             => $levelKey,
            'categoryID'        => $categoryID,
            'order'             => $this->objectOrder($categoryID),
            'roomType'          => $this->inferRoomType($label),
            'number'            => $this->extractNumber($label),
            'deviceInstanceIDs' => $this->resolveRoomDevices($categoryID),
        ];
    }

    // -----------------------------------------------------------------
    // Persistente Keys (categoryID -> key), Änderungserkennung
    // -----------------------------------------------------------------

    private function loadKeyRegistry(): array
    {
        $data = json_decode($this->ReadAttributeString('KeyRegistry'), true);
        return is_array($data) ? $data : [];
    }

    // Liefert den persistierten Key einer categoryID; erzeugt und persistiert
    // beim ERSTEN Erfassen einen neuen (aus dem damaligen Label abgeleitet,
    // eindeutig gegen ALLE bereits vergebenen Keys — levels UND rooms teilen
    // sich einen Namensraum). Eine spätere Umbenennung der Kategorie ändert
    // den bereits vergebenen Key NICHT mehr.
    private function resolveKey(array &$registry, int $categoryID, string $label): string
    {
        if (isset($registry[$categoryID])) {
            return $registry[$categoryID];
        }
        $used = array_values($registry);
        $key  = $this->uniqueKey($label, $used);
        $registry[$categoryID] = $key;
        return $key;
    }

    // Entfernt nur Einträge, deren Kategorie in Symcon tatsächlich gelöscht
    // wurde — NICHT, wenn eine Kategorie nur gerade nicht Teil der aktuell
    // gewählten Struktur ist (z. B. Etagen-Häkchen kurzzeitig entfernt), damit
    // der Key bei Rückkehr stabil bleibt.
    private function pruneAndSaveKeyRegistry(array $registry): void
    {
        foreach (array_keys($registry) as $cid) {
            if (!IPS_ObjectExists((int) $cid)) {
                unset($registry[$cid]);
            }
        }
        $this->WriteAttributeString('KeyRegistry', json_encode($registry));
    }

    // Aktualisiert structureChangedAt nur, wenn sich levels/rooms inhaltlich
    // seit dem letzten Aufruf wirklich geändert haben (Hash-Vergleich) —
    // spart Konsumenten das Diffen des kompletten JSON (MeterHub/EMS-Wunsch
    // 28.08.2026).
    private function touchChangeTimestamp(array $levels, array $rooms): int
    {
        $hash = md5(json_encode(['levels' => $levels, 'rooms' => $rooms], JSON_UNESCAPED_UNICODE));
        if ($hash !== $this->ReadAttributeString('LastStructureHash')) {
            $this->WriteAttributeString('LastStructureHash', $hash);
            $this->WriteAttributeInteger('StructureChangedAt', time());
        }
        return $this->ReadAttributeInteger('StructureChangedAt');
    }

    // Vom Nutzer im Objektbaum gesetzte Sortierposition (Konsolen-Drag&Drop) —
    // robuster für Konsumenten als Array-Reihenfolge oder alphabetisches
    // Sortieren nach key/label (Dashboard-Wunsch, 28.08.2026: sonst würde
    // z. B. "Dachgeschoss" alphabetisch vor "Erdgeschoss" einsortieren).
    private function objectOrder(int $id): int
    {
        // IPS_GetObject() liefert den Schlüssel "ObjectPosition", NICHT
        // "Position" — Live-Fund 28.08.2026 beim v0.2-Testlauf: order war
        // seit Einführung immer 0, weil der falsche Array-Schlüssel gelesen
        // wurde ("Position" existiert im Rückgabe-Array schlicht nicht, "??"
        // fing das lautlos ab, ohne Fehler oder Warnung).
        return (int) (IPS_GetObject($id)['ObjectPosition'] ?? 0);
    }

    // Heuristische Best-Effort-Ableitung einer Geschoss-/Raumnummer aus dem
    // Namen — NUR eine Anzeige-/Ableitungs-Hilfe (z. B. für Konsumenten, die
    // Geräte-Idents aus der Raumnummer bilden wollen, Dietmar-Wunsch
    // 28.08.2026), kein garantierter Fachwert. Nummer kann vor ODER nach dem
    // Namen stehen ("101 Büro" / "Büro 101"), mit oder ohne Trenner
    // (Leerzeichen/Punkt/Bindestrich) — funktioniert unabhängig davon, ob die
    // Kategorie über den v0.2-Generator oder manuell entstanden ist. Rein
    // numerische Namen ("101") zuerst behandeln, sonst würde die
    // Nachgestellt-Regel sie fälschlich in z. B. "10"+"1" zerlegen.
    //
    // Zusammengesetzte Geschoss.Raum-Nummer ("1.11 Büro" = Geschoss 1, Raum
    // 11) — verbreitete Konvention bei öffentlichen Gebäuden/Institutionen
    // (z. B. Hochschulen, Verwaltungsgebäude, siehe CLAUDE.md-Recherche
    // 09.09.2026), MUSS vor den einfachen Ziffernfolgen-Regeln geprüft
    // werden: sonst würde die "vorne"-Regel bei "1.11 Büro" nur die "1" vor
    // dem Punkt greifen und die eigentliche Raumnummer "11" verlieren.
    private function extractNumber(string $label): ?string
    {
        $label = trim($label);
        if ($label === '') {
            return null;
        }
        if (preg_match('/^\d+$/', $label)) {
            return $label;
        }
        if (preg_match('/^\d+\.\d+$/', $label)) {
            return $label;
        }
        // Vorangestellte zusammengesetzte Nummer ("1.11 Büro").
        if (preg_match('/^(\d+\.\d+)(?=\D)/', $label, $m)) {
            return $m[1];
        }
        // Nachgestellte zusammengesetzte Nummer ("Büro 1.11").
        if (preg_match('/(?<=\D)(\d+\.\d+)$/', $label, $m)) {
            return $m[1];
        }
        // Nachgestellte Nummer zuerst prüfen (Standard-Konvention "Name 101").
        if (preg_match('/(\d+)$/', $label, $m)) {
            return $m[1];
        }
        // Vorangestellte Nummer ("101 Name").
        if (preg_match('/^(\d+)/', $label, $m)) {
            return $m[1];
        }
        return null;
    }

    // Heuristische Best-Effort-Ableitung des Raumtyps aus dem Kategorienamen,
    // NUR für Anzeige-Zwecke (z. B. Icon-Auswahl, Dashboard-Wunsch
    // 28.08.2026) — kein Fachwert, keine Garantie. Token-Abgleich (nicht
    // Substring) gegen ein festes deutsches Vokabular, damit z. B. "wc" nicht
    // versehentlich mitten in einem anderen Wort matcht. Unbekannt -> null,
    // niemals raten/erfinden.
    private function inferRoomType(string $label): ?string
    {
        // Deckt bewusst sowohl privaten als auch gewerblichen Sprachgebrauch ab
        // (Grundregel "keine eigene Anlage als Norm" — nicht nur Wohnhaus-
        // Vokabular). Weiterhin rein heuristisch/optional, siehe Docblock.
        static $synonyms = [
            'kueche'           => ['kueche'],
            'bad'              => ['bad', 'badezimmer', 'dusche'],
            'wc'               => ['wc', 'toilette', 'sanitaer'],
            'wohnzimmer'       => ['wohnzimmer', 'wohnen'],
            'schlafzimmer'     => ['schlafzimmer', 'schlafen'],
            'kinderzimmer'     => ['kinderzimmer'],
            'buero'            => ['buero', 'office', 'arbeitszimmer'],
            'besprechungsraum' => ['besprechungsraum', 'konferenzraum', 'meetingraum', 'seminarraum', 'schulungsraum'],
            'empfang'          => ['empfang', 'rezeption', 'lobby', 'eingang'],
            'esszimmer'        => ['esszimmer', 'essen', 'kantine', 'pausenraum', 'personalraum'],
            'flur'             => ['flur', 'diele', 'windfang', 'gang', 'korridor'],
            'keller'           => ['keller'],
            'dachboden'        => ['dachboden', 'spitzboden'],
            'hwr'              => ['hwr', 'hauswirtschaftsraum', 'hauswirtschaft'],
            'vorrat'           => ['vorrat', 'vorratsraum', 'speisekammer'],
            'lager'            => ['lager', 'lagerraum', 'lagerhalle', 'archiv'],
            'garage'           => ['garage', 'tiefgarage', 'parkhaus'],
            'carport'          => ['carport'],
            'schuppen'         => ['schuppen', 'geraeteschuppen'],
            'terrasse'         => ['terrasse'],
            'balkon'           => ['balkon'],
            'garten'           => ['garten'],
            'treppe'           => ['treppe', 'treppenhaus'],
            'technik'          => ['technik', 'technikraum', 'hausanschlussraum', 'serverraum', 'edv'],
            'sauna'            => ['sauna'],
            'pool'             => ['pool', 'schwimmbad'],
            'gaestezimmer'     => ['gaestezimmer', 'gaeste'],
            'abstellraum'      => ['abstellraum', 'abstellkammer', 'nebenraum'],
            'umkleide'         => ['umkleide', 'umkleideraum'],
            'werkstatt'        => ['werkstatt', 'produktionshalle', 'fertigung'],
            'versand'          => ['versand', 'wareneingang', 'warenausgang'],
            'labor'            => ['labor'],
        ];

        $slug   = strtr(mb_strtolower($label), ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        $tokens = preg_split('/[^a-z0-9]+/', $slug, -1, PREG_SPLIT_NO_EMPTY);

        foreach ($synonyms as $type => $words) {
            if (array_intersect($tokens, $words)) {
                return $type;
            }
        }
        return null;
    }

    // -----------------------------------------------------------------
    // v0.4 Standesamt — Namenskonventions-Berater. Rein DESKRIPTIV: die
    // MEHRHEIT der bestehenden Namen bestimmt "die Konvention", nichts wird
    // fest codiert vorgegeben (SUITE.md-Grundregel "keine eigene Anlage als
    // Norm" — jede Installation hat andere Vorlieben). Arbeitet direkt auf
    // buildStructure(), keine eigene Konfiguration nötig. Umbenennen ist
    // eine echte Schreiboperation (IPS_SetName()), deshalb nur für vom
    // Nutzer angehakte Zeilen mit Korrekturvorschlag, nie automatisch.
    // -----------------------------------------------------------------

    public function RunNamingCheck(): string
    {
        $findings = $this->analyzeNamingConventions();
        $status   = $this->standesamtStatusText($findings);
        $this->UpdateFormField('StandesamtStatus', 'caption', $status);
        $this->UpdateFormField('NamingFindings', 'values', json_encode($this->namingFindingRows($findings), JSON_UNESCAPED_UNICODE));
        return $status;
    }

    public function ApplyNamingFixes(mixed $findings): string
    {
        $rows = $this->normalizeFormList($findings);
        if (!$rows) {
            return '⛔ Keine gültigen Daten übergeben.';
        }

        $applied = 0;
        foreach ($rows as $row) {
            if (empty($row['Anwenden']) || empty($row['Vorschlag']) || empty($row['ObjectID'])) {
                continue;
            }
            $objectID = (int) $row['ObjectID'];
            if (!IPS_ObjectExists($objectID)) {
                continue;
            }
            IPS_SetName($objectID, $row['Vorschlag']);
            $applied++;
        }

        // Nach Änderungen frisch neu analysieren — behobene Funde
        // verschwinden, sonst zeigt die Liste den jetzt falschen Altstand.
        $fresh = $this->analyzeNamingConventions();
        $this->UpdateFormField('StandesamtStatus', 'caption', $this->standesamtStatusText($fresh));
        $this->UpdateFormField('NamingFindings', 'values', json_encode($this->namingFindingRows($fresh), JSON_UNESCAPED_UNICODE));

        if ($applied === 0) {
            return 'ℹ️ Keine Zeile ausgewählt (oder keine hatte einen Korrekturvorschlag).';
        }
        return "✅ $applied Umbenennung(en) übernommen.";
    }

    private function analyzeNamingConventions(): array
    {
        $structure = $this->buildStructure();
        $entries   = [];
        foreach (array_merge($structure['levels'], $structure['rooms']) as $item) {
            $entries[] = [
                'categoryID' => $item['categoryID'],
                'label'      => $item['label'],
                'number'     => $item['number'],
            ];
        }

        return array_merge(
            $this->checkNumberPositionConsistency($entries),
            $this->checkCapitalizationConsistency($entries),
            $this->checkDuplicateLabels($entries),
            $this->checkCrypticLabels($entries)
        );
    }

    private function checkNumberPositionConsistency(array $entries): array
    {
        $withPos = [];
        foreach ($entries as $e) {
            $pos = $this->numberPosition($e['label']);
            if ($pos !== null) {
                $withPos[] = $e + ['position' => $pos];
            }
        }
        if (count($withPos) < 2) {
            return []; // zu wenig Datenbasis für eine Mehrheit
        }

        $counts = ['vorne' => 0, 'hinten' => 0];
        foreach ($withPos as $e) {
            $counts[$e['position']]++;
        }
        if ($counts['vorne'] === $counts['hinten']) {
            return []; // Patt, keine klare Mehrheit — nichts melden
        }
        $majority = $counts['vorne'] > $counts['hinten'] ? 'vorne' : 'hinten';

        $findings = [];
        foreach ($withPos as $e) {
            if ($e['position'] === $majority) {
                continue;
            }
            $findings[] = [
                'categoryID' => $e['categoryID'],
                'label'      => $e['label'],
                'suggestion' => $this->reformatNumberPosition($e['label'], $e['number'], $e['position'], $majority),
                'reason'     => "Zahlenposition weicht von der Mehrheit ab (die meisten haben die Zahl $majority).",
            ];
        }
        return $findings;
    }

    // Liefert 'vorne'/'hinten', wenn das Label eine Zahl am Anfang/Ende trägt,
    // sonst null (auch bei rein numerischen Labels — da ist "Position" nicht
    // sinnvoll definierbar, es gibt keinen Textrest).
    private function numberPosition(string $label): ?string
    {
        $label = trim($label);
        if ($label === '' || preg_match('/^\d+$/', $label) || preg_match('/^\d+\.\d+$/', $label)) {
            return null;
        }
        if (preg_match('/\d+$/', $label)) {
            return 'hinten';
        }
        if (preg_match('/^\d+/', $label)) {
            return 'vorne';
        }
        return null;
    }

    private function reformatNumberPosition(string $label, ?string $number, string $currentPosition, string $targetPosition): ?string
    {
        if ($number === null || $currentPosition === $targetPosition) {
            return null;
        }
        $rest = $currentPosition === 'vorne'
            ? preg_replace('/^' . preg_quote($number, '/') . '/', '', $label, 1)
            : preg_replace('/' . preg_quote($number, '/') . '$/', '', $label, 1);
        $rest = trim($rest, " .-_");
        if ($rest === '') {
            return null;
        }
        return $targetPosition === 'vorne' ? "$number $rest" : "$rest $number";
    }

    private function checkCapitalizationConsistency(array $entries): array
    {
        $withStyle = [];
        foreach ($entries as $e) {
            $style = $this->capitalizationStyle($e['label']);
            if ($style !== null) {
                $withStyle[] = $e + ['style' => $style];
            }
        }
        if (count($withStyle) < 2) {
            return [];
        }

        $counts = ['gross' => 0, 'klein' => 0];
        foreach ($withStyle as $e) {
            $counts[$e['style']]++;
        }
        if ($counts['gross'] === $counts['klein']) {
            return [];
        }
        $majority = $counts['gross'] > $counts['klein'] ? 'gross' : 'klein';

        $findings = [];
        foreach ($withStyle as $e) {
            if ($e['style'] === $majority) {
                continue;
            }
            $firstChar  = mb_substr($e['label'], 0, 1);
            $rest       = mb_substr($e['label'], 1);
            $suggestion = ($majority === 'gross' ? mb_strtoupper($firstChar) : mb_strtolower($firstChar)) . $rest;
            $findings[] = [
                'categoryID' => $e['categoryID'],
                'label'      => $e['label'],
                'suggestion' => $suggestion,
                'reason'     => "Groß-/Kleinschreibung weicht von der Mehrheit ab (die meisten beginnen $majority geschrieben).",
            ];
        }
        return $findings;
    }

    // 'gross'/'klein' je nach erstem Buchstaben, null wenn das Label gar
    // nicht mit einem Buchstaben beginnt (Ziffer/Sonderzeichen) — dort ist
    // keine Aussage über Groß-/Kleinschreibung möglich.
    private function capitalizationStyle(string $label): ?string
    {
        $first = mb_substr(trim($label), 0, 1);
        if ($first === '' || mb_strtoupper($first) === mb_strtolower($first)) {
            return null;
        }
        return $first === mb_strtoupper($first) ? 'gross' : 'klein';
    }

    private function checkDuplicateLabels(array $entries): array
    {
        $byLabel = [];
        foreach ($entries as $e) {
            $byLabel[$e['label']][] = $e;
        }

        $findings = [];
        foreach ($byLabel as $group) {
            if (count($group) < 2) {
                continue;
            }
            foreach ($group as $e) {
                $findings[] = [
                    'categoryID' => $e['categoryID'],
                    'label'      => $e['label'],
                    'suggestion' => null,
                    'reason'     => 'Name kommt mehrfach vor (' . count($group) . 'x) — bitte manuell prüfen, welches umbenannt werden soll.',
                ];
            }
        }
        return $findings;
    }

    private function checkCrypticLabels(array $entries): array
    {
        $findings = [];
        foreach ($entries as $e) {
            $label     = trim($e['label']);
            $isShort   = mb_strlen($label) <= 2;
            $isCryptic = (bool) preg_match('/^[A-Za-z]?\d+$/', $label);
            if (!$isShort && !$isCryptic) {
                continue;
            }
            $findings[] = [
                'categoryID' => $e['categoryID'],
                'label'      => $label,
                'suggestion' => null,
                'reason'     => 'Sehr kurzer/kryptischer Name — evtl. schwer wiederzuerkennen, keine automatische Empfehlung möglich.',
            ];
        }
        return $findings;
    }

    private function namingFindingRows(array $findings): array
    {
        $rows = [];
        foreach ($findings as $f) {
            $rows[] = [
                'Anwenden'  => false,
                'ObjectID'  => $f['categoryID'],
                'Objekt'    => IPS_ObjectExists($f['categoryID']) ? IPS_GetName($f['categoryID']) : $f['label'],
                'Aktuell'   => $f['label'],
                'Vorschlag' => $f['suggestion'] ?? '',
                'Grund'     => $f['reason'],
            ];
        }
        return $rows;
    }

    private function standesamtStatusText(array $findings): string
    {
        if (empty($findings)) {
            return '✅ Keine Auffälligkeiten gefunden.';
        }
        $n       = count($findings);
        $fixable = count(array_filter($findings, fn($f) => $f['suggestion'] !== null));
        return "ℹ️ $n Auffälligkeit" . ($n === 1 ? '' : 'en') . " gefunden, davon $fixable mit Korrekturvorschlag.";
    }

    // Sammelt die Geräte-Instanzen eines Raums: direkte Instanz-Kinder, Links
    // auf Instanzen, sowie Variablen (direkt oder per Link) — dort wird die
    // ELTERNINSTANZ der Variable aufgenommen (MeterHub-Fund 28.08.2026: ein
    // "Licht"-Link zeigt bei Dietmar direkt auf eine Schalter-Variable eines
    // Aktors, nicht auf dessen Instanz; ohne Auflösung ginge der Messpunkt
    // still verloren). Dedupliziert (doppelte Links auf dieselbe Instanz,
    // live an Dietmars Anlage beobachtet: Geschirrspüler 2x in der Küche
    // verlinkt) und filtert tote/namenlose Links (Ziel existiert nicht mehr
    // — IPS zeigt die dann als "Unnamed Object") sowie Variablen ohne
    // Instanz-Elternteil (kein sinnvolles Ziel, wird ignoriert).
    private function resolveRoomDevices(int $categoryID): array
    {
        $ids = [];
        foreach (IPS_GetChildrenIDs($categoryID) as $cid) {
            $obj = IPS_GetObject($cid);
            switch ($obj['ObjectType']) {
                case OBJECTTYPE_INSTANCE:
                    $ids[] = $cid;
                    break;
                case OBJECTTYPE_VARIABLE:
                    $this->addInstanceOfVariable($cid, $ids);
                    break;
                case OBJECTTYPE_LINK:
                    $target = IPS_GetLink($cid)['TargetID'];
                    if ($target <= 0 || !IPS_ObjectExists($target)) {
                        break; // toter/namenloser Link, bewusst übersprungen.
                    }
                    $targetObj = IPS_GetObject($target);
                    if ($targetObj['ObjectType'] === OBJECTTYPE_INSTANCE) {
                        $ids[] = $target;
                    } elseif ($targetObj['ObjectType'] === OBJECTTYPE_VARIABLE) {
                        $this->addInstanceOfVariable($target, $ids);
                    }
                    break;
            }
        }
        return array_values(array_unique($ids));
    }

    private function addInstanceOfVariable(int $variableID, array &$ids): void
    {
        $parent = IPS_GetParent($variableID);
        if ($parent > 0 && IPS_ObjectExists($parent) && IPS_GetObject($parent)['ObjectType'] === OBJECTTYPE_INSTANCE) {
            $ids[] = $parent;
        }
    }

    private function isCategory(int $id): bool
    {
        return IPS_ObjectExists($id) && IPS_GetObject($id)['ObjectType'] === OBJECTTYPE_CATEGORY;
    }

    // Liest die zuletzt gespeicherte Levels-Property als [CategoryID => IsLevel]-Map.
    private function levelFlags(): array
    {
        $rows = json_decode($this->ReadPropertyString('Levels'), true);
        if (!is_array($rows)) {
            return [];
        }
        $map = [];
        foreach ($rows as $row) {
            if (isset($row['CategoryID'])) {
                $map[(int) $row['CategoryID']] = !empty($row['IsLevel']);
            }
        }
        return $map;
    }

    // Deutscher Slug aus einem Kategorienamen (Umlaute umschreiben, Rest auf
    // [a-z0-9] reduzieren), innerhalb von $used eindeutig gemacht.
    private function uniqueKey(string $label, array &$used): string
    {
        $slug = $this->slugify($label, 'raum');

        $key = $slug;
        $n   = 2;
        while (in_array($key, $used, true)) {
            $key = $slug . '_' . $n;
            $n++;
        }
        $used[] = $key;

        return $key;
    }

    // Deutscher Slug (Umlaute umschreiben, Rest auf [a-z0-9] reduzieren) —
    // genutzt für den v0.1-Lesevertrag-Key (uniqueKey()) UND für die v0.2-
    // Baumeister-Idents (planSkeleton()).
    private function slugify(string $label, string $fallback): string
    {
        $slug = strtr(mb_strtolower($label), ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        $slug = preg_replace('/[^a-z0-9]+/', '_', $slug);
        $slug = trim($slug, '_');
        return $slug === '' ? $fallback : $slug;
    }
}
