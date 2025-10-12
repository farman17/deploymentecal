<?php
// deployweb/hooks/ingest_chat.php
const INGEST_TOKEN = '4f9a7c2e1e9c4f6f2d5b1a9c0e7d3a12b6c9f1e4d7a8b2c3d4e5f6a7b8c9d0e1';

$dsn='mysql:host=localhost;dbname=deployweb;charset=utf8mb4';
$dbUser='root'; $dbPass='';

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
  // Gunakan tanggal dari pesan (createdDt) agar konsisten di nomor form
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
$data  = null;
if (str_contains($ctype,'application/json'))       $data = json_decode($raw,true);
elseif (str_contains($ctype,'application/x-www-form-urlencoded') || str_contains($ctype,'multipart/form-data')) $data = $_POST;
else { parse_str($raw,$maybe); if ($maybe) $data=$maybe; }
if (!is_array($data)) $data = [];

$debug = isset($_GET['debug']) || (($_SERVER['HTTP_X_DEBUG'] ?? '')==='1');

$need = ['dev_requestor','server','site','project','service','source_branch','changelog'];
$miss = [];
foreach($need as $k){ if(!isset($data[$k]) || trim((string)$data[$k])==='') $miss[]=$k; }
if ($miss){
  $extra = $debug ? ['received_keys'=>array_keys($data),'content_type'=>$ctype,'raw'=>$raw] : [];
  jerr('missing fields',422,['fields'=>$miss]+$extra);
}

$server  = strtoupper(trim($data['server']))==='PRODUCTION' ? 'PRODUCTION' : 'STAGING';
$site    = strtoupper(trim($data['site']));
$project = strtoupper(trim($data['project']));
$service = trim((string)$data['service']);
$branch  = trim((string)$data['source_branch']);   // nama field sudah benar (source_branch)
$chlog   = trim((string)$data['changelog']);
$devReq  = trim((string)$data['dev_requestor']);
$status  = strtoupper($data['status'] ?? 'OPEN');

// ===== Tentukan created_at dari message_ts / message_iso =====
$tz = new DateTimeZone('Asia/Jakarta');
$createdDt = null; $createdFrom = 'now';
if (isset($data['message_ts']) && is_numeric($data['message_ts']) && (int)$data['message_ts'] > 0) {
  $createdDt = new DateTime('@'.(int)$data['message_ts']); // UTC
  $createdDt->setTimezone($tz);
  $createdFrom = 'message_ts';
} elseif (!empty($data['message_iso'])) {
  try {
    // Asumsikan ISO dalam UTC jika berakhiran Z, kalau tidak gunakan parser default
    $dtISO = new DateTime((string)$data['message_iso']);
    $dtISO->setTimezone($tz);
    $createdDt = $dtISO;
    $createdFrom = 'message_iso';
  } catch (Throwable $e) { /* fallback below */ }
}
if (!$createdDt) { $createdDt = new DateTime('now', $tz); }
$created_at = $createdDt->format('Y-m-d H:i:s');

try {
  $pdo = new PDO($dsn,$dbUser,$dbPass,[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
  ]);
} catch(Throwable $e){ jerr('db connect failed',500, $debug?['detail'=>$e->getMessage()]:[]); }

// Nomor form ikut tanggal pesan
$nomor = gen_nomor_form($pdo,$server,$createdDt);

try{
  // created_at diisi sesuai waktu pesan WA (Asia/Jakarta), bukan NOW()
  $sql='INSERT INTO requests
        (dev_requestor, nomor_form, server, site, project, service, source_branch, changelog, status, created_at)
        VALUES (:dev, :no, :srv, :site, :prj, :svc, :br, :chg, :st, :created_at)';
  $st=$pdo->prepare($sql);
  $st->execute([
    ':dev'=>$devReq, ':no'=>$nomor, ':srv'=>$server, ':site'=>$site,
    ':prj'=>$project, ':svc'=>$service, ':br'=>$branch, ':chg'=>$chlog,
    ':st'=>$status, ':created_at'=>$created_at
  ]);

  $resp = ['id'=>(int)$pdo->lastInsertId(),'nomor_form'=>$nomor,'created_at'=>$created_at];
  if ($debug) $resp += ['created_from'=>$createdFrom];
  jok($resp);
}catch(Throwable $e){
  jerr('insert failed',500, $debug?['detail'=>$e->getMessage()]:[]);
}
