<?php
// api.php — handles GET (subjects) and POST (submit survey)
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Catch any PHP error and return JSON instead of HTML
set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Preflight CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

try {

require_once __DIR__ . '/db.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $action = $_GET['action'] ?? '';

    if ($action === 'subjects') {
        $db = getDB();
        $results = $db->query("SELECT name FROM subjects ORDER BY id ASC");
        $subjects = [];
        while ($row = $results->fetchArray(SQLITE3_ASSOC)) {
            $subjects[] = $row['name'];
        }
        $db->close();
        echo json_encode(['subjects' => $subjects]);
        exit;
    }

    if ($action === 'results') {
        $db = getDB();

        // All respondents
        $res = $db->query("SELECT id, pseudo, created_at FROM responses ORDER BY created_at ASC");
        $respondents = [];
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $respondents[] = $row;
        }

        // All subjects
        $res = $db->query("SELECT name FROM subjects ORDER BY id ASC");
        $subjects = [];
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $subjects[] = $row['name'];
        }

        // Q1
        $q1 = [];
        $res = $db->query("SELECT r.pseudo, q.subject_name, q.level FROM q1_contribution q JOIN responses r ON q.response_id = r.id");
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $q1[] = $row;
        }

        // Q2
        $q2 = [];
        $res = $db->query("SELECT r.pseudo, q.subject_name, q.rank FROM q2_priority q JOIN responses r ON q.response_id = r.id");
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $q2[] = $row;
        }

        // Q3
        $q3 = [];
        $res = $db->query("SELECT r.pseudo, q.subject_name, q.level FROM q3_vision q JOIN responses r ON q.response_id = r.id");
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $q3[] = $row;
        }

        // Q4
        $q4 = [];
        $res = $db->query("SELECT r.pseudo, q.subject_name, q.level FROM q4_confidence q JOIN responses r ON q.response_id = r.id");
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $q4[] = $row;
        }

        // Q5
        $q5 = [];
        $res = $db->query("SELECT r.pseudo, q.level FROM q5_workload q JOIN responses r ON q.response_id = r.id");
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $q5[] = $row;
        }

        $db->close();
        echo json_encode([
            'respondents' => $respondents,
            'subjects' => $subjects,
            'q1' => $q1,
            'q2' => $q2,
            'q3' => $q3,
            'q4' => $q4,
            'q5' => $q5,
        ]);
        exit;
    }

    echo json_encode(['error' => 'Unknown action']);
    exit;
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input || empty($input['pseudo'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Pseudo requis']);
        exit;
    }

    $db = getDB();

    // Insert new custom subjects if any
    if (!empty($input['new_subjects'])) {
        $stmt = $db->prepare("INSERT OR IGNORE INTO subjects (name) VALUES (:name)");
        foreach ($input['new_subjects'] as $ns) {
            $ns = trim($ns);
            if ($ns !== '') {
                $stmt->bindValue(':name', $ns);
                $stmt->execute();
                $stmt->reset();
            }
        }
    }

    // Create response record
    $stmt = $db->prepare("INSERT INTO responses (pseudo) VALUES (:pseudo)");
    $stmt->bindValue(':pseudo', trim($input['pseudo']));
    $stmt->execute();
    $responseId = $db->lastInsertRowID();

    // Q1
    if (!empty($input['q1'])) {
        $stmt = $db->prepare("INSERT INTO q1_contribution (response_id, subject_name, level) VALUES (:rid, :sn, :lv)");
        foreach ($input['q1'] as $item) {
            $stmt->bindValue(':rid', $responseId);
            $stmt->bindValue(':sn', $item['subject']);
            $stmt->bindValue(':lv', $item['level']);
            $stmt->execute();
            $stmt->reset();
        }
    }

    // Q2
    if (!empty($input['q2'])) {
        $stmt = $db->prepare("INSERT INTO q2_priority (response_id, subject_name, rank) VALUES (:rid, :sn, :rk)");
        foreach ($input['q2'] as $item) {
            $stmt->bindValue(':rid', $responseId);
            $stmt->bindValue(':sn', $item['subject']);
            $stmt->bindValue(':rk', $item['rank']);
            $stmt->execute();
            $stmt->reset();
        }
    }

    // Q3
    if (!empty($input['q3'])) {
        $stmt = $db->prepare("INSERT INTO q3_vision (response_id, subject_name, level) VALUES (:rid, :sn, :lv)");
        foreach ($input['q3'] as $item) {
            $stmt->bindValue(':rid', $responseId);
            $stmt->bindValue(':sn', $item['subject']);
            $stmt->bindValue(':lv', $item['level']);
            $stmt->execute();
            $stmt->reset();
        }
    }

    // Q4
    if (!empty($input['q4'])) {
        $stmt = $db->prepare("INSERT INTO q4_confidence (response_id, subject_name, level) VALUES (:rid, :sn, :lv)");
        foreach ($input['q4'] as $item) {
            $stmt->bindValue(':rid', $responseId);
            $stmt->bindValue(':sn', $item['subject']);
            $stmt->bindValue(':lv', $item['level']);
            $stmt->execute();
            $stmt->reset();
        }
    }

    // Q5
    if (!empty($input['q5'])) {
        $stmt = $db->prepare("INSERT INTO q5_workload (response_id, level) VALUES (:rid, :lv)");
        $stmt->bindValue(':rid', $responseId);
        $stmt->bindValue(':lv', $input['q5']);
        $stmt->execute();
    }

    $db->close();
    echo json_encode(['success' => true, 'response_id' => $responseId]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Erreur serveur',
        'message' => $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine()
    ]);
}
