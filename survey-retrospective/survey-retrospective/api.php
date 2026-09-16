<?php
/**
 * api.php — Unique point d'entrée back-end du sondage.
 *
 * Toute la "base de données" est un seul fichier JSON : data.json, à la racine,
 * à côté des fichiers .html.
 *
 * Structure de data.json :
 * {
 *   "version": 1,
 *   "subjects": ["Sujet A", "Sujet B", ...],          // ordre = ordre d'affichage
 *   "responses": [
 *     {
 *       "id": 1,
 *       "pseudo": "Alice",
 *       "created_at": "2026-09-16T10:12:33+00:00",
 *       "q1": { "Sujet A": "contribué activement", ... },   // contribution par sujet
 *       "q2": [ "Sujet B", "Sujet A", ... ],                  // classement, index 0 = plus prioritaire
 *       "q3": { "Sujet A": "super claire", ... },             // vision par sujet
 *       "q4": { "Sujet A": "on va réussir", ... },            // confiance par sujet
 *       "q5": "J'ai juste ce qu'il faut"                      // charge de travail
 *     }
 *   ]
 * }
 *
 * Endpoints (toutes les réponses sont en JSON) :
 *   GET  api.php?action=subjects        -> { subjects: [...] }
 *   GET  api.php?action=results         -> { subjects: [...], responses: [...] }
 *   GET  api.php?action=db              -> contenu complet de data.json (sauvegarde)
 *   GET  api.php?action=health          -> diagnostic (chemin, droits, nb réponses)
 *   POST api.php  {action:"submit", pseudo, new_subjects, q1, q2, q3, q4, q5}
 *   POST api.php  {action:"set_subjects", subjects:[...]}      (admin)
 *   POST api.php  {action:"delete_response", id}               (admin)
 *   POST api.php  {action:"clear_responses"}                   (admin)
 *
 * Robustesse :
 *   - verrou exclusif (flock) sur data.json.lock pour toute écriture ;
 *   - écriture atomique (fichier temporaire + rename), avec repli en écriture
 *     directe si rename est impossible (ex. data.json monté en bind-mount Docker) ;
 *   - fichier absent -> recréé avec les sujets par défaut ;
 *   - fichier corrompu -> sauvegardé en data.json.corrupt-<date> puis recréé ;
 *   - toute erreur PHP est transformée en réponse JSON avec un code HTTP explicite.
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------

const DATA_FILE      = __DIR__ . '/data.json';
const LOCK_FILE      = __DIR__ . '/data.json.lock';
const SCHEMA_VERSION = 1;

/** Sujets créés quand data.json n'existe pas encore. Ensuite ils se gèrent depuis admin.html. */
const DEFAULT_SUBJECTS = [
    'Migration API v2',
    'Refonte page d\'accueil',
    'Intégration SSO',
    'Optimisation performances BDD',
    'Module de notifications',
    'Documentation technique',
    'Tests end-to-end',
];

/** Valeurs autorisées (doivent correspondre aux libellés de index.html / results.html). */
const Q1_OPTIONS = ['contribué activement', 'suivi le sujet', 'pas travaillé dessus'];
const Q3_OPTIONS = ['super claire', 'bien mais avec des zones de flou', 'très flou', 'de quoi on parle ?'];
const Q4_OPTIONS = [
    'on va réussir',
    'on va réussir partiellement ou on sera en retard',
    'on va échouer',
    'je connais pas le sujet, ne se prononce pas',
    'je connais le sujet mais je me prononce pas car trop d\'incertitude',
];
const Q5_OPTIONS = [
    "J'ai trop de travail par rapport à ma capacité, je suis débordé",
    "J'ai juste ce qu'il faut",
    "J'ai pas assez de travail, je m'ennuie",
];

const MAX_PSEUDO_LEN   = 60;    // en octets UTF-8
const MAX_SUBJECT_LEN  = 120;   // en octets UTF-8
const MAX_SUBJECTS     = 100;
const MAX_RESPONSES    = 5000;
const MAX_BODY_BYTES   = 256 * 1024;

// ---------------------------------------------------------------------------
// Sortie JSON + gestion d'erreurs globale
// ---------------------------------------------------------------------------

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function respond(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail(string $message, int $status = 400, array $extra = []): never
{
    respond(['success' => false, 'error' => $message] + $extra, $status);
}

set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false; // appel préfixé par @ : on laisse le code gérer l'échec lui-même
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(function (Throwable $e): void {
    respond([
        'success' => false,
        'error'   => 'Erreur serveur',
        'detail'  => $e->getMessage(),
        'where'   => basename($e->getFile()) . ':' . $e->getLine(),
    ], 500);
});

// ---------------------------------------------------------------------------
// Utilitaires de validation
// ---------------------------------------------------------------------------

/** Nettoie une chaîne saisie par un utilisateur : trim, espaces multiples, longueur max, UTF-8 valide. */
function cleanString(mixed $value, int $maxLen): string
{
    if (!is_string($value)) {
        return '';
    }
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    if ($value === '' || !preg_match('//u', $value)) {
        return '';
    }
    if (strlen($value) > $maxLen) {
        // Coupe sur une frontière de caractère UTF-8 : on recule tant que l'octet
        // situé au point de coupe est un octet de continuation (10xxxxxx).
        $n = $maxLen;
        while ($n > 0 && (ord($value[$n]) & 0xC0) === 0x80) {
            $n--;
        }
        $value = rtrim(substr($value, 0, $n));
    }
    return $value;
}

/** Normalise une liste de sujets : chaînes propres, uniques (insensible à la casse), bornée. */
function cleanSubjectList(mixed $list): array
{
    if (!is_array($list)) {
        return [];
    }
    $out  = [];
    $seen = [];
    foreach ($list as $raw) {
        $name = cleanString($raw, MAX_SUBJECT_LEN);
        if ($name === '') {
            continue;
        }
        $key = strtolower($name);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $out[]      = $name;
        if (count($out) >= MAX_SUBJECTS) {
            break;
        }
    }
    return $out;
}

/** Garde uniquement les entrées {sujet connu => valeur autorisée}. */
function cleanPerSubjectMap(mixed $map, array $subjects, array $allowed): array
{
    if (!is_array($map)) {
        return [];
    }
    $known = array_flip($subjects);
    $out   = [];
    foreach ($map as $subject => $level) {
        if (is_string($subject) && isset($known[$subject]) && is_string($level) && in_array($level, $allowed, true)) {
            $out[$subject] = $level;
        }
    }
    return $out;
}

/** Garde uniquement les sujets connus, sans doublon, dans l'ordre fourni. */
function cleanRanking(mixed $list, array $subjects): array
{
    if (!is_array($list)) {
        return [];
    }
    $known = array_flip($subjects);
    $out   = [];
    foreach ($list as $subject) {
        if (is_string($subject) && isset($known[$subject]) && !in_array($subject, $out, true)) {
            $out[] = $subject;
        }
    }
    return $out;
}

// ---------------------------------------------------------------------------
// Couche "base de données" : un fichier JSON
// ---------------------------------------------------------------------------

function emptyDb(): array
{
    return ['version' => SCHEMA_VERSION, 'subjects' => DEFAULT_SUBJECTS, 'responses' => []];
}

/** Rend une structure conforme au schéma, quoi qu'il y ait dans le fichier. */
function normalizeDb(mixed $data): array
{
    if (!is_array($data)) {
        return emptyDb();
    }
    $db             = emptyDb();
    $db['subjects'] = isset($data['subjects']) ? cleanSubjectList($data['subjects']) : DEFAULT_SUBJECTS;
    $db['responses'] = [];
    $maxId = 0;

    foreach ((array) ($data['responses'] ?? []) as $r) {
        if (!is_array($r)) {
            continue;
        }
        $pseudo = cleanString($r['pseudo'] ?? '', MAX_PSEUDO_LEN);
        if ($pseudo === '') {
            continue;
        }
        $id = isset($r['id']) && is_int($r['id']) && $r['id'] > 0 ? $r['id'] : ++$maxId;
        $maxId = max($maxId, $id);
        $db['responses'][] = [
            'id'         => $id,
            'pseudo'     => $pseudo,
            'created_at' => is_string($r['created_at'] ?? null) ? $r['created_at'] : gmdate('c'),
            'q1'         => cleanPerSubjectMap($r['q1'] ?? [], $db['subjects'], Q1_OPTIONS),
            'q2'         => cleanRanking($r['q2'] ?? [], $db['subjects']),
            'q3'         => cleanPerSubjectMap($r['q3'] ?? [], $db['subjects'], Q3_OPTIONS),
            'q4'         => cleanPerSubjectMap($r['q4'] ?? [], $db['subjects'], Q4_OPTIONS),
            'q5'         => in_array($r['q5'] ?? null, Q5_OPTIONS, true) ? $r['q5'] : '',
        ];
    }
    return $db;
}

/**
 * Lit data.json. Ne lève jamais d'exception pour un contenu invalide :
 * un fichier absent ou corrompu est remplacé par une base vide (le fichier
 * corrompu est conservé sous un autre nom pour analyse).
 */
function readDb(): array
{
    if (!is_file(DATA_FILE)) {
        return emptyDb();
    }
    $raw = @file_get_contents(DATA_FILE);
    if ($raw === false) {
        throw new RuntimeException('Lecture impossible : ' . DATA_FILE);
    }
    if (trim($raw) === '') {
        return emptyDb();
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        $backup = DATA_FILE . '.corrupt-' . gmdate('Ymd-His');
        @copy(DATA_FILE, $backup);
        return emptyDb();
    }
    return normalizeDb($data);
}

/**
 * Prépare la base pour json_encode : q1/q3/q4 sont des dictionnaires {sujet: valeur}
 * et doivent rester des objets JSON ({}), même vides — PHP encoderait un tableau vide en [].
 */
function forJson(array $db): array
{
    foreach ($db['responses'] as &$r) {
        $r['q1'] = (object) $r['q1'];
        $r['q3'] = (object) $r['q3'];
        $r['q4'] = (object) $r['q4'];
    }
    unset($r);
    return $db;
}

/** Écrit data.json de façon atomique (tmp + rename), avec repli en écriture directe. */
function writeDb(array $db): void
{
    $json = json_encode(forJson($db), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Encodage JSON impossible : ' . json_last_error_msg());
    }
    $json .= "\n";

    $dir = dirname(DATA_FILE);
    $tmp = $dir . '/.data.json.' . getmypid() . '.' . bin2hex(random_bytes(4)) . '.tmp';

    $written = @file_put_contents($tmp, $json, LOCK_EX);
    if ($written === strlen($json) && @rename($tmp, DATA_FILE)) {
        @chmod(DATA_FILE, 0664);
        return;
    }
    @unlink($tmp);

    // Repli : écriture directe (nécessaire si data.json est un bind-mount Docker,
    // où rename() renvoie EBUSY). Le verrou global (flock) protège l'accès concurrent.
    $written = @file_put_contents(DATA_FILE, $json, LOCK_EX);
    if ($written !== strlen($json)) {
        throw new RuntimeException(
            'Écriture impossible dans ' . DATA_FILE . ' (dossier accessible en écriture : '
            . (is_writable($dir) ? 'oui' : 'non') . ')'
        );
    }
}

/**
 * Exécute $fn(&$db) sous verrou exclusif : lecture, modification, écriture.
 * $fn retourne la valeur renvoyée au client.
 */
function withDbLock(callable $fn): mixed
{
    $lock = @fopen(LOCK_FILE, 'c');
    if ($lock === false) {
        throw new RuntimeException('Impossible de créer le fichier de verrou ' . LOCK_FILE
            . ' (dossier accessible en écriture : ' . (is_writable(__DIR__) ? 'oui' : 'non') . ')');
    }
    try {
        if (!flock($lock, LOCK_EX)) {
            throw new RuntimeException('Verrou indisponible');
        }
        $db     = readDb();
        $before = json_encode($db);
        $result = $fn($db);
        if (json_encode($db) !== $before) {
            writeDb($db);
        }
        flock($lock, LOCK_UN);
        return $result;
    } finally {
        fclose($lock);
    }
}

/** Crée data.json avec les valeurs par défaut s'il n'existe pas (silencieux si le dossier est en lecture seule). */
function ensureDbFile(): void
{
    if (is_file(DATA_FILE)) {
        return;
    }
    try {
        withDbLock(function (array &$db): void {
            if (!is_file(DATA_FILE)) {
                writeDb($db);
            }
        });
    } catch (Throwable) {
        // Une lecture doit toujours fonctionner : on répondra avec les valeurs par défaut.
    }
}

// ---------------------------------------------------------------------------
// Routage
// ---------------------------------------------------------------------------

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($method === 'GET') {
    $action = (string) ($_GET['action'] ?? '');
    ensureDbFile();

    switch ($action) {
        case 'subjects':
            respond(['success' => true, 'subjects' => readDb()['subjects']]);

        case 'results':
            $db = forJson(readDb());
            respond(['success' => true, 'subjects' => $db['subjects'], 'responses' => $db['responses']]);

        case 'db':
            respond(forJson(readDb()));

        case 'health':
            $db = readDb();
            respond([
                'success'        => true,
                'php'            => PHP_VERSION,
                'data_file'      => DATA_FILE,
                'file_exists'    => is_file(DATA_FILE),
                'file_writable'  => is_file(DATA_FILE) ? is_writable(DATA_FILE) : null,
                'dir_writable'   => is_writable(__DIR__),
                'subjects'       => count($db['subjects']),
                'responses'      => count($db['responses']),
            ]);

        default:
            fail('Action inconnue', 404);
    }
}

if ($method !== 'POST') {
    fail('Méthode non autorisée', 405);
}

$rawBody = file_get_contents('php://input') ?: '';
if (strlen($rawBody) > MAX_BODY_BYTES) {
    fail('Requête trop volumineuse', 413);
}
$input = json_decode($rawBody, true);
if (!is_array($input)) {
    fail('Corps JSON invalide', 400);
}

// Rétro-compatibilité : un POST sans "action" mais avec un pseudo est une soumission.
$action = (string) ($input['action'] ?? (isset($input['pseudo']) ? 'submit' : ''));

switch ($action) {

    // ----- Participant : soumission du questionnaire -------------------------
    case 'submit': {
        $pseudo = cleanString($input['pseudo'] ?? '', MAX_PSEUDO_LEN);
        if ($pseudo === '') {
            fail('Pseudo requis');
        }
        $q5 = $input['q5'] ?? '';
        if (!is_string($q5) || !in_array($q5, Q5_OPTIONS, true)) {
            fail('Veuillez répondre à la question 5');
        }
        $newSubjects = cleanSubjectList($input['new_subjects'] ?? []);

        $result = withDbLock(function (array &$db) use ($input, $pseudo, $q5, $newSubjects): array {
            if (count($db['responses']) >= MAX_RESPONSES) {
                return ['success' => false, 'error' => 'Nombre maximal de réponses atteint', 'status' => 409];
            }
            // Ajoute les sujets créés à la volée (sans doublon, insensible à la casse).
            $lower = array_map('strtolower', $db['subjects']);
            foreach ($newSubjects as $s) {
                if (!in_array(strtolower($s), $lower, true) && count($db['subjects']) < MAX_SUBJECTS) {
                    $db['subjects'][] = $s;
                    $lower[]          = strtolower($s);
                }
            }
            $subjects = $db['subjects'];
            $maxId    = 0;
            foreach ($db['responses'] as $r) {
                $maxId = max($maxId, $r['id']);
            }
            $response = [
                'id'         => $maxId + 1,
                'pseudo'     => $pseudo,
                'created_at' => gmdate('c'),
                'q1'         => cleanPerSubjectMap($input['q1'] ?? [], $subjects, Q1_OPTIONS),
                'q2'         => cleanRanking($input['q2'] ?? [], $subjects),
                'q3'         => cleanPerSubjectMap($input['q3'] ?? [], $subjects, Q3_OPTIONS),
                'q4'         => cleanPerSubjectMap($input['q4'] ?? [], $subjects, Q4_OPTIONS),
                'q5'         => $q5,
            ];
            $db['responses'][] = $response;
            return ['success' => true, 'response_id' => $response['id']];
        });
        $status = $result['status'] ?? 200;
        unset($result['status']);
        respond($result, $status);
    }

    // ----- Admin : remplace la liste ordonnée des sujets ---------------------
    case 'set_subjects': {
        if (!isset($input['subjects']) || !is_array($input['subjects'])) {
            fail('Liste de sujets requise');
        }
        $subjects = cleanSubjectList($input['subjects']);
        $result = withDbLock(function (array &$db) use ($subjects): array {
            $db['subjects'] = $subjects;
            // Les réponses ne référencent que des sujets existants : on nettoie.
            foreach ($db['responses'] as &$r) {
                $r['q1'] = cleanPerSubjectMap($r['q1'], $subjects, Q1_OPTIONS);
                $r['q2'] = cleanRanking($r['q2'], $subjects);
                $r['q3'] = cleanPerSubjectMap($r['q3'], $subjects, Q3_OPTIONS);
                $r['q4'] = cleanPerSubjectMap($r['q4'], $subjects, Q4_OPTIONS);
            }
            unset($r);
            return ['success' => true, 'subjects' => $db['subjects']];
        });
        respond($result);
    }

    // ----- Admin : supprime une réponse ---------------------------------------
    case 'delete_response': {
        $id = $input['id'] ?? null;
        if (!is_int($id) || $id <= 0) {
            fail('Identifiant de réponse invalide');
        }
        $result = withDbLock(function (array &$db) use ($id): array {
            $before          = count($db['responses']);
            $db['responses'] = array_values(array_filter($db['responses'], fn($r) => $r['id'] !== $id));
            return ['success' => true, 'deleted' => $before - count($db['responses']), 'responses' => count($db['responses'])];
        });
        respond($result);
    }

    // ----- Admin : supprime toutes les réponses -------------------------------
    case 'clear_responses': {
        $result = withDbLock(function (array &$db): array {
            $deleted         = count($db['responses']);
            $db['responses'] = [];
            return ['success' => true, 'deleted' => $deleted];
        });
        respond($result);
    }

    default:
        fail('Action inconnue', 404);
}
