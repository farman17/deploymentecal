<?php
/* requests.php — UI ringan monitoring deploy (kolom rapi + align) */

$DB_HOST = getenv('DB_HOST') ?: 'db';
$DB_PORT = getenv('DB_PORT') ?: '3306';
$DB_NAME = getenv('DB_NAME') ?: 'deploymentecal';
$DB_USER = getenv('DB_USER') ?: 'deployuser';
$DB_PASS = getenv('DB_PASS') ?: 'secret';

$dsn = "mysql:host={$DB_HOST};port={$DB_PORT};dbname={$DB_NAME};charset=utf8mb4";
date_default_timezone_set('Asia/Jakarta');
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }

$q       = trim($_GET['q'] ?? '');
$server  = strtoupper(trim($_GET['server'] ?? ''));
$project = strtoupper(trim($_GET['project'] ?? ''));
$status  = strtoupper(trim($_GET['status'] ?? ''));
$from    = trim($_GET['from'] ?? '');
$to      = trim($_GET['to'] ?? '');
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = min(max((int)($_GET['pp'] ?? 20), 5), 200);
$sort    = $_GET['sort'] ?? 'created_at';
$dir     = strtolower($_GET['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
$export  = $_GET['export'] ?? '';

$sortable = ['server','site','project','service','latest_version','new_version','created_at','updated_at','nomor_form'];
if(!in_array($sort, $sortable, true)) $sort = 'created_at';

$where=[]; $params=[];
if($q!==''){
  $where[]="(nomor_form LIKE :kw OR dev_requestor LIKE :kw OR site LIKE :kw OR project LIKE :kw
             OR service LIKE :kw OR source_branch LIKE :kw OR latest_version LIKE :kw OR new_version LIKE :kw)";
  $params[':kw']="%$q%";
}
if($server!==''){ $where[]="server = :srv"; $params[':srv']=$server; }
if($project!==''){ $where[]="project = :prj"; $params[':prj']=$project; }
if($status!==''){ $where[]="status = :st"; $params[':st']=$status; }
if($from!=='' && preg_match('/^\d{4}-\d{2}-\d{2}$/',$from)){ $where[]="created_at >= :fromd"; $params[':fromd']=$from.' 00:00:00'; }
if($to!=='' && preg_match('/^\d{4}-\d{2}-\d{2}$/',$to)){ $where[]="created_at < :tod"; $params[':tod']=date('Y-m-d', strtotime($to.' +1 day')).' 00:00:00'; }
$sqlWhere = $where?('WHERE '.implode(' AND ',$where)):'';

try{
  $pdo = new PDO($dsn,$DB_USER,$DB_PASS,[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
  ]);
}catch(Throwable $e){ http_response_code(500); echo '<pre>DB connect failed: '.h($e->getMessage()).'</pre>'; exit; }

if($export==='csv'){
  $sql="SELECT server,site,project,service,latest_version,new_version,created_at,updated_at
        FROM requests $sqlWhere ORDER BY $sort $dir LIMIT 100000";
  $st=$pdo->prepare($sql); $st->execute($params);
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="requests_'.date('Ymd_His').'.csv"');
  $out=fopen('php://output','w');
  fputcsv($out,['server','site','project','service','latest_version','new_version','created_at','updated_at']);
  while($r=$st->fetch()){ fputcsv($out,$r); }
  fclose($out); exit;
}

$stc=$pdo->prepare("SELECT COUNT(*) FROM requests $sqlWhere"); $stc->execute($params); $total=(int)$stc->fetchColumn();
$offset=($page-1)*$perPage;
$sql="SELECT server, site, project, service, latest_version, new_version, created_at
      FROM requests $sqlWhere ORDER BY $sort $dir LIMIT :lim OFFSET :off";
$st=$pdo->prepare($sql);
foreach($params as $k=>$v){ $st->bindValue($k,$v); }
$st->bindValue(':lim',$perPage,PDO::PARAM_INT);
$st->bindValue(':off',$offset,PDO::PARAM_INT);
$st->execute();
$rows=$st->fetchAll();

function url_with($over){
  $q=array_merge($_GET,$over);
  foreach($q as $k=>$v){ if($v===''||$v===null) unset($q[$k]); }
  return '?'.http_build_query($q);
}
function badge($text){
  $colors=['STAGING'=>'#6366f1','PRODUCTION'=>'#966b9cff'];
  $bg=$colors[$text] ?? '#0ea5e9';
  return '<span class="badge" style="background:'.$bg.'">'.h($text).'</span>';
}
?>
<!doctype html><html lang="id"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>DevOps Deploy Monitoring</title>
<style>
:root{ --bg:#0b1220; --fg:#e5e7eb; --muted:#9ca3af; --card:#0f172a; --line:#1d2636; --accent2:#3b82f6; }
*{box-sizing:border-box}
body{margin:0; font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu; background:var(--bg); color:var(--fg)}
a{color:var(--accent2); text-decoration:none}
.wrap{max-width:1680px; margin:20px auto; padding:0 20px}
.card{background:var(--card); border:1px solid var(--line); border-radius:12px; padding:12px}
.toolbar{display:grid; grid-template-columns:1fr auto; gap:10px; align-items:end}
.filters{display:grid; grid-template-columns:repeat(7,1fr); gap:6px}
label{font-size:11.5px; color:var(--muted)}
input,select{width:100%; padding:7px 9px; border-radius:8px; border:1px solid var(--line); background:#0b1328; color:var(--fg); font-size:13px}
.btn{display:inline-block; padding:8px 12px; border-radius:8px; border:1px solid var(--line); background:#0b1328; color:var(--fg); cursor:pointer; font-size:13px}
.btn.primary{background:linear-gradient(135deg,#2563eb,#10b981); border:none}

/* TABLE (dense) */
.table-wrap{overflow:auto; border-radius:10px; margin-top:8px}
.table{
  width:100%;
  min-width:1120px;          /* lebih kecil dari sebelumnya */
  border-collapse:separate;
  border-spacing:0;
  table-layout:fixed;        /* sesuai colgroup */
}
.table th,.table td{
  padding:6px 8px;           /* << pad mengecil */
  border-bottom:1px solid var(--line);
  vertical-align:middle;
  font-size:13px;            /* << font sedikit lebih kecil */
  line-height:1.2;
  white-space:nowrap;
  overflow:hidden;
  text-overflow:ellipsis;
}
.table thead th{
  font-weight:600;
  position:sticky; top:0; z-index:1;
  background:var(--card);
}
/* link header tidak mengubah align */
.table thead th > a{
  display:flex; align-items:center; justify-content:center; gap:4px; width:100%;
}
.table thead th .arr{opacity:.6; font-size:10px}
.table tbody tr:nth-child(even){background:rgba(255,255,255,.015)}
.table tbody tr:hover{background:rgba(59,130,246,.06)}
.center{text-align:center}
.mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:11.5px}
.badge{padding:2px 6px; border-radius:999px; font-size:11.5px; color:#fff; display:inline-block}
.badge.service{background:#424242}

/* versi di-center + chip lebih pendek */
.ver{text-align:center}
.ver .chip{display:inline-block; max-width:180px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; vertical-align:middle}
.ver .chip code{background:#0b1328; border:1px solid var(--line); padding:1px 5px; border-radius:5px; display:inline-block}

.pager{display:flex; gap:6px; align-items:center; justify-content:flex-end; margin-top:10px}
.num{padding:5px 9px; border-radius:7px; border:1px solid var(--line); background:#0b1328}

/* bantu alignment global per kolom */
td:nth-child(1), th:nth-child(1),
td:nth-child(2), th:nth-child(2),
td:nth-child(4), th:nth-child(4),
td:nth-child(5), th:nth-child(5),
td:nth-child(6), th:nth-child(6){ text-align:center; }
</style>

</head>
<body>
<div class="wrap">
  <h2 style="margin:0 0 12px; display:flex; gap:10px; align-items:center">
    DevOps Deploy Monitoring
    <span class="mono" style="color:var(--muted)">— <?=h(number_format($total))?> entri</span>
  </h2>

  <div class="card">
    <form method="get" class="toolbar">
      <div class="filters">
        <div><label>Search</label><input type="text" name="q" value="<?=h($q)?>" placeholder="nomor, service, version..."></div>
        <div><label>Server</label>
          <select name="server">
            <option value="">(All)</option>
            <option value="STAGING" <?= $server==='STAGING'?'selected':''?>>STAGING</option>
            <option value="PRODUCTION" <?= $server==='PRODUCTION'?'selected':''?>>PRODUCTION</option>
          </select>
        </div>
        <div><label>Project</label>
          <select name="project">
            <option value="">(All)</option>
            <?php foreach(['BACKEND-JAVA','BACK-OFFICE-JAVA','WEB-EMR','BACKEND-SITE'] as $p): ?>
              <option value="<?=$p?>" <?=$project===$p?'selected':''?>><?=$p?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div><label>Dari Tanggal</label><input type="date" name="from" value="<?=h($from)?>"></div>
        <div><label>Sampai Tanggal</label><input type="date" name="to" value="<?=h($to)?>"></div>
        <div><label>Per Halaman</label>
          <select name="pp">
            <?php foreach([10,20,50,100,200] as $pp): ?>
              <option value="<?=$pp?>" <?=$perPage===$pp?'selected':''?>><?=$pp?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div><button class="btn primary" type="submit">Terapkan</button> <a class="btn" href="requests.php">Reset</a></div>
      </div>
    </form>

    <div class="table-wrap">
      <table class="table">
        <!-- lebar pasti per-kolom -->
<colgroup>
  <col style="width:110px">  <!-- Server -->
  <col style="width:80px">   <!-- Site -->
  <col style="width:220px">  <!-- Project -->
  <col style="width:160px">  <!-- Service -->
  <col style="width:210px">  <!-- Latest -->
  <col style="width:210px">  <!-- New -->
  <col style="width:160px">  <!-- Created -->
</colgroup>


        <thead>
          <tr>
            <?php
              function th($label,$key,$sort,$dir){
                $is=($sort===$key); $next=($is && $dir==='ASC')?'desc':'asc';
                $arrow=$is?($dir==='ASC'?'▲':'▼'):'';
                echo '<th><a href="'.h(url_with(['sort'=>$key,'dir'=>$next,'page'=>1])).'"><span>'
                    .h($label).'</span>'.($arrow?'<span class="arr">'.$arrow.'</span>':'').'</a></th>';
              }
              th('Server','server',$sort,$dir);
              th('Site','site',$sort,$dir);
              th('Project','project',$sort,$dir);
              th('Service','service',$sort,$dir);
              th('Latest Version','latest_version',$sort,$dir);
              th('New Version','new_version',$sort,$dir);
              th('Created','created_at',$sort,$dir);
            ?>
          </tr>
        </thead>

        <tbody>
          <?php if(!$rows): ?>
            <tr><td colspan="7" class="mono" style="color:#9ca3af; text-align:center">Tidak ada data.</td></tr>
          <?php else: foreach($rows as $r): ?>
            <tr>
              <td class="mono"><?=h($r['server'])?></td>
              <td><?=badge($r['site'])?></td>
              <td class="mono"><?=h($r['project'])?></td>
              <td><span class="badge service"><?=h($r['service'])?></span></td>
              <?php $lv=trim((string)$r['latest_version']); $nv=trim((string)$r['new_version']); ?>
              <td class="ver"><?= $lv===''?'<span style="color:#9ca3af">—</span>':'<span class="chip" title="'.h($lv).'"><code class="mono">'.h($lv).'</code></span>' ?></td>
              <td class="ver"><?= $nv===''?'<span style="color:#9ca3af">—</span>':'<span class="chip" title="'.h($nv).'"><code class="mono">'.h($nv).'</code></span>' ?></td>
              <td class="mono"><?=h($r['created_at'])?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <?php
      $lastPage=max(1,(int)ceil($total/$perPage));
      $prev=max(1,$page-1); $next=min($lastPage,$page+1);
    ?>
    <div class="pager">
      <span class="mono" style="color:#9ca3af">Halaman <?=h($page)?> / <?=h($lastPage)?> • Total <?=h(number_format($total))?></span>
      <a class="btn" href="<?=h(url_with(['page'=>1]))?>">« First</a>
      <a class="btn" href="<?=h(url_with(['page'=>$prev]))?>">‹ Prev</a>
      <span class="num"><?=h($page)?></span>
      <a class="btn" href="<?=h(url_with(['page'=>$next]))?>">Next ›</a>
      <a class="btn" href="<?=h(url_with(['page'=>$lastPage]))?>">Last »</a>
    </div>
  </div>
</div>
</body></html>
