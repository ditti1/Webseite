<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title>Startseite - Mein Portal</title>
    <link rel="stylesheet" href="style.css">
    <link rel="manifest" href="/webseite/manifest.json">
</head>
<body>

    <h1>Willkommen auf meinem Portal</h1>

    <div class="dashboard">
        <!-- Kachel 1: Die Bibliothek -->
        <a href="bibliothek/bibliothek.php" class="card library">
            <div class="icon">📚</div>
            <div class="card-title">Meine Bibliothek</div>
            <div class="card-desc">Bücher verwalten, scannen und Lesestatus im Blick behalten.</div>
        </a>

        <a href="kleiderschrank/kleiderschrank.php" class="card placeholder">
            <div class="icon">👕</div>
            <div class="card-title">Kleiderschrank</div>
            <div class="card-desc">Outfits verwalten, kategorisieren und den Überblick behalten.</div>
        </a>
    </div>
<script>
document.addEventListener('click', function(event) {
    // Sucht den angeklickten Link
    var target = event.target.closest('a');
    
    // Wenn es ein Link ist und er zur selben Website führt (dein lokaler Server)
    if (target && target.host === window.location.host) {
        // Verhindert das Standard-Öffnen (was das "X"-Menü auslöst)
        event.preventDefault();
        // Lädt die Seite direkt im bestehenden Vollbild
        window.location.href = target.href;
    }
}, false);
</script>
</body>

   <?php include 'includes/nav.php'; ?>

</html>