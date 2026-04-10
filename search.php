<?php
ob_start();
error_reporting(0);
header('Content-Type: application/json');

function curlGet($url) {
    $agents = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.3 Safari/605.1.15',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:123.0) Gecko/20100101 Firefox/123.0'
    ];
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    // Zufälliger Browser-Agent um Google-Sperren zu umgehen
    curl_setopt($ch, CURLOPT_USERAGENT, $agents[array_rand($agents)]);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['body' => $body, 'code' => $code];
}

function normalizeGenre($raw) {
    if (empty($raw)) return '';
    $raw = mb_strtolower(trim($raw));
    $map = [
        'science fiction' => 'Science-Fiction', 'science-fiction' => 'Science-Fiction', 'sci-fi' => 'Science-Fiction',
        'fantasy' => 'Fantasy', 
        'thriller' => 'Thriller', 'spannung' => 'Thriller', 
        'mystery' => 'Krimi', 'crime' => 'Krimi', 'krimi' => 'Krimi', 'kriminalroman' => 'Krimi', 'detective' => 'Krimi',
        'horror' => 'Horror', 
        'fiction' => 'Roman', 'roman' => 'Roman', 'belletristik' => 'Roman', 'novel' => 'Roman',
        'nonfiction' => 'Sachbuch', 'biography' => 'Sachbuch', 'history' => 'Sachbuch', 'sachbuch' => 'Sachbuch', 'ratgeber' => 'Sachbuch',
        'romance' => 'Romantik', 'love' => 'Romantik', 'liebesroman' => 'Romantik', 
        'young adult' => 'Jugendbuch', 'jugendbuch' => 'Jugendbuch', 
        'children' => 'Kinderbuch', 'kinderbuch' => 'Kinderbuch'
    ];
    foreach ($map as $keyword => $normalized) {
        if (str_contains($raw, $keyword)) return $normalized;
    }
    return '';
}

$isbn = preg_replace('/\D/', '', $_GET['isbn'] ?? '');
$query = trim($_GET['q'] ?? '');

if (empty($isbn) && empty($query)) {
    ob_end_clean();
    echo json_encode(['error' => 'Keine Suchkriterien']);
    exit;
}

// ==========================================
// TITEL-SUCHE (Mehrere Ergebnisse)
// ==========================================
if (!empty($query)) {
    $results = [];
    
    // VERSUCH 1: Google Books API
    $searchUrl = "https://www.googleapis.com/books/v1/volumes?q=" . urlencode($query) . "&maxResults=5";
    $google = curlGet($searchUrl);
    
    if ($google['code'] === 200) {
        $googleData = json_decode($google['body'], true);
        if (!empty($googleData['items'])) {
            foreach ($googleData['items'] as $item) {
                $b = $item['volumeInfo'];
                $foundIsbn = '';
                if (!empty($b['industryIdentifiers'])) {
                    foreach ($b['industryIdentifiers'] as $id) {
                        if ($id['type'] === 'ISBN_13') { $foundIsbn = $id['identifier']; break; }
                        if ($id['type'] === 'ISBN_10' && empty($foundIsbn)) { $foundIsbn = $id['identifier']; }
                    }
                }
                $results[] = [
                    'title' => $b['title'] ?? '',
                    'author' => $b['authors'][0] ?? '',
                    'year' => isset($b['publishedDate']) ? substr($b['publishedDate'], 0, 4) : '',
                    'genre' => !empty($b['categories']) ? normalizeGenre($b['categories'][0]) : '',
                    'cover' => str_replace('http://', 'https://', $b['imageLinks']['thumbnail'] ?? ''),
                    'isbn' => $foundIsbn
                ];
            }
        }
    }
    
    // VERSUCH 2: Deutsches Bibliotheks-Zentrum (Lobid) - Perfekt für deutsche Bücher & ISBNs
    if (empty($results)) {
        $lobidUrl = "https://lobid.org/resources/search?q=" . urlencode($query) . "&filter=type:Book&size=5&format=json";
        $lobid = curlGet($lobidUrl);
        
        if ($lobid['code'] === 200) {
            $lobidData = json_decode($lobid['body'], true);
            if (!empty($lobidData['member'])) {
                foreach ($lobidData['member'] as $item) {
                    $title = $item['title'] ?? '';
                    
                    // Autor finden
                    $author = '';
                    if (!empty($item['contribution'])) {
                        foreach ($item['contribution'] as $contrib) {
                            if (!empty($contrib['agent']['label'])) { $author = $contrib['agent']['label']; break; }
                        }
                    }
                    
                    // Jahr finden
                    $year = '';
                    if (!empty($item['publication'][0]['startDate'])) {
                        $year = substr($item['publication'][0]['startDate'], 0, 4);
                    }
                    
                    // ISBN sicher extrahieren
                    $foundIsbn = '';
                    if (!empty($item['isbn'])) {
                        foreach ((array)$item['isbn'] as $i) {
                            $clean = preg_replace('/\D/', '', $i);
                            if (strlen($clean) === 13) { $foundIsbn = $clean; break; }
                            if (strlen($clean) === 10 && empty($foundIsbn)) { $foundIsbn = $clean; }
                        }
                    }
                    
                    // Genre (Subjects) finden
                    $genre = '';
                    if (!empty($item['subject'])) {
                        foreach ((array)$item['subject'] as $subj) {
                            if (!empty($subj['label'])) {
                                $mapped = normalizeGenre($subj['label']);
                                if (!empty($mapped)) { $genre = $mapped; break; }
                            }
                        }
                    }
                    
                    // Cover über die sichere ISBN von OpenLibrary abgreifen
                    $coverUrl = '';
                    if (!empty($foundIsbn)) {
                        $coverUrl = "https://covers.openlibrary.org/b/isbn/" . $foundIsbn . "-M.jpg";
                    }
                    
                    $results[] = [
                        'title' => $title,
                        'author' => $author,
                        'year' => $year,
                        'genre' => $genre,
                        'cover' => $coverUrl,
                        'isbn' => $foundIsbn
                    ];
                }
            }
        }
    }

    // VERSUCH 3: OpenLibrary Fallback (Falls Google UND Lobid fehlschlagen)
    if (empty($results)) {
        $olUrl = "https://openlibrary.org/search.json?q=" . urlencode($query) . "&limit=5";
        $ol = curlGet($olUrl);
        if ($ol['code'] === 200) {
            $olData = json_decode($ol['body'], true);
            if (!empty($olData['docs'])) {
                foreach ($olData['docs'] as $item) {
                    $coverUrl = !empty($item['cover_i']) ? "https://covers.openlibrary.org/b/id/" . $item['cover_i'] . "-M.jpg" : '';
                    $olIsbn = '';
                    if (!empty($item['isbn'])) {
                        foreach($item['isbn'] as $potentialIsbn) {
                            $clean = preg_replace('/\D/', '', $potentialIsbn);
                            if (strlen($clean) === 13) { $olIsbn = $clean; break; }
                            if (strlen($clean) === 10 && empty($olIsbn)) { $olIsbn = $clean; }
                        }
                    }
                    if (empty($coverUrl) && !empty($olIsbn)) { $coverUrl = "https://covers.openlibrary.org/b/isbn/" . $olIsbn . "-M.jpg"; }
                    $genre = '';
                    if (!empty($item['subject'])) {
                        foreach ((array)$item['subject'] as $subj) {
                            $mapped = normalizeGenre($subj);
                            if (!empty($mapped)) { $genre = $mapped; break; }
                        }
                    }
                    $results[] = [
                        'title' => $item['title'] ?? '',
                        'author' => !empty($item['author_name']) ? $item['author_name'][0] : '',
                        'year' => $item['first_publish_year'] ?? '',
                        'genre' => $genre, 
                        'cover' => $coverUrl,
                        'isbn' => $olIsbn
                    ];
                }
            }
        }
    }

    ob_end_clean();
    echo json_encode(['type' => 'list', 'results' => $results]);
    exit;
}

// ==========================================
// ISBN-SUCHE (Einzelnes Ergebnis via Scanner)
// ==========================================
$result = ['source' => 'none', 'title' => '', 'author' => '', 'year' => '', 'genre' => '', 'cover' => 'https://covers.openlibrary.org/b/isbn/' . $isbn . '-M.jpg'];
$google = curlGet("https://www.googleapis.com/books/v1/volumes?q=isbn:" . $isbn);

if ($google['code'] === 200) {
    $googleData = json_decode($google['body'], true);
    if (!empty($googleData['totalItems']) && $googleData['totalItems'] > 0) {
        $b = $googleData['items'][0]['volumeInfo'];
        $result['source'] = 'google';
        $result['title']  = $b['title'] ?? '';
        $result['author'] = $b['authors'][0] ?? '';
        $result['year']   = isset($b['publishedDate']) ? substr($b['publishedDate'], 0, 4) : '';
        $result['cover']  = str_replace('http://', 'https://', $b['imageLinks']['thumbnail'] ?? $result['cover']);
        $cats = $b['categories'] ?? [];
        $result['genre'] = !empty($cats) ? normalizeGenre($cats[0]) : '';
    }
}
if ($result['source'] === 'none') $result['source'] = 'try_openlibrary';
ob_end_clean();
echo json_encode(array_merge(['type' => 'single'], $result));