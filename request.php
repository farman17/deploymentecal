<?php
// requests.php — UI ringan untuk melihat tabel requests

// ==== DB config via ENV (fallback ke nilai compose/run) ====
$DB_HOST = getenv('DB_HOST') ?: 'db';
$DB_PORT = getenv('DB_PORT') ?: '3306';
$DB_NAME = getenv('DB_NAME') ?: 'deploymentecal';
$DB_USER = getenv('DB_USER') ?: 'deployuser';
$DB_PASS = getenv('DB_PASS') ?: 'secret';

$dsn    = "mysql:host={$DB_HOST};port={$DB_PORT};dbname={$DB_NAME};charset=utf8mb4";
$dbUser = $DB_USER;
$dbPass = $DB_PASS;

date_default_timezone_set('Asia/Jakarta');

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

// ===== Input & default =====
$q        = trim($_GET['q'] ?? '');
$server   = strtoupper(trim($_GET['server'] ?? ''));
$project  = strtoupper(trim($_GET['project'] ?? ''));
$status   = strtoupper(trim($_GET['status'] ?? '')); // tetap didukung via querystring, tapi tidak ditampilkan di UI
$from     = trim($_GET['from'] ?? '');
$to       = trim($_GET['to'] ?? '');
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = min(max((int)($_GET['pp'] ?? 20), 5), 200);
$sort     = $_GET['sort'] ?? 'created_at';
$dir      = strtolower($_GET['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
$export   = $_GET['export'] ?? '';

// kolom yang boleh disort (status dihapus dari daftar)
$sortable = [
  'nomor_form','server','site','project','service',
  'latest_version','new_version',
  'created_at','updated_at'
];
if(!in_array($sort, $sortable, true)) $sort = 'created_at';

// ===== Build WHERE =====
$where=[];$params=[];
if($q!==''){
  $where[]="(nomor_form LIKE :kw OR dev_requestor LIKE :kw OR site LIKE :kw OR project LIKE :kw
             OR service LIKE :kw OR source_branch LIKE :kw
             OR latest_version LIKE :kw OR new_version LIKE :kw)";
  $params[':kw']="%$q%";
}
if($server!==''){ $where[]="server = :srv"; $params[':srv']=$server==='PRODUCTION'?'PRODUCTION':'STAGING'; }
if($project!==''){ $where[]="project = :prj"; $params[':prj']=$project; }
if($status!==''){ $where[]="status = :st"; $params[':st']=$status; }
if($from!=='' && preg_match('/^\d{4}-\d{2}-\d{2}$/',$from)){ $where[]="created_at >= :fromd"; $params[':fromd']=$from.' 00:00:00'; }
if($to!=='' && preg_match('/^\d{4}-\d{2}-\d{2}$/',$to)){ $where[]="created_at < :tod"; $params[':tod']=date('Y-m-d', strtotime($to.' +1 day')).' 00:00:00'; }
$sqlWhere = $where?('WHERE '.implode(' AND ',$where)):'';

// ===== Koneksi DB =====
try{
  $pdo = new PDO($dsn,$dbUser,$dbPass,[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
  ]);
}catch(Throwable $e){
  http_response_code(500);
  echo '<pre>DB connect failed: '.h($e->getMessage()).'</pre>'; exit;
}

// ===== CSV Export (tanpa kolom status) =====
if($export==='csv'){
  $sql="SELECT id, nomor_form, dev_requestor, server, site, project, service, source_branch,
               latest_version, new_version,
               created_at, updated_at
        FROM requests $sqlWhere ORDER BY $sort $dir LIMIT 100000";
  $st=$pdo->prepare($sql); $st->execute($params);
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename=\"requests_'.date('Ymd_His').'.csv\"');
  $out=fopen('php://output','w');
  fputcsv($out,[
    'id','nomor_form','dev_requestor','server','site','project','service','source_branch',
    'latest_version','new_version','created_at','updated_at'
  ]);
  while($r=$st->fetch()){ fputcsv($out,$r); }
  fclose($out); exit;
}

// ===== Hitung total & ambil data halaman =====
$stc=$pdo->prepare("SELECT COUNT(*) FROM requests $sqlWhere"); $stc->execute($params); $total=(int)$stc->fetchColumn();

$offset=($page-1)*$perPage;
$sql="SELECT id, nomor_form, dev_requestor, server, site, project, service, source_branch,
             latest_version, new_version,
             created_at, updated_at
      FROM requests $sqlWhere ORDER BY $sort $dir LIMIT :lim OFFSET :off";
$st=$pdo->prepare($sql);
foreach($params as $k=>$v){ $st->bindValue($k,$v); }
$st->bindValue(':lim',$perPage,PDO::PARAM_INT);
$st->bindValue(':off',$offset,PDO::PARAM_INT);
$st->execute();
$rows=$st->fetchAll();

// ===== Helper UI =====
function url_with($over){
  $q=array_merge($_GET,$over);
  foreach($q as $k=>$v){ if($v===''||$v===null) unset($q[$k]); }
  return '?'.http_build_query($q);
}
function badge($text,$type){
  $colors = [
    'STAGING'     => '#6366f1',
    'PRODUCTION'  => '#966b9cff',
  ];
  $bg = $colors[$text] ?? '#0ea5e9';
  return '<span class="badge" style="background:'.$bg.'">'.h($text).'</span>';
}
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>DevOps Requests Monitoring</title>
<style>
:root{
  --bg:#0b1220; --fg:#e5e7eb; --muted:#9ca3af; --card:#0f172a; --line:#1f2937;
  --accent:#22c55e; --accent2:#3b82f6;
}
*{box-sizing:border-box}
body{margin:0; font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu; background:var(--bg); color:var(--fg)}
a{color:var(--accent2); text-decoration:none}
.wrap{max-width:1680px; margin:24px auto; padding:0 24px}
.card{background:var(--card); border:1px solid var(--line); border-radius:14px; padding:16px; box-shadow:0 10px 30px rgba(0,0,0,.25)}
.toolbar{display:grid; grid-template-columns:1fr auto; gap:12px; align-items:end}
.filters{display:grid; grid-template-columns:repeat(7,1fr); gap:8px} /* 7 kolom filter (status dihapus) */
label{font-size:12px; color:var(--muted)}
input,select{width:100%; padding:9px 10px; border-radius:10px; border:1px solid var(--line); background:#0b1328; color:var(--fg)}
.btn{display:inline-block; padding:10px 14px; border-radius:10px; border:1px solid var(--line); background:#0b1328; color:var(--fg); cursor:pointer}
.btn.primary{background:linear-gradient(135deg,#2563eb,#10b981); border:none}

/* TABLE */
.table-wrap{ overflow:auto; border-radius:12px; }
.table{width:100%; min-width:1200px; border-collapse:separate; border-spacing:0; margin-top:14px; table-layout:fixed;}
.table th,.table td{padding:10px 12px; border-bottom:1px solid var(--line); vertical-align:middle; font-size:14px; white-space:nowrap;}
.table th{font-weight:600; text-align:left; position:sticky; top:0; background:var(--card); z-index:1}
.table tr:nth-child(even){background:rgba(255,255,255,.02)}
.table tbody tr:hover{background:rgba(59,130,246,.07)}

/* kolom yang boleh wrap/ellipsis */
.cell-id{white-space:normal; word-break:break-all}
.cell-ver{max-width:220px; overflow:hidden; text-overflow:ellipsis}
.cell-ver code{background:#0b1328; border:1px solid var(--line); padding:2px 6px; border-radius:6px; display:inline-block}
.text-center{text-align:center}
.mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:12px}
.nowrap{white-space:nowrap}
.badge{padding:3px 8px; border-radius:999px; font-size:12px; color:#fff; display:inline-block}
.badge.service{ background:#454545; }
.muted{color:var(--muted)}
.pager{display:flex; gap:8px; align-items:center; justify-content:flex-end; margin-top:10px}
.num{padding:6px 10px; border-radius:8px; border:1px solid var(--line); background:#0b1328}
.grid-2{display:grid; grid-template-columns:1fr auto; gap:8px; align-items:center}
</style>
</head>
<body>
<div class="wrap">
  <h2 style="margin:0 0 12px; display:flex; gap:10px; align-items:center">
    DevOps Requests Monitoring
    <span class="muted" style="font-size:14px">— <?=h(number_format($total))?> entri</span>
  </h2>

  <div class="card">
    <form method="get" class="toolbar">
      <div class="filters">
        <div><label>Search</label>
          <input type="text" name="q" value="<?=h($q)?>" placeholder="nomor, service, version...">
        </div>
        <div><label>Server</label>
          <select name="server">
            <option value="">(All)</option>
            <option value="STAGING"    <?= $server==='STAGING'?'selected':''?>>STAGING</option>
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
        <!-- Status filter sengaja disembunyikan dari UI -->
        <div><label>Dari Tanggal</label><input type="date" name="from" value="<?=h($from)?>"></div>
        <div><label>Sampai Tanggal</label><input type="date" name="to" value="<?=h($to)?>"></div>
        <div><label>Per Halaman</label>
          <select name="pp">
            <?php foreach([10,20,50,100,200] as $pp): ?>
              <option value="<?=$pp?>" <?=$perPage===$pp?'selected':''?>><?=$pp?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="grid-2">
          <button class="btn primary" type="submit">Terapkan</button>
          <a class="btn" href="requests.php">Reset</a>
        </div>
      </div>
    </form>

    <div class="table-wrap">
      <table class="table">
        <colgroup>
          <col style="width:230px"><col style="width:110px">
          <col style="width:90px"><col style="width:170px"><col style="width:180px">
          <col style="width:230px"><!-- latest -->
          <col style="width:230px"><!-- new -->
          <col style="width:160px"><col style="width:160px">
        </colgroup>

        <thead>
          <tr>
            <?php
              function th($label,$key,$sort,$dir){
                $is=($sort===$key); $next=($is && $dir==='ASC')?'desc':'asc'; $arrow=$is?($dir==='ASC'?'▲':'▼'):'';
                echo '<th><a href="'.h(url_with(['sort'=>$key,'dir'=>$next,'page'=>1])).'">'.h($label).($arrow?' <span class="muted">'.$arrow.'</span>':'').'</a></th>';
              }
              th('Nomor Tiket','nomor_form',$sort,$dir);
              th('Server','server',$sort,$dir);
              th('Site','site',$sort,$dir);
              th('Project','project',$sort,$dir);
              th('Service','service',$sort,$dir);
              th('Latest Version','latest_version',$sort,$dir);
              th('New Version','new_version',$sort,$dir);
              th('Created','created_at',$sort,$dir);
              th('Updated','updated_at',$sort,$dir);
            ?>
          </tr>
        </thead>

        <tbody>
          <?php if(!$rows): ?>
            <tr><td colspan="9" class="muted">Tidak ada data.</td></tr>
          <?php else: foreach($rows as $r): ?>
            <tr>
              <td class="mono cell-id"><?=h($r['nomor_form'])?></td>
              <td class="mono text-center"><?=h($r['server'])?></td>
              <td class="text-center"><span class="badge"><?=h($r['site'])?></span></td>
              <td class="mono"><?=h($r['project'])?></td>
              <td class="text-center"><span class="badge service"><?=h($r['service'])?></span></td>

              <?php
                $lv = trim((string)$r['latest_version']);
                $nv = trim((string)$r['new_version']);
              ?>
              <td class="cell-ver"><?= $lv === '' ? '<span class="muted">—</span>' : '<span class="cell-ver" title="'.h($lv).'"><code class="mono">'.h($lv).'</code></span>' ?></td>
              <td class="cell-ver"><?= $nv === '' ? '<span class="muted">—</span>' : '<span class="cell-ver" title="'.h($nv).'"><code class="mono">'.h($nv).'</code></span>' ?></td>

              <td class="mono nowrap"><?=h($r['created_at'])?></td>
              <td class="mono nowrap"><?=h($r['updated_at'])?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <?php
      $lastPage=max(1,(int)ceil($total/$perPage)); $prev=max(1,$page-1); $next=min($lastPage,$page+1);
    ?>
    <div class="pager">
      <span class="muted">Halaman <?=h($page)?> / <?=h($lastPage)?> • Total <?=h(number_format($total))?></span>
      <a class="btn" href="<?=h(url_with(['page'=>1]))?>">« First</a>
      <a class="btn" href="<?=h(url_with(['page'=>$prev]))?>">‹ Prev</a>
      <span class="num"><?=h($page)?></span>
      <a class="btn" href="<?=h(url_with(['page'=>$next]))?>">Next ›</a>
      <a class="btn" href="<?=h(url_with(['page'=>$lastPage]))?>">Last »</a>
    </div>
  </div>
</div>

<style>
@keyframes flashRow { 0%{background:rgba(34,197,94,.18)} 100%{background:transparent} }
.tr-new { animation: flashRow 1.2s ease-out; }
</style>

<script>
(() => {
  const parser = new DOMParser();
  let lastSwapAt = 0;
  let isSwapping = false;

  async function softRefresh() {
    if (document.hidden || isSwapping) return;
    isSwapping = true;
    try {
      const res  = await fetch(location.href, { cache: 'no-store' });
      const html = await res.text();
      const doc  = parser.parseFromString(html, 'text/html');

      const newTbody = doc.querySelector('table.table tbody');
      const oldTbody = document.querySelector('table.table tbody');
      const newPager = doc.querySelector('.pager');
      const oldPager = document.querySelector('.pager');
      if (!newTbody || !oldTbody) return;

      const oldKeys = new Set([...oldTbody.querySelectorAll('tr')].map(tr => (tr.cells[0]?.textContent || '').trim()));
      oldTbody.replaceWith(newTbody);
      newTbody.querySelectorAll('tr').forEach(tr => {
        const key = (tr.cells[0]?.textContent || '').trim();
        if (!oldKeys.has(key)) tr.classList.add('tr-new');
      });

      if (newPager && oldPager) oldPager.replaceWith(newPager);
      lastSwapAt = Date.now();
    } catch (e) {
      // diamkan
    } finally {
      isSwapping = false;
    }
  }

  // Polling cadangan (fallback)
  setInterval(() => {
    if (document.hidden) return;
    if (Date.now() - lastSwapAt < 3000) return;
    softRefresh();
  }, 5000);

  document.addEventListener('visibilitychange', () => {
    if (!document.hidden) softRefresh();
  });
})();
</script>
</body>
</html>

