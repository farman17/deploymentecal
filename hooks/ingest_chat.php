<?php
/**
 * ingest_chat.php
 * Endpoint sederhana untuk menulis riwayat deploy ke tabel `requests`.
 * Mapping khusus:
 *   - nomor_form   := GIT SHORT HASH (upper, 8 chars)
 *   - dev_requestor:= GIT AUTHOR
 */

declare(strict_types=1);
date_default_timezone_set('Asia/Jakarta');
header('Content-Type: application/json; charset=utf-8');

/* ======================== Config via ENV ======================== */
$DB_HOST  = getenv('DB_HOST') ?: 'db';
$DB_PORT  = getenv('DB_PORT') ?: '3306';
$DB_NAME  = getenv('DB_NAME') ?: 'deploymentecal';
$DB_USER  = getenv('DB_USER') ?: 'deployuser';
$DB_PASS  = getenv('DB_PASS') ?: 'secret';
$INGEST_TOKEN = getenv('INGEST_TOKEN') ?: 'please-change-me';

/* ======================== Helpers ======================== */
function h401($msg){ http_response_code(401); echo json_encode(['ok'=>false,'error'=>$msg]); exit; }
function h400($msg){ http_response_code(400); echo json_encode(['ok'=>false,'error'=>$msg]); exit; }
function h405($msg){ http_response_code(405); echo json_encode(['ok'=>false,'error'=>$msg]); exit; }
function h500($msg){ http_response_code(500); echo json_encode(['ok'=>false,'error'=>$msg]); exit; }

/** Safe get all headers (apache/nginx/cli). */
function get_header(string $name): ?string {
  $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
  if (isset($_SERVER[$key])) return $_SERVER[$key];
  if (function_exists('getallheaders')) {
    foreach (getallheaders() as $k=>$v) {
      if (strcasecmp($k, $name) === 0) return $v;
    }
  }
  return null;
}

/* ======================== Auth ======================== */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  h405('Use POST method');
}
$hdrToken = trim((string)(get_header('X-INGEST-TOKEN') ?? ''));
if ($hdrToken === '' || !hash_equals($INGEST_TOKEN, $hdrToken)) {
  h401('Invalid or missing X-INGEST-TOKEN');
}

/* ======================== Parse JSON ======================== */
$raw = file_get_contents('php://input');
if ($raw === '' || $raw === false) h400('Empty body');

$in = json_decode($raw, true);
if (!is_array($in)) h400('Invalid JSON');

/* ======================== Derive fields ======================== */
/* Git short hash → nomor_form */
$gitShort   = strtoupper(trim((string)($in['git_short'] ?? '')));
$gitHash    = trim((string)($in['git_hash']  ?? ''));
if ($gitShort === '' && $gitHash !== '') {
  $gitShort = strtoupper(substr(preg_replace('/[^0-9a-f]/i','',$gitHash), 0, 8));
}
$nomor_form = $gitShort !== '' ? $gitShort : 'N/A';

/* Git author → dev_requestor (fallback ke yang lama bila kosong) */
$gitAuthor     = trim((string)($in['git_author'] ?? ''));
$dev_requestor = $gitAuthor !== '' ? $gitAuthor : trim((string)($in['dev_requestor'] ?? 'Jenkins'));

/* Normalisasi server & site dari payload
   Server diasumsikan sudah berupa PRODUCTION/STAGING dari sisi Jenkins,
   tapi tetap kita uppercase-kan agar konsisten. */
$server = strtoupper(trim((string)($in['server'] ?? 'PRODUCTION')));
$site   = strtoupper(trim((string)($in['site']   ?? 'COLOCLUSTER')));

/* Project / Service / Branch */
$project       = strtoupper(trim((string)($in['project'] ?? 'UNKNOWN')));
$service       = strtolower(trim((string)($in['service'] ?? '')));
$service       = preg_replace('/\s+/', '_', $service); // lower_snake
$source_branch = trim((string)($in['source_branch'] ?? 'master'));

/* Versions */
$latest_version = trim((string)($in['latest_version'] ?? ''));
$new_version    = trim((string)($in['new_version']    ?? ''));

/* Status + message_ts (epoch seconds) */
$status     = strtoupper(trim((string)($in['status'] ?? 'OPEN')));
$message_ts = $in['message_ts'] ?? time();
if (!is_numeric($message_ts)) {
  $message_ts = time();
} else {
  $message_ts = (int)$message_ts;
}

/* Optional: batasi panjang agar aman dg skema yang sempit */
$nomor_form      = substr($nomor_form, 0, 32);
$dev_requestor   = substr($dev_requestor, 0, 128);
$site            = substr($site, 0, 32);
$project         = substr($project, 0, 64);
$service         = substr($service, 0, 128);
$source_branch   = substr($source_branch, 0, 64);
$latest_version  = substr($latest_version, 0, 128);
$new_version     = substr($new_version, 0, 128);
$status          = substr($status, 0, 32);

/* ======================== DB Insert ======================== */
$dsn = "mysql:host={$DB_HOST};port={$DB_PORT};dbname={$DB_NAME};charset=utf8mb4";
try {
  $pdo = new PDO($dsn, $DB_USER, $DB_PASS, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);

  $sql = "INSERT INTO requests
            (nomor_form, dev_requestor, server, site, project, service, source_branch,
             latest_version, new_version, message_ts, status, created_at, updated_at)
          VALUES
            (:nomor_form, :dev_requestor, :server, :site, :project, :service, :source_branch,
             :latest_version, :new_version, :message_ts, :status, NOW(), NOW())";

  $st = $pdo->prepare($sql);
  $st->execute([
    ':nomor_form'     => $nomor_form,
    ':dev_requestor'  => $dev_requestor,
    ':server'         => $server,
    ':site'           => $site,
    ':project'        => $project,
    ':service'        => $service,
    ':source_branch'  => $source_branch,
    ':latest_version' => $latest_version,
    ':new_version'    => $new_version,
    ':message_ts'     => $message_ts,
    ':status'         => $status,
  ]);

  echo json_encode([
    'ok'    => true,
    'id'    => (int)$pdo->lastInsertId(),
    'data'  => [
      'nomor_form'    => $nomor_form,     // = GIT SHORT HASH (UPPER)
      'dev_requestor' => $dev_requestor,  // = GIT AUTHOR
      'server'        => $server,
      'site'          => $site,
      'project'       => $project,
      'service'       => $service,
      'source_branch' => $source_branch,
      'latest_version'=> $latest_version,
      'new_version'   => $new_version,
      'message_ts'    => $message_ts,
      'status'        => $status,
    ],
  ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
  h500('DB error: '.$e->getMessage());
}
