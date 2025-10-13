<?php
// ingest_chat.php — endpoint penerima JSON dari Jenkins
// Simpan ke tabel `requests`

header('Content-Type: application/json; charset=utf-8');

// ==== Wajib POST + JSON ====
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['code'=>'METHOD_NOT_ALLOWED','error'=>'use POST']); exit;
}
$ct = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
if (stripos($ct, 'application/json') === false) {
  http_response_code(415);
  echo json_encode(['code'=>'UNSUPPORTED_MEDIA_TYPE','error'=>'content-type must be application/json']); exit;
}

$VALID_TOKEN = getenv('INGEST_TOKEN') ?: '4f9a7c2e1e9c4f6f2d5b1a9c0e7d3a12b6c9f1e4d7a8b2c3d4e5f6a7b8c9d0e1';

// ==== Auth via header ====
$hdrToken = $_SERVER['HTTP_X_INGEST_TOKEN'] ?? '';
if (!hash_equals($VALID_TOKEN, $hdrToken)) {
  http_response_code(403);
  echo json_encode(['code'=>'FORBIDDEN','error'=>'invalid token']); exit;
}

// ==== DB config ====
$DB_HOST = getenv('DB_HOST') ?: 'db';
$DB_PORT = getenv('DB_PORT') ?: '3306';
$DB_NAME = getenv('DB_NAME') ?: 'deploymentecal';
$DB_USER = getenv('DB_USER') ?: 'deployuser';
$DB_PASS = getenv('DB_PASS') ?: 'secret';

$dsn = "mysql:host={$DB_HOST};port={$DB_PORT};dbname={$DB_NAME};charset=utf8mb4";
date_default_timezone_set('Asia/Jakarta');

try {
  $pdo = new PDO($dsn,$DB_USER,$DB_PASS,[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
  ]);
} catch(Throwable $e) {
  http_response_code(500);
  echo json_encode(['code'=>'DB_ERROR','error'=>$e->getMessage()]); exit;
}

// ==== Read JSON body ====
$raw = file_get_contents('php://input') ?: '';
// buang BOM jika ada
if (substr($raw, 0, 3) === "\xEF\xBB\xBF") $raw = substr($raw, 3);
$in  = json_decode($raw, true);
if (!is_array($in)) {
  http_response_code(400);
  echo json_encode(['code'=>'BAD_REQUEST','error'=>'invalid json']); exit;
}

// helper cut length (opsional, mencegah "Data too long")
$cut = function($s, $len){ $s=(string)$s; return mb_substr($s,0,$len,'UTF-8'); };

// ==== Normalize / mapping ====
$server        = strtoupper(trim($in['server'] ?? ''));
$site          = strtoupper(trim($in['site'] ?? ''));
$projectRaw    = trim($in['project'] ?? '');
$project       = strtoupper(preg_replace('/\s+/', '-', $projectRaw));
$service       = strtolower(trim($in['service'] ?? ''));
$source_branch = trim($in['source_branch'] ?? '');
$latest_ver    = trim($in['latest_version'] ?? '');
$new_ver       = trim($in['new_version'] ?? '');
$status        = strtoupper(trim($in['status'] ?? 'OPEN'));

// dari stage “Repo Git Metadata Info”
$git_short_in  = trim($in['git_short'] ?? '');
$git_short     = strtoupper($git_short_in);
$git_author    = trim($in['git_author'] ?? '');
$git_title     = trim($in['git_title'] ?? '');
$git_created_s = trim($in['git_created'] ?? '');

// Normalisasi tanggal git_created ke "Y-m-d H:i:s"
$git_created = null;
if ($git_created_s !== '') {
  $ts = strtotime($git_created_s);
  if ($ts !== false) {
    $git_created = date('Y-m-d H:i:s', $ts); // disimpan sebagai DATETIME lokal (Asia/Jakarta)
  }
}

// dev_requestor = git author (fallback ke field lama / Jenkins)
$dev_requestor = $git_author !== '' ? $git_author : trim($in['dev_requestor'] ?? 'Jenkins');

// nomor_form digantikan oleh git short hash (upper)
$nomor_form = $git_short;

// Validasi minimal
if ($server === '' || $site === '' || $project === '' || $service === '') {
  http_response_code(400);
  echo json_encode(['code'=>'BAD_REQUEST','error'=>'missing required fields (server/site/project/service)']); exit;
}

// (Opsional) pangkas panjang sesuai skema kolom kamu
$nomor_form    = $cut($nomor_form, 40);   // kalau kolom VARCHAR(40) atau 16–40
$dev_requestor = $cut($dev_requestor, 120);
$server        = $cut($server, 32);
$site          = $cut($site, 64);
$project       = $cut($project, 64);
$service       = $cut($service, 128);
$source_branch = $cut($source_branch, 128);
$latest_ver    = $cut($latest_ver, 190);
$new_ver       = $cut($new_ver, 190);
$git_short     = $cut($git_short, 40);
$git_author    = $cut($git_author, 120);
$git_title     = $cut($git_title, 255);
$status        = $cut($status, 20);

// ==== Insert ====
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
  ':git_created'   => $git_created, // null boleh untuk DATETIME yang nullable
  ':status'        => $status,
]);

$id = (int)$pdo->lastInsertId();

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
