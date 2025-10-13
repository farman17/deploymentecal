<?php
// ingest_chat.php — endpoint penerima JSON dari Jenkins
// Menyimpan ke tabel `requests`

header('Content-Type: application/json; charset=utf-8');

$VALID_TOKEN = getenv('INGEST_TOKEN') ?: 'CHANGE_ME_TOKEN';

// ===== Auth very simple via header =====
$hdrToken = $_SERVER['HTTP_X_INGEST_TOKEN'] ?? '';
if (!hash_equals($VALID_TOKEN, $hdrToken)) {
  http_response_code(403);
  echo json_encode(['code'=>'FORBIDDEN','error'=>'invalid token']); exit;
}

DB_HOST = getenv('DB_HOST') ?: 'db';
$DB_PORT = getenv('DB_PORT') ?: '3306';
$DB_NAME = getenv('DB_NAME') ?: 'deploymentecal';
$DB_USER = getenv('DB_USER') ?: 'deployuser';
$DB_PASS = getenv('DB_PASS') ?: 'secret';

$dsn = "mysql:host={$DB_HOST};port={$DB_PORT};dbname={$DB_NAME};charset=utf8mb4";
date_default_timezone_set('Asia/Jakarta');
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }


try {
  $pdo = new PDO($dsn,$DB_USER,$DB_PASS,[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
  ]);
} catch(Throwable $e) {
  http_response_code(500);
  echo json_encode(['code'=>'DB_ERROR','error'=>$e->getMessage()]); exit;
}

// ===== Read JSON body =====
$raw = file_get_contents('php://input') ?: '';
$in  = json_decode($raw, true);
if (!is_array($in)) {
  http_response_code(400);
  echo json_encode(['code'=>'BAD_REQUEST','error'=>'invalid json']); exit;
}

// ===== Normalize / mapping =====
$server        = strtoupper(trim($in['server'] ?? ''));
$site          = strtoupper(trim($in['site'] ?? ''));
$projectRaw    = trim($in['project'] ?? '');
$project       = strtoupper(preg_replace('/\s+/', '-', $projectRaw));
$service       = strtolower(trim($in['service'] ?? ''));
$source_branch = trim($in['source_branch'] ?? '');
$latest_ver    = trim($in['latest_version'] ?? '');
$new_ver       = trim($in['new_version'] ?? '');
$status        = strtoupper(trim($in['status'] ?? 'OPEN'));

$git_short     = strtoupper(trim($in['git_short'] ?? ''));
$git_author    = trim($in['git_author'] ?? '');
$git_title     = trim($in['git_title'] ?? '');
$git_created   = trim($in['git_created'] ?? '');

// dev_requestor DIPAKAI = git_author (fallback ke field lama, lalu 'Jenkins')
$dev_requestor = $git_author !== '' ? $git_author : trim($in['dev_requestor'] ?? 'Jenkins');

// nomor_form DIGANTIKAN OLEH GIT SHORT HASH (8 char, uppercase)
$nomor_form = $git_short !== '' ? substr($git_short, 0, 32) : ''; // simpan apa adanya (upper)

// Beberapa validasi ringan
if ($server === '' || $site === '' || $project === '' || $service === '') {
  http_response_code(400);
  echo json_encode(['code'=>'BAD_REQUEST','error'=>'missing required fields (server/site/project/service)']); exit;
}

// ===== Insert =====
$sql = "INSERT INTO requests
  (nomor_form, dev_requestor, server, site, project, service, source_branch,
   latest_version, new_version, git_short, git_author, git_title, git_created,
   status, created_at, updated_at)
  VALUES
  (:nomor_form, :dev_requestor, :server, :site, :project, :service, :source_branch,
   :latest_version, :new_version, :git_short, :git_author, :git_title, :git_created,
   :status, NOW(), NOW())";

$st = $pdo->prepare($sql);
$st->execute([
  ':nomor_form'    => $nomor_form,
  ':dev_requestor' => $dev_requestor,
  ':server'        => $server,
  ':site'          => $site,
  ':project'       => $project,
  ':service'       => $service,
  ':source_branch' => $source_branch,
  ':latest_version'=> $latest_ver,
  ':new_version'   => $new_ver,
  ':git_short'     => $git_short,
  ':git_author'    => $git_author,
  ':git_title'     => $git_title,
  ':git_created'   => $git_created,
  ':status'        => $status,
]);

$id = (int)$pdo->lastInsertId();

// Kembalikan echo data yang disimpan (buat debugging di Jenkins)
echo json_encode([
  'code' => 'SUCCESS',
  'id'   => $id,
  'data' => [
    'nomor_form'    => $nomor_form,
    'dev_requestor' => $dev_requestor,
    'server'        => $server,
    'site'          => $site,
    'project'       => $project,
    'service'       => $service,
    'source_branch' => $source_branch,
    'latest_version'=> $latest_ver,
    'new_version'   => $new_ver,
    'git_short'     => $git_short,
    'git_author'    => $git_author,
    'git_title'     => $git_title,
    'git_created'   => $git_created,
    'status'        => $status
  ]
], JSON_UNESCAPED_SLASHES);
