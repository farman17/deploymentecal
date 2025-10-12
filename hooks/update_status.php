<?php
// hooks/update_status.php
const INGEST_TOKEN = '4f9a7c2e1e9c4f6f2d5b1a9c0e7d3a12b6c9f1e4d7a8b2c3d4e5f6a7b8c9d0e1';

$dsn='mysql:host=localhost;dbname=deployweb;charset=utf8mb4';
$dbUser='root'; $dbPass='';

function jerr($m,$c=400,$x=[]){
  http_response_code($c); header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok'=>false,'error'=>$m]+$x,JSON_UNESCAPED_UNICODE); exit;
}
function jok($d=[]){
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok'=>true]+$d,JSON_UNESCAPED_UNICODE); exit;
}

if($_SERVER['REQUEST_METHOD']!=='POST') jerr('method not allowed',405);
if(!hash_equals(INGEST_TOKEN, $_SERVER['HTTP_X_INGEST_TOKEN'] ?? '')) jerr('unauthorized',401);

$ct=strtolower($_SERVER['CONTENT_TYPE']??''); $raw=file_get_contents('php://input');
if(str_contains($ct,'json')) $in=json_decode($raw,true);
elseif(str_contains($ct,'x-www-form-urlencoded')) $in=$_POST;
else parse_str($raw,$in);
if(!is_array($in)) $in=[];

$nomor_form = trim((string)($in['nomor_form']??''));
$server     = strtoupper(trim((string)($in['server']??'')));
$project    = strtoupper(trim((string)($in['project']??'')));
$service    = strtolower(trim((string)($in['service']??'')));
$site       = strtoupper(trim((string)($in['site']??'')));       // optional
$from       = strtoupper(trim((string)($in['status_from']??''))); // optional
$to         = strtoupper(trim((string)($in['status_to']??'')));
$updateAll  = !empty($in['update_all']) && (
               $in['update_all']===true || $in['update_all']==='1' || $in['update_all']==='true' || $in['update_all']==='yes'
             );

if($to==='') jerr('status_to required',422);

try{
  $pdo=new PDO($dsn,$dbUser,$dbPass,[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
  ]);
}catch(Throwable $e){ jerr('db connect failed',500,['detail'=>$e->getMessage()]); }

// helper builder
$buildWhere = function($base=[]) use($server,$project,$service,$site,$from){
  $where=[]; $p=[];
  if(isset($base['server'])){ $where[]='server = :srv'; $p[':srv']=$base['server']; }
  if(isset($base['project'])){ $where[]='project = :prj'; $p[':prj']=$base['project']; }
  if(isset($base['service'])){ $where[]='service = :svc'; $p[':svc']=$base['service']; }
  if(isset($base['site']) && $base['site']!==''){ $where[]='site = :site'; $p[':site']=$base['site']; }
  if(isset($base['from']) && $base['from']!==''){ $where[]='status = :stf'; $p[':stf']=$base['from']; }
  return [$where,$p];
};

if($nomor_form!==''){
  // 1) ada nomor_form
  $row = $pdo->prepare("SELECT id,nomor_form,server,project,service,site,status FROM requests WHERE nomor_form=? LIMIT 1");
  $row->execute([$nomor_form]);
  $row = $row->fetch();
  if(!$row) jerr('no matching row',404);

  if(!$updateAll){
    // update SINGEL
    $upd=$pdo->prepare("UPDATE requests SET status=:to, updated_at=NOW() WHERE id=:id"
      . ($from!=='' ? " AND status=:from" : ""));
    $params=[':to'=>$to, ':id'=>$row['id']]; if($from!=='') $params[':from']=$from;
    $upd->execute($params);
    jok(['mode'=>'single','id'=>(int)$row['id'],'nomor_form'=>$row['nomor_form'],'from'=>$row['status'],'to'=>$to]);
  }else{
    // bulk dengan ciri baris ini (server+project+service+site)
    [$w,$p] = $buildWhere([
      'server'=>$row['server'],
      'project'=>$row['project'],
      'service'=>$row['service'],
      'site'   =>$row['site'],
      'from'   =>$from
    ]);
    $sel=$pdo->prepare("SELECT id,nomor_form FROM requests WHERE ".implode(' AND ',$w)." ORDER BY id DESC");
    $sel->execute($p);
    $list=$sel->fetchAll();
    if(!$list) jerr('no matching row',404,['hint'=>'nothing matches same attributes']);

    $p[':to']=$to;
    $upd=$pdo->prepare("UPDATE requests SET status=:to, updated_at=NOW() WHERE ".implode(' AND ',$w));
    $upd->execute($p);

    jok([
      'mode'=>'bulk-from-nomor',
      'match'=>['server'=>$row['server'],'project'=>$row['project'],'service'=>$row['service'],'site'=>$row['site'],'from'=>$from?:null,'to'=>$to],
      'affected'=>count($list),
      'nomor_forms'=>array_column($list,'nomor_form')
    ]);
  }
}else{
  // 2) tanpa nomor_form → gunakan kombinasi
  if($server===''||$project===''||$service==='') jerr('provide nomor_form OR (server, project, service)',422);

  [$w,$p] = $buildWhere([
    'server'=>($server==='PRODUCTION'?'PRODUCTION':'STAGING'),
    'project'=>$project,
    'service'=>$service,
    'site'=>$site,
    'from'=>$from
  ]);

  if(!$updateAll){
    // pilih satu terbaru seperti lama
    $sel=$pdo->prepare("SELECT id,nomor_form,status FROM requests WHERE ".implode(' AND ',$w)." ORDER BY id DESC LIMIT 1");
    $sel->execute($p);
    $row=$sel->fetch();
    if(!$row) jerr('no matching row',404,['hint'=>'cek kombinasi atau status_from']);
    $upd=$pdo->prepare("UPDATE requests SET status=:to, updated_at=NOW() WHERE id=:id");
    $upd->execute([':to'=>$to, ':id'=>$row['id']]);
    jok(['mode'=>'single-latest','id'=>(int)$row['id'],'nomor_form'=>$row['nomor_form'],'from'=>$row['status'],'to'=>$to]);
  }else{
    // BULK: update semua yang match
    $sel=$pdo->prepare("SELECT id,nomor_form FROM requests WHERE ".implode(' AND ',$w)." ORDER BY id DESC");
    $sel->execute($p);
    $list=$sel->fetchAll();
    if(!$list) jerr('no matching row',404,['hint'=>'cek kombinasi atau status_from']);

    $p[':to']=$to;
    $upd=$pdo->prepare("UPDATE requests SET status=:to, updated_at=NOW() WHERE ".implode(' AND ',$w));
    $upd->execute($p);

    jok([
      'mode'=>'bulk',
      'match'=>['server'=>$server,'project'=>$project,'service'=>$service,'site'=>$site?:null,'from'=>$from?:null,'to'=>$to],
      'affected'=>count($list),
      'nomor_forms'=>array_column($list,'nomor_form')
    ]);
  }
}
