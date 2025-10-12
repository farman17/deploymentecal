<?php
// deployweb/hooks/ingest_chat.php
const INGEST_TOKEN = '4f9a7c2e1e9c4f6f2d5b1a9c0e7d3a12b6c9f1e4d7a8b2c3d4e5f6a7b8c9d0e1';

// ==== DB config via ENV (fallback ke nilai compose/run) ====
$DB_HOST = getenv('DB_HOST') ?: 'db';
$DB_PORT = getenv('DB_PORT') ?: '3306';
$DB_NAME = getenv('DB_NAME') ?: 'deploymentecal';
$DB_USER = getenv('DB_USER') ?: 'deployuser';
$DB_PASS = getenv('DB_PASS') ?: 'secret';

$dsn    = "mysql:host={$DB_HOST};port={$DB_PORT};dbname={$DB_NAME};charset=utf8mb4";
$dbUser = $DB_USER;
$dbPass = $DB_PASS;

function jerr($msg,$code=400,$extra=[]){
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok'=>false,'error'=>$msg]+$extra, JSON_UNESCAPED_UNICODE);
  exit;
}
function jok($data=[]){
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok'=>true]+$data, JSON_UNESCAPED_UNICODE);
  exit;
}
function rand_key($len=5){ $c='ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; $o=''; for($i=0;$i<$len;$i++) $o.=$c[random_int(0,strlen($c)-1)]; return $o; }
function gen_nomor_form(PDO $pdo, string $server, ?DateTime $createdDt=null): string {
  $tz = new DateTimeZone('Asia/Jakarta');
  $dt = $createdDt ?: new DateTime('now', $tz);
  $env  = strtoupper($server)==='PRODUCTION' ? 'PROD' : 'DEV';
  $date = $dt->format('Ymd');
  do {
    $no = "FRBEDVPS-REG-$env-$date-".rand_key(5);
    $st = $pdo->prepare('SELECT 1 FROM requests WHERE nomor_form=? LIMIT 1');
    $st->execute([$no]);
  } while($st->fetchColumn());
  return $no;
}

if ($_SERVER['REQUEST_METHOD']!=='POST') jerr('method not allowed',405);
if (!hash_equals(INGEST_TOKEN, $_SERVER['HTTP_X_INGEST_TOKEN'] ?? '')) jerr('unauthorized',401);

$ctype = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
$raw   = file_get_contents('php://input');
if (str_contains($ctype,'application/json'))       $data = json_decode($raw,true);
elseif (str_contains($ctype,'application/x-www-form-urlencoded') || str_contains($ctype,'multipart/form-data')) $data = $_POST;
else { parse_str($raw,$maybe); $data = $maybe ?: []; }

$debug = isset($_GET['debug']) || (($_SERVER['HTTP_X_DEBUG'] ?? '')==='1');

// ==== Wajib (tanpa version) — biar kompatibel skema baru ====
$need = ['dev_requestor','server','site','project','service','source_branch'];
$miss = [];
foreach($need as $k){ if(!isset($data[$k]) || trim((string)$data[$k])==='') $miss[]=$k; }
if ($miss){
  $extra = $debug ? ['received_keys'=>array_keys((array)$data),'content_type'=>$ctype,'raw'=>$raw] : [];
  jerr('missing fields',422,['fields'=>$miss]+$extra);
}

// Normalisasi nilai utama
$server  = strtoupper(trim($data['server']))==='PRODUCTION' ? 'PRODUCTION' : 'STAGING';
$site    = strtoupper(trim($data['site']));
$project = strtoupper(trim($data['project']));
$service = trim((string)$data['service']);
$branch  = trim((string)$data['source_branch']);
$devReq  = trim((string)$data['dev_requestor']);
$status  = strtoupper(trim($data['status'] ?? 'OPEN'));

// ====== Versi: dukung new_version / latest_version / changelog (fallback) ======
$verNew  = trim((string)($data['new_version']     ?? ''));
$verLast = trim((string)($data['latest_version']  ?? ''));
$chlog   = trim((string)($data['changelog']       ?? '')); // payload lama

if ($verNew === '' && $verLast !== '') $verNew = $verLast;
if ($verNew === '' && $chlog   !== '') $verNew = mb_substr($chlog, 0, 100, 'UTF-8'); // potong aman
if ($verLast === '' && $verNew !== '') $verLast = $verNew; // isi keduanya agar konsisten

// ===== Tentukan created_at dari message_ts / message_iso =====
$tz = new DateTimeZone('Asia/Jakarta');
$createdDt = null; $createdFrom = 'now';
if (isset($data['message_ts']) && is_numeric($data['message_ts']) && (int)$data['message_ts'] > 0) {
  $createdDt = new DateTime('@'.(int)$data['message_ts']); // UTC
  $createdDt->setTimezone($tz);
  $createdFrom = 'message_ts';
} elseif (!empty($data['message_iso'])) {
  try {
    $dtISO = new DateTime((string)$data['message_iso']);
    $dtISO->setTimezone($tz);
    $createdDt = $dtISO;
    $createdFrom = 'message_iso';
  } catch (Throwable $e) {}
}
if (!$createdDt) { $createdDt = new DateTime('now', $tz); }
$created_at = $createdDt->format('Y-m-d H:i:s');

try {
  $pdo = new PDO($dsn,$dbUser,$dbPass,[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
  ]);
} catch(Throwable $e){ jerr('db connect failed',500, $debug?['detail'=>$e->getMessage()]:[]); }

$nomor = gen_nomor_form($pdo,$server,$createdDt);

try{
  // Kolom mengikuti skema BARU (tanpa changelog)
  $sql='INSERT INTO requests
        (dev_requestor, nomor_form, server, site, project, service, source_branch,
         latest_version, new_version, message_ts, status, created_at)
        VALUES (:dev, :no, :srv, :site, :prj, :svc, :br,
                :latest_version, :new_version, :message_ts, :st, :created_at)';
  $st=$pdo->prepare($sql);
  $st->execute([
    ':dev'            => $devReq,
    ':no'             => $nomor,
    ':srv'            => $server,
    ':site'           => $site,
    ':prj'            => $project,
    ':svc'            => $service,
    ':br'             => $branch,
    ':latest_version' => ($verLast !== '' ? $verLast : null),
    ':new_version'    => ($verNew  !== '' ? $verNew  : null),
    ':message_ts'     => (isset($data['message_ts']) && is_numeric($data['message_ts']) ? (int)$data['message_ts'] : null),
    ':st'             => $status,
    ':created_at'     => $created_at
  ]);

  $resp = ['id'=>(int)$pdo->lastInsertId(),'nomor_form'=>$nomor,'created_at'=>$created_at];
  if ($debug) $resp += ['created_from'=>$createdFrom,'latest_version'=>$verLast,'new_version'=>$verNew];
  jok($resp);
}catch(Throwable $e){
  jerr('insert failed',500, $debug?['detail'=>$e->getMessage()]:[]);
}

