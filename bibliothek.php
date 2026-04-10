<?php ob_start(); ?>
<!DOCTYPE html>
<html lang="de">
<head>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <link rel="stylesheet" href="style.css">
    <link rel="manifest" href="/webseite/manifest.json">
    <title>Meine Bibliothek</title>
</head>
</head>
<body>
<div class="container">
    <h1 style="margin-top:0;">📚 Bibliothek</h1>

    <?php
    require_once '../includes/db.php';

    // Auto-Update DB: Datentyp auf Kommazahlen ändern für halbe Sterne
    try {
        $pdo->exec("ALTER TABLE buecher MODIFY sterne DECIMAL(2,1) DEFAULT 0");
    } catch (Exception $e) {}

    // STERNE-BEWERTUNG AKTUALISIEREN (AJAX)
    if (isset($_POST['set_sterne']) && isset($_POST['id'])) {
        $stmt = $pdo->prepare("UPDATE buecher SET sterne = :sterne WHERE id = :id");
        $stmt->execute([':sterne' => (float)$_POST['set_sterne'], ':id' => (int)$_POST['id']]);
        exit;
    }

    // GELESEN-STATUS AKTUALISIEREN (AJAX)
    if (isset($_POST['toggle_gelesen']) && isset($_POST['id'])) {
        $stmt = $pdo->prepare("UPDATE buecher SET gelesen = :gelesen WHERE id = :id");
        $stmt->execute([':gelesen' => $_POST['toggle_gelesen'], ':id' => $_POST['id']]);
        exit; // Hier abbrechen, da es eine Hintergrund-Anfrage ist
    }

    // LÖSCHEN
    if (isset($_GET['delete_id'])) {
        $delStmt = $pdo->prepare("DELETE FROM buecher WHERE id = :id");
        $delStmt->execute([':id' => $_GET['delete_id']]);
    }

    // SPEICHERN
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['titel'])) {
        $sql  = "INSERT INTO buecher (isbn, titel, autor, erscheinungsjahr, genre, cover_url, gelesen, is_ebook) 
                 VALUES (:isbn, :titel, :autor, :jahr, :genre, :cover_url, :gelesen, :is_ebook)";
        $stmt = $pdo->prepare($sql);
        try {
            $stmt->execute([
                ':isbn'      => empty($_POST['isbn'])      ? null : $_POST['isbn'],
                ':titel'     => $_POST['titel'],
                ':autor'     => empty($_POST['autor'])     ? null : $_POST['autor'],
                ':jahr'      => empty($_POST['jahr'])      ? null : $_POST['jahr'],
                ':genre'     => empty($_POST['genre'])     ? null : $_POST['genre'],
                ':cover_url' => empty($_POST['cover_url']) ? null : $_POST['cover_url'],
                ':gelesen'   => isset($_POST['gelesen'])   ? 1 : 0,
                ':is_ebook'  => isset($_POST['is_ebook'])  ? 1 : 0 // NEU
            ]);
            // Nach erfolgreichem Speichern: Weiterleitung auf GET 
            header('Location: bibliothek.php?success=' . urlencode($_POST['titel']));
            exit;
        } catch (\PDOException $e) {
            if ($e->getCode() == 23000) {
                $errorMsg = "Dieses Buch existiert bereits!";
            } else {
                $errorMsg = $e->getMessage();
            }
        }
    }

    // STATISTIKEN BERECHNEN (Nur Status 1 zählt als "Gelesen")
    $statQuery = $pdo->query("SELECT COUNT(*) as total, SUM(IF(gelesen = 1, 1, 0)) as gelesen FROM buecher");
    $stats = $statQuery->fetch(PDO::FETCH_ASSOC);

    $totalBooks  = (int)$stats['total'];
    $readBooks   = (int)$stats['gelesen']; // Zählt nur Bücher, die exakt auf 1 stehen
    $unreadBooks = $totalBooks - $readBooks; // Alles andere (0, 2, 3) landet hier

    $readPercent   = $totalBooks > 0 ? round(($readBooks / $totalBooks) * 100) : 0;
    $unreadPercent = $totalBooks > 0 ? round(($unreadBooks / $totalBooks) * 100) : 0;
    ?>

    <!-- Dashboard Statistiken -->
    <div class="stats-dashboard" id="statsDashboard" onclick="toggleStatsMode()" style="cursor: pointer;" title="Klicken für %-Ansicht">
        <div class="stat-box">
            <span class="stat-number" id="stat-total" data-value="<?php echo $totalBooks; ?>"><?php echo $totalBooks; ?></span>
            <span class="stat-label">Gesamt</span>
        </div>
        <div class="stat-box">
            <span class="stat-number" id="stat-read" style="color: #34c759;" data-value="<?php echo $readBooks; ?>" data-percent="<?php echo $readPercent; ?>%">
                <?php echo $readBooks; ?>
            </span>
            <span class="stat-label">Gelesen</span>
        </div>
        <div class="stat-box">
            <span class="stat-number" id="stat-unread" style="color: #ff3b30;" data-value="<?php echo $unreadBooks; ?>" data-percent="<?php echo $unreadPercent; ?>%">
                <?php echo $unreadBooks; ?>
            </span>
            <span class="stat-label">Ungelesen</span>
        </div>
    </div>

    <!-- Neuer Button, der immer sichtbar ist -->
    <button class="open-modal-btn" onclick="openAddModal()">➕ Neues Buch hinzufügen</button>

    <!-- Das unsichtbare Modal-Fenster -->
    <div id="addModal" class="modal-overlay">
        <div class="modal-content">
            <button class="close-modal-btn" onclick="closeAddModal()">✕</button>
            <h2 style="margin-top: 0;">Neues Buch scannen oder suchen</h2>
            
            <div class="top-section" style="margin-bottom: 0;">
                <div id="reader"></div>

                <form action="bibliothek.php" method="POST" class="form-box">
                    <input type="hidden" name="cover_url" id="cover_url">
                    <div id="cover_preview" style="text-align: center; min-height: 80px; margin-bottom: 10px; color: gray; display: flex; align-items: center; justify-content: center;">
                    </div>

                    <label>Titel *</label>
                    <input type="text" name="titel" id="titel" required oninput="checkManualInput()">
                    
                    <label>Autor</label>
                    <input type="text" name="autor" id="autor" oninput="checkManualInput()">
                    <label>ISBN</label>
                    <input type="text" name="isbn" id="isbn" oninput="checkManualInput()">
                    <label>Genre</label>
                    <input type="text" name="genre" id="genre">
                    <label>Jahr</label>
                    <input type="number" name="jahr" id="jahr" oninput="checkManualInput()">

                    <button type="button" id="btnOnlineSearch" class="search-title-btn" onclick="searchByTitleAPI()" style="display: none;">
                        Online suchen
                    </button>

                    <div style="display: flex; flex-direction: column; gap: 10px; margin-top: 15px;">
                        <label style="font-size: 18px; margin: 0;">
                            <input type="checkbox" name="gelesen" value="1" style="transform: scale(1.4); margin-right: 8px;">
                            Schon gelesen?
                        </label>
                        <!-- NEUE E-BOOK CHECKBOX -->
                        <label style="font-size: 18px; margin: 0;">
                            <input type="checkbox" name="is_ebook" value="1" style="transform: scale(1.4); margin-right: 8px;">
                            Als E-Book speichern
                        </label>
                    </div>

                    <button type="submit" class="save-btn">💾 Buch speichern</button>
                </form>
            </div>
            
            <!-- Modal für API-Suchergebnisse -->
            <div id="searchResults" style="display: none; margin-top: 20px; background: #fff; padding: 15px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
                <h3>Suchergebnisse (Klicken zum Auswählen)</h3>
                <div id="searchResultsList" style="display: flex; flex-direction: column; gap: 10px; max-height: 300px; overflow-y: auto;"></div>
            </div>
        </div>
    </div>

    <!-- Such-, Sortier- und Filterleiste -->
    <div class="filter-bar" style="margin-bottom: 20px;">
        <input type="text" id="searchInput" class="search-input" placeholder="🔍 Suchen nach Titel oder Autor..." onkeyup="searchBooks()" style="margin-bottom: 0; width: 100%;">
    </div>

    <?php
    // 1. Sortierung dynamisch auslesen
    $allowedFields = ['hinzugefuegt', 'titel', 'autor', 'erscheinungsjahr'];
    $allowedDirs = ['asc', 'desc'];

    $sortField = $_GET['sort_field'] ?? 'hinzugefuegt';
    $sortDir = $_GET['sort_dir'] ?? 'desc';

    if (!in_array($sortField, $allowedFields)) $sortField = 'hinzugefuegt';
    if (!in_array($sortDir, $allowedDirs)) $sortDir = 'desc';

    $dbField = ($sortField === 'hinzugefuegt') ? 'hinzugefuegt_am' : $sortField;

    // 2. Bücher aus der Datenbank holen
    $stmt    = $pdo->query("SELECT * FROM buecher ORDER BY $dbField $sortDir");
    $buecher = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($buecher) > 0) {
        
        // Alle einzigartigen Genres sammeln
        $unique_genres = [];
        foreach ($buecher as $buch) {
            $g = trim($buch['genre'] ?? '');
            if ($g !== '' && !in_array($g, $unique_genres)) {
                $unique_genres[] = $g;
            }
        }
        sort($unique_genres);

        // Filter-Buttons (Jetzt als Toggle-Multi-Select)
        echo "<div class='genre-scroller' id='genreScroller'>";
        
        // E-Book & Print Filter
        echo "<button class='genre-btn toggle-filter' data-filter-type='type' data-filter-val='print' onclick=\"toggleFilter(this)\">Print</button>";
        echo "<button class='genre-btn toggle-filter' data-filter-type='type' data-filter-val='ebook' onclick=\"toggleFilter(this)\">E-Book</button>";
        
        // Status Filter
        echo "<button class='genre-btn toggle-filter' data-filter-type='status' data-filter-val='0' onclick=\"toggleFilter(this)\">Ungelesen</button>";
        echo "<button class='genre-btn toggle-filter' data-filter-type='status' data-filter-val='1' onclick=\"toggleFilter(this)\">Gelesen</button>";
        echo "<button class='genre-btn toggle-filter' data-filter-type='status' data-filter-val='2' onclick=\"toggleFilter(this)\">DNF</button>";
        echo "<button class='genre-btn toggle-filter' data-filter-type='status' data-filter-val='3' onclick=\"toggleFilter(this)\">Revisit</button>";
        
        // Genre Filter
        foreach($unique_genres as $g) {
            echo "<button class='genre-btn toggle-filter' data-filter-type='genre' data-filter-val='" . htmlspecialchars($g, ENT_QUOTES) . "' onclick=\"toggleFilter(this)\">" . htmlspecialchars($g) . "</button>";
        }
        echo "<button class='genre-btn toggle-filter' data-filter-type='genre' data-filter-val='none' onclick=\"toggleFilter(this)\">Ohne Genre</button>";
        echo "</div>";

        // Ultra-kompakte Leiste für Sterne-Filter und Sortierung
        $currentSortField = $_GET['sort_field'] ?? 'hinzugefuegt';
        $currentSortDir   = $_GET['sort_dir'] ?? 'desc';
        $nextDir          = ($currentSortDir === 'asc') ? 'desc' : 'asc';
        $arrowSymbol      = ($currentSortDir === 'asc') ? '↑' : '↓';

        echo "<div style='display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; flex-wrap: nowrap; gap: 8px;'>";
        
        // LINKS: Sterne-Filter
        echo "<select id='starFilter' class='search-input' style='margin-bottom: 0; padding: 6px 8px; font-size: 13px; flex: 1; max-width: 170px;' onchange='searchBooks()'>";
        echo "<option value='0'>⭐ Alle</option>";
        echo "<option value='5'>⭐⭐⭐⭐⭐</option>";
        echo "<option value='4'>⭐⭐⭐⭐</option>";
        echo "<option value='3'>⭐⭐⭐</option>";
        echo "<option value='unbewertet'>Keine ⭐</option>";
        echo "</select>";

        // RECHTS: Sortier-Kriterium 
        echo "<form action='bibliothek.php' method='GET' style='margin: 0; display: flex; align-items: center; gap: 4px; flex: 1; justify-content: flex-end;'>";
        echo "<select name='sort_field' class='search-input' style='margin-bottom: 0; padding: 6px 8px; font-size: 13px; max-width: 140px;' onchange='this.form.submit()'>";
        echo "<option value='hinzugefuegt' " . ($currentSortField === 'hinzugefuegt' ? 'selected' : '') . ">Neu hinzugefügt</option>";
        echo "<option value='titel' " . ($currentSortField === 'titel' ? 'selected' : '') . ">Titel</option>";
        echo "<option value='autor' " . ($currentSortField === 'autor' ? 'selected' : '') . ">Autor</option>";
        echo "<option value='erscheinungsjahr' " . ($currentSortField === 'erscheinungsjahr' ? 'selected' : '') . ">Erscheinungsjahr</option>";
        echo "</select>";
        echo "<input type='hidden' name='sort_dir' value='$currentSortDir'>";
        echo "<button type='submit' name='sort_dir' value='$nextDir' class='genre-btn' style='margin: 0; padding: 6px 0; width: 34px; text-align: center; font-size: 14px; flex-shrink: 0;'>$arrowSymbol</button>";
        echo "</form>";
        echo "</div>";

        // 3. Die Bücherliste ausgeben
        echo "<div class='book-list'>";

            foreach ($buecher as $buch) {
    $safeGenre = htmlspecialchars(trim($buch['genre'] ?? ''), ENT_QUOTES);
    $currentState = isset($buch['gelesen']) ? (int)$buch['gelesen'] : 0;
    $sterne = isset($buch['sterne']) ? (float)$buch['sterne'] : 0;
    $isEbook = isset($buch['isebook']) ? (int)$buch['isebook'] : 0;
    $extraClass = $isEbook ? ' ebook-card' : '';
    
    // NEU HINZUFÜGEN:
    $safeIsbn = htmlspecialchars(trim($buch['isbn'] ?? ''), ENT_QUOTES);
    $safeJahr = htmlspecialchars(trim($buch['erscheinungsjahr'] ?? ''), ENT_QUOTES);

    // ZEILE ÄNDERN (Die beiden neuen data-Attribute hinten ergänzen):
    echo '<div class="book-card'.$extraClass.'" data-genre="'.$safeGenre.'" data-gelesen="'.$currentState.'" data-sterne="'.$sterne.'" data-ebook="'.$isEbook.'" data-isbn="'.$safeIsbn.'" data-jahr="'.$safeJahr.'">';

            // 1. Cover (Links)
            echo "<div class='book-cover-wrapper'>";
            if (!empty($buch['cover_url'])) {
                $safeUrl = str_replace('http://', 'https://', $buch['cover_url']);
                echo "<img src='" . htmlspecialchars($safeUrl) . "' class='book-cover' alt='Cover'>";
            } else {
                echo "<div class='no-cover'>Kein Bild</div>";
            }
            echo "</div>";

            // 2. Info-Bereich (Mitte)
            echo "<div class='book-info'>";
                echo "<div class='book-header'>";
                    echo "<div class='book-title'>" . htmlspecialchars($buch['titel']) . "</div>";
                    if (!empty($buch['autor'])) {
                        echo "<div class='book-autor'>" . htmlspecialchars($buch['autor']) . "</div>";
                    }
                echo "</div>";

                // WIEDER ORIGINAL LINKSBÜNDIG: Keine E-Book Text-Markierung mehr
                echo "<div class='book-meta'>";
                    if (!empty($buch['genre'])) {
                        echo "<span class='book-genre'>" . htmlspecialchars($buch['genre']) . "</span>";
                    }
                echo "</div>";

                // Die Sterne-Anzeige
                echo "<div class='book-stars' style='margin-top: 8px; font-size: 24px; letter-spacing: 2px;'>";
                for ($i = 1; $i <= 5; $i++) {
                    $starClass = 'star';
                    if ($sterne >= $i) {
                        $starClass = 'star filled';
                    } elseif ($sterne >= ($i - 0.5)) {
                        $starClass = 'star half-filled';
                    }
                    echo "<span class='$starClass' style='cursor:pointer;' onclick='rateBook(" . $buch['id'] . ", $i, this, event)'>★</span>";
                }
                echo "</div>";

            echo "</div>";

            // 3. Aktionen (Rechts: Löschen oben, Status unten)
            echo "<div class='book-actions'>";
            
                // Löschen-Button
                echo "<form action='bibliothek.php' method='GET' onsubmit=\"return confirm('Wirklich löschen?');\" style='margin:0; display:block;'>
                        <input type='hidden' name='delete_id' value='" . $buch['id'] . "'>
                        <button type='submit' class='action-btn delete-btn' title='Buch löschen'>🗑</button>
                    </form>";

                // Dropdown Lese-Status-Button 
                if ($currentState === 1) { $bgClass = "read-btn read"; $icon = "📖"; }
                elseif ($currentState === 2) { $bgClass = "read-btn dnf"; $icon = "🛑"; }
                elseif ($currentState === 3) { $bgClass = "read-btn revisit"; $icon = "⏳"; }
                else { $bgClass = "read-btn unread"; $icon = "📘"; }

                echo "<div class='status-dropdown-wrapper' title='Status ändern'>";
                    echo "<div class='status-display $bgClass'>$icon</div>";
                    echo "<select class='status-select' onchange='changeStatus(" . $buch['id'] . ", this)'>";
                        echo "<option value='0' " . ($currentState === 0 ? 'selected' : '') . ">Ungelesen</option>";
                        echo "<option value='1' " . ($currentState === 1 ? 'selected' : '') . ">Gelesen</option>";
                        echo "<option value='2' " . ($currentState === 2 ? 'selected' : '') . ">Abgebrochen</option>";
                        echo "<option value='3' " . ($currentState === 3 ? 'selected' : '') . ">Pausiert</option>";
                    echo "</select>";
                echo "</div>";

            echo "</div>";

            echo "</div>"; // Ende book-card
        }
        echo "</div>"; // Ende book-list
    } else {
        echo "<p style='text-align:center; color: gray;'>Noch keine Bücher in der Datenbank.</p>";
    }
    ?>
</div>

<script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>
<script>
    let isScanning = false;

    // Prüft, ob der Nutzer gerade selbst tippt
    function checkManualInput() {
    let titelText = document.getElementById('titel').value.trim();
    let autorText = document.getElementById('autor').value.trim();
    let isbnText = document.getElementById('isbn').value.trim();
    let jahrText = document.getElementById('jahr').value.trim();
    
    let searchBtn = document.getElementById('btnOnlineSearch');
    
    // Button zeigen, sobald IRGENDEIN Feld (Titel, Autor, ISBN oder Jahr) ausgefüllt ist
    if (titelText.length > 0 || autorText.length > 0 || isbnText.length > 0 || jahrText.length > 0) {
        searchBtn.style.display = 'block';
    } else {
        searchBtn.style.display = 'none';
    }
    }

    function fillForm(title, author, year, cover, genre, isbn = '') {
        document.getElementById('titel').value     = title  || '';
        document.getElementById('autor').value     = author || '';
        document.getElementById('genre').value     = genre  || '';
        document.getElementById('jahr').value      = year   || '';
        document.getElementById('cover_url').value = cover  || '';
        if(isbn) document.getElementById('isbn').value = isbn;

        let previewDiv = document.getElementById('cover_preview');
        if (cover) {
            previewDiv.innerHTML = "<img src='" + cover + "' style='height: 100px; border-radius: 5px; box-shadow: 0 2px 5px rgba(0,0,0,0.2);' onerror='this.style.display=\"none\"; this.parentElement.innerHTML=\"<span style=\\\"color:orange\\\">Kein Cover verfügbar</span>\";'>";
        } else {
            previewDiv.innerHTML = "<span style='color:orange'>Kein Cover gefunden</span>";
        }
        isScanning = false;
        document.getElementById('searchResults').style.display = 'none';

                // Beim automatischen Ausfüllen durch den Scanner den Online-Suchen Button verstecken
        let searchBtn = document.getElementById('btnOnlineSearch');
        if (searchBtn) searchBtn.style.display = 'none';
    }

    // --- NEUE TITELSUCHE API ---
    function searchByTitleAPI() {
    let titel = document.getElementById('titel').value.trim();
    let autor = document.getElementById('autor').value.trim();
    let isbn = document.getElementById('isbn').value.trim();
    let jahr = document.getElementById('jahr').value.trim();
    
    // Alle Felder sammeln, die ausgefüllt wurden
    let queryParts = [];
    if (titel) queryParts.push(titel);
    if (autor) queryParts.push(autor);
    if (isbn) queryParts.push(isbn);
    if (jahr) queryParts.push(jahr);
    
    let query = queryParts.join(' '); // Alles mit Leerzeichen verbinden

    if (!query) {
        alert('Bitte Suchbegriffe eingeben!');
        return;
    }

    let resDiv = document.getElementById('searchResults');
    let resList = document.getElementById('searchResultsList');
        resList.innerHTML = "<em>Suche läuft...</em>";
        resDiv.style.display = 'block';

        fetch('search.php?q=' + encodeURIComponent(query))
            .then(res => res.json())
            .then(data => {
                resList.innerHTML = '';
                if(data.type === 'list' && data.results && data.results.length > 0) {
                    data.results.forEach(b => {
                        let div = document.createElement('div');
                        div.className = 'search-result-item';
                        div.innerHTML = `
                            <img src="${b.cover || ''}" style="width:40px; height:60px; object-fit:cover; background:#eee; border-radius:3px;">
                            <div style="flex:1;">
                                <div style="font-weight:bold;">${b.title}</div>
                                <div style="font-size:12px; color:#555;">${b.author} (${b.year})</div>
                            </div>
                        `;
                        div.onclick = function() { fillForm(b.title, b.author, b.year, b.cover, b.genre, b.isbn); };
                        resList.appendChild(div);
                    });
                } else {
                    resList.innerHTML = "<span style='color:red;'>Keine Ergebnisse gefunden.</span>";
                }
            }).catch(err => {
                resList.innerHTML = "<span style='color:red;'>Fehler bei der Suche.</span>";
            });
    }

    function changeStatus(id, selectElement) {
        let newState = parseInt(selectElement.value);
        let displayElement = selectElement.previousElementSibling;
        let card = selectElement.closest('.book-card');
        let currentState = parseInt(card.getAttribute('data-gelesen')) || 0;
        
        let formData = new FormData();
        formData.append('toggle_gelesen', newState);
        formData.append('id', id);

        fetch('bibliothek.php', { method: 'POST', body: formData })
        .then(response => {
            if(response.ok) {
                // Icon Farbe
                if (newState === 1) { displayElement.className = 'status-display read-btn read'; displayElement.innerHTML = '📖'; }
                else if (newState === 2) { displayElement.className = 'status-display read-btn dnf'; displayElement.innerHTML = '🛑'; }
                else if (newState === 3) { displayElement.className = 'status-display read-btn revisit'; displayElement.innerHTML = '⏳'; }
                else { displayElement.className = 'status-display read-btn unread'; displayElement.innerHTML = '📘'; }

                if (card) {
                    card.setAttribute('data-gelesen', newState);
                    searchBooks(); // Filter neu anwenden
                }

                // Statistik updaten
                let totalElem = document.getElementById('stat-total');
                let readElem = document.getElementById('stat-read');
                let unreadElem = document.getElementById('stat-unread');

                if (readElem && unreadElem && totalElem) {
                    let total = parseInt(totalElem.getAttribute('data-value')) || 0;
                    let currentRead = parseInt(readElem.getAttribute('data-value')) || 0;
                    let currentUnread = parseInt(unreadElem.getAttribute('data-value')) || 0;

                    let wasReadBefore = (currentState === 1);
                    let isReadNow = (newState === 1);

                    if (wasReadBefore && !isReadNow) { currentRead--; currentUnread++; }
                    else if (!wasReadBefore && isReadNow) { currentRead++; currentUnread--; }

                    let newReadPercent = total > 0 ? Math.round((currentRead / total) * 100) : 0;
                    let newUnreadPercent = total > 0 ? Math.round((currentUnread / total) * 100) : 0;

                    readElem.setAttribute('data-value', currentRead);
                    unreadElem.setAttribute('data-value', currentUnread);
                    readElem.setAttribute('data-percent', newReadPercent + '%');
                    unreadElem.setAttribute('data-percent', newUnreadPercent + '%');

                    if (typeof isPercentMode !== 'undefined' && isPercentMode) {
                        readElem.innerText = newReadPercent + '%';
                        unreadElem.innerText = newUnreadPercent + '%';
                    } else {
                        readElem.innerText = currentRead;
                        unreadElem.innerText = currentUnread;
                    }
                }
            }
        });
    }

    let isPercentMode = false;
    function toggleStatsMode() {
        isPercentMode = !isPercentMode; 
        let readElem = document.getElementById('stat-read');
        let unreadElem = document.getElementById('stat-unread');

        readElem.style.opacity = '0';
        unreadElem.style.opacity = '0';
        readElem.style.transform = 'scale(0.95)';
        unreadElem.style.transform = 'scale(0.95)';

        setTimeout(() => {
            if (isPercentMode) {
                readElem.innerText = readElem.getAttribute('data-percent');
                unreadElem.innerText = unreadElem.getAttribute('data-percent');
            } else {
                readElem.innerText = readElem.getAttribute('data-value');
                unreadElem.innerText = unreadElem.getAttribute('data-value');
            }
            readElem.style.opacity = '1';
            unreadElem.style.opacity = '1';
            readElem.style.transform = 'scale(1)';
            unreadElem.style.transform = 'scale(1)';
        }, 200);
    }

    // --- NEUE FILTER-LOGIK (Multi-Select) ---
    function toggleFilter(btnElement) {
        btnElement.classList.toggle('active');
        searchBooks(); // Ruft unsere kombinierte Suchfunktion auf
    }

    function searchBooks() {
        let query = document.getElementById('searchInput').value.toLowerCase();
        let cards = document.querySelectorAll('.book-card');
        let starFilterValue = document.getElementById('starFilter').value;
        let activeBtns = document.querySelectorAll('.toggle-filter.active');
        
        let filters = { types: [], status: [], genres: [] };
        activeBtns.forEach(btn => {
            let fType = btn.getAttribute('data-filter-type');
            let fVal = btn.getAttribute('data-filter-val');
            if (fType === 'type') filters.types.push(fVal);
            if (fType === 'status') filters.status.push(fVal);
            if (fType === 'genre') filters.genres.push(fVal);
        });

        cards.forEach(card => {
            let titleElement = card.querySelector('.book-title');
            let authorElement = card.querySelector('.book-autor');
            let title = titleElement ? titleElement.innerText.toLowerCase() : '';
            let author = authorElement ? authorElement.innerText.toLowerCase() : '';
            
            let cardGenre = card.getAttribute('data-genre');
            let cardGelesen = card.getAttribute('data-gelesen'); // ist hier 0, 1, 2 oder 3
            let cardSterne = parseFloat(card.getAttribute('data-sterne')) || 0;
            let cardEbook = card.getAttribute('data-ebook');

            let isbn = card.getAttribute('data-isbn') || "";
            let jahr = card.getAttribute('data-jahr') || "";

            // 1. Text-Suche
            let matchesSearch = title.includes(query) || author.includes(query) || isbn.includes(query) || jahr.includes(query);

            // 2. Sterne
            let matchesStars = true;
            if (starFilterValue === '5') matchesStars = (cardSterne === 5);
            else if (starFilterValue === '4') matchesStars = (cardSterne >= 4); // Du hast die Werte in HTML als fest gesetzt, hier passen wir es auf genau oder größer an.
            else if (starFilterValue === '3') matchesStars = (cardSterne >= 3);
            else if (starFilterValue === 'unbewertet') matchesStars = (cardSterne === 0);

            // 3. Typ (E-Book/Print)
            let matchType = filters.types.length === 0 || 
                            (filters.types.includes('ebook') && cardEbook === '1') || 
                            (filters.types.includes('print') && cardEbook === '0');

            // 4. Status
            let matchStatus = filters.status.length === 0 || filters.status.includes(cardGelesen);

            // 5. Genre
            let matchGenre = filters.genres.length === 0 || 
                             filters.genres.includes(cardGenre) || 
                             (filters.genres.includes('none') && cardGenre === '');

            if (matchesSearch && matchesStars && matchType && matchStatus && matchGenre) {
                card.style.display = 'flex';
            } else {
                card.style.display = 'none';
            }
        });
    }

    function tryOpenLibrary(isbn, fallbackCover) {
        fetch("https://openlibrary.org/api/books?bibkeys=ISBN:" + isbn + "&format=json&jscmd=data")
            .then(res => res.json())
            .then(data => {
                let key = "ISBN:" + isbn;
                if (data[key]) {
                    let b = data[key];
                    let cover = (b.cover && b.cover.medium) ? b.cover.medium : fallbackCover;
                    fillForm(b.title || '', (b.authors && b.authors.length > 0) ? b.authors[0].name : '', (b.publish_date ? b.publish_date.match(/\d{4}/)?.[0] : ''), cover, (b.subjects && b.subjects.length > 0) ? b.subjects[0].name : '');
                } else {
                    document.getElementById('cover_preview').innerHTML = "<span style='color:red'>Nicht gefunden. Bitte manuell eintippen!</span>";
                    document.getElementById('cover_url').value = fallbackCover;
                    isScanning = false;
                }
            }).catch(() => { isScanning = false; });
    }

    function onScanSuccess(decodedText) {
        if (isScanning) return;
        isScanning = true;
        let cleanIsbn = decodedText.replace(/\D/g, '');
        document.getElementById('isbn').value = cleanIsbn;
        html5QrcodeScanner.clear();
        document.getElementById('cover_preview').innerHTML = "<span style='color:blue'>Suche Buch...</span>";

        fetch('search.php?isbn=' + cleanIsbn)
            .then(res => res.json())
            .then(data => {
                if (data.source === 'google') fillForm(data.title, data.author, data.year, data.cover, data.genre, cleanIsbn);
                else tryOpenLibrary(cleanIsbn, data.cover);
            }).catch(err => { isScanning = false; });
    }

    let html5QrcodeScanner = null;
    function openAddModal() {
        document.getElementById('addModal').style.display = 'flex';
        if (!html5QrcodeScanner) {
            html5QrcodeScanner = new Html5QrcodeScanner("reader", { fps: 10, qrbox: {width: 250, height: 100} }, false);
            html5QrcodeScanner.render(onScanSuccess);
        }
    }
    function closeAddModal() {
        document.getElementById('addModal').style.display = 'none';
        if (html5QrcodeScanner) { html5QrcodeScanner.clear().then(() => { html5QrcodeScanner = null; }); }
    }

    function rateBook(id, starIndex, element, event) {
        let rect = element.getBoundingClientRect();
        let isHalf = (event.clientX - rect.left) < (rect.width / 2);
        let rating = isHalf ? (starIndex - 0.5) : starIndex;
        
        let formData = new FormData();
        formData.append('set_sterne', rating);
        formData.append('id', id);

        fetch('bibliothek.php', { method: 'POST', body: formData })
        .then(response => {
            if(response.ok) {
                let starsContainer = element.parentElement;
                let starElements = starsContainer.querySelectorAll('.star');
                starElements.forEach((star, index) => {
                    let currentI = index + 1;
                    star.className = 'star'; 
                    if (rating >= currentI) star.classList.add('filled');
                    else if (rating >= (currentI - 0.5)) star.classList.add('half-filled');
                });
                let card = starsContainer.closest('.book-card');
                card.setAttribute('data-sterne', rating);
                searchBooks(); 
            }
        });
    }
</script>
</body>

   <?php include '../includes/nav.php'; ?>

</html>