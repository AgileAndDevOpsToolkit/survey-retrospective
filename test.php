<?php
// test.php — Page de diagnostic, à supprimer après vérification
header('Content-Type: text/html; charset=utf-8');
echo "<h2>Diagnostic serveur</h2>";
echo "<pre>";

echo "PHP version : " . phpversion() . "\n";
echo "SQLite3 dispo : " . (class_exists('SQLite3') ? 'OUI ✅' : 'NON ❌') . "\n";

$dir = __DIR__;
echo "Dossier courant : $dir\n";
echo "Dossier accessible en écriture : " . (is_writable($dir) ? 'OUI ✅' : 'NON ❌') . "\n";

$dbPath = $dir . '/survey.db';
echo "Chemin BDD : $dbPath\n";
echo "BDD existe déjà : " . (file_exists($dbPath) ? 'OUI' : 'NON') . "\n";

try {
    $db = new SQLite3($dbPath);
    echo "Connexion SQLite : OK ✅\n";
    $db->exec("CREATE TABLE IF NOT EXISTS _test (id INTEGER PRIMARY KEY)");
    $db->exec("DROP TABLE _test");
    echo "Écriture SQLite : OK ✅\n";
    $db->close();
    echo "BDD existe maintenant : " . (file_exists($dbPath) ? 'OUI ✅' : 'NON ❌') . "\n";
} catch (Exception $e) {
    echo "ERREUR SQLite : " . $e->getMessage() . " ❌\n";
}

echo "\nTest API subjects...\n";
$url = 'http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . dirname($_SERVER['SCRIPT_NAME']) . '/api.php?action=subjects';
echo "URL: $url\n";
$response = @file_get_contents($url);
if ($response === false) {
    echo "Appel API échoué ❌ (pas grave si allow_url_fopen est désactivé)\n";
} else {
    echo "Réponse API : $response\n";
    $json = json_decode($response, true);
    echo "JSON valide : " . ($json !== null ? 'OUI ✅' : 'NON ❌') . "\n";
}

echo "</pre>";
echo "<p><strong>Si tout est ✅, supprimez ce fichier et utilisez <a href='index.html'>le sondage</a>.</strong></p>";
