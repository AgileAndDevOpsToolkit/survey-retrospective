<?php
// db.php — SQLite database setup and helpers
error_reporting(E_ALL);
ini_set('display_errors', 0);

function getDB() {
    $dbPath = __DIR__ . '/survey.db';

    try {
        $db = new SQLite3($dbPath);
    } catch (Exception $e) {
        // Fallback : répertoire temp si le dossier courant n'est pas accessible en écriture
        $dbPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'survey_pi.db';
        try {
            $db = new SQLite3($dbPath);
        } catch (Exception $e2) {
            if (php_sapi_name() !== 'cli') {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(500);
                echo json_encode([
                    'error' => 'Impossible de creer la base de donnees',
                    'detail' => $e->getMessage(),
                    'path_tried' => __DIR__ . '/survey.db',
                    'dir_writable' => is_writable(__DIR__) ? 'oui' : 'non',
                ]);
                exit;
            }
            throw $e2;
        }
    }

    $db->busyTimeout(5000);
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('PRAGMA foreign_keys=ON');
    return $db;
}

function initDB() {
    $db = getDB();

    $db->exec("
        CREATE TABLE IF NOT EXISTS subjects (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE
        );
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS responses (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            pseudo TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
    ");

    // Q1: contribution per subject
    $db->exec("
        CREATE TABLE IF NOT EXISTS q1_contribution (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            response_id INTEGER NOT NULL,
            subject_name TEXT NOT NULL,
            level TEXT NOT NULL,
            FOREIGN KEY (response_id) REFERENCES responses(id)
        );
    ");

    // Q2: priority ranking
    $db->exec("
        CREATE TABLE IF NOT EXISTS q2_priority (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            response_id INTEGER NOT NULL,
            subject_name TEXT NOT NULL,
            rank INTEGER NOT NULL,
            FOREIGN KEY (response_id) REFERENCES responses(id)
        );
    ");

    // Q3: vision level
    $db->exec("
        CREATE TABLE IF NOT EXISTS q3_vision (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            response_id INTEGER NOT NULL,
            subject_name TEXT NOT NULL,
            level TEXT NOT NULL,
            FOREIGN KEY (response_id) REFERENCES responses(id)
        );
    ");

    // Q4: confidence level
    $db->exec("
        CREATE TABLE IF NOT EXISTS q4_confidence (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            response_id INTEGER NOT NULL,
            subject_name TEXT NOT NULL,
            level TEXT NOT NULL,
            FOREIGN KEY (response_id) REFERENCES responses(id)
        );
    ");

    // Q5: workload
    $db->exec("
        CREATE TABLE IF NOT EXISTS q5_workload (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            response_id INTEGER NOT NULL,
            level TEXT NOT NULL,
            FOREIGN KEY (response_id) REFERENCES responses(id)
        );
    ");

    // Seed default subjects
    $defaults = [
        'Migration API v2',
        'Refonte page d\'accueil',
        'Intégration SSO',
        'Optimisation performances BDD',
        'Module de notifications',
        'Documentation technique',
        'Tests end-to-end',
    ];
    $stmt = $db->prepare("INSERT OR IGNORE INTO subjects (name) VALUES (:name)");
    foreach ($defaults as $s) {
        $stmt->bindValue(':name', $s);
        $stmt->execute();
        $stmt->reset();
    }

    $db->close();
}

initDB();
