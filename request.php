<?php
/* requests.php — UI ringan monitoring deploy (rapi + align konsisten) */

$DB_HOST = getenv('DB_HOST') ?: 'db';
$DB_PORT = getenv('DB_PORT') ?: '3306';
$DB_NAME = getenv('DB_NAME') ?: 'deploymentecal';
$DB_USER = getenv('DB_USER') ?: 'deployuser';
$DB_PASS = getenv('DB_PASS') ?: 'secret';

$dsn = "mysql:host={$DB_HOST};port={$DB_PORT};dbname={$DB_NAME};charset=utf8mb4";
date_default_timezone_set('Asia/Jakarta');

// Nonaktifkan cache agar refresh selalu ambil data terbaru
if (!headers_sent()) {
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');
  header('Expires: 0');
}


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

/* NEW: filter service (penambahan variabel GET, tidak mengubah variabel yang ada) */
$service = trim($_GET['service'] ?? '');

/* tambahkan field baru ke daftar yang bisa disort */
$sortable = [
  'server','site','project','service',
  'latest_version','new_version',
  'git_author','git_short','git_title',   // <-- baru
  'created_at','updated_at','nomor_form'
];
if(!in_array($sort, $sortable, true)) $sort = 'created_at';

$where=[]; $params=[];
if($q!==''){
  $where[]="(nomor_form LIKE :kw OR dev_requestor LIKE :kw OR site LIKE :kw OR project LIKE :kw
             OR service LIKE :kw OR source_branch LIKE :kw OR latest_version LIKE :kw OR new_version LIKE :kw
             OR git_author LIKE :kw OR git_short LIKE :kw OR git_title LIKE :kw)";
  $params[':kw']="%$q%";
}
if($server!==''){ $where[]="server = :srv"; $params[':srv']=$server; }
if($project!==''){ $where[]="project = :prj"; $params[':prj']=$project; }
if($status!==''){ $where[]="status = :st"; $params[':st']=$status; }

/* NEW: tambah kondisi WHERE untuk filter service (tanpa mengubah kondisi lain) */
if($service!==''){ $where[] = "service = :svc"; $params[':svc'] = $service; }

if($from!=='' && preg_match('/^\d{4}-\d{2}-\d{2}$/',$from)){ $where[]="created_at >= :fromd"; $params[':fromd']=$from.' 00:00:00'; }
if($to!=='' && preg_match('/^\d{4}-\d{2}-\d{2}$/',$to)){ $where[]="created_at < :tod"; $params[':tod']=date('Y-m-d', strtotime($to.' +1 day')).' 00:00:00'; }
$sqlWhere = $where?('WHERE '.implode(' AND ',$where)):'';

try{
  $pdo = new PDO($dsn,$DB_USER,$DB_PASS,[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
  ]);
}catch(Throwable $e){ http_response_code(500); echo '<pre>DB connect failed: '.h($e->getMessage()).'</pre>'; exit; }

/* NEW: Ambil daftar service unik untuk dropdown (tanpa mengubah query yang lain) */
$services = [];
try {
  $stSvc = $pdo->query("SELECT DISTINCT service FROM requests WHERE service IS NOT NULL AND service <> '' ORDER BY service");
  $services = $stSvc->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch(Throwable $e) {
  $services = []; // fallback diam
}

/* == Export CSV == (biarkan seperti semula) */
if($export==='csv'){
  $sql="SELECT server,site,project,service,latest_version,new_version,created_at,updated_at
        FROM requests $sqlWhere ORDER BY $sort $dir LIMIT 100000";
  $st=$pdo->prepare($sql); $st->execute($params);
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename=\"requests_'.date('Ymd_His').'\".csv');
  $out=fopen('php://output','w');
  fputcsv($out,['server','site','project','service','latest_version','new_version','created_at','updated_at']);
  while($r=$st->fetch()){ fputcsv($out,$r); }
  fclose($out); exit;
}

/* == Query list == — tambah 3 kolom baru */
$stc=$pdo->prepare("SELECT COUNT(*) FROM requests $sqlWhere"); $stc->execute($params); $total=(int)$stc->fetchColumn();
$offset=($page-1)*$perPage;
$sql="SELECT
        server, site, project, service,
        latest_version, new_version,
        git_author, git_short, git_title,            -- baru
        created_at
      FROM requests
      $sqlWhere ORDER BY $sort $dir LIMIT :lim OFFSET :off";
$st=$pdo->prepare($sql);
foreach($params as $k=>$v){ $st->bindValue($k,$v); }
$st->bindValue(':lim',$perPage,PDO::PARAM_INT);
$st->bindValue(':off',$offset,PDO::PARAM_INT);
$st->execute();
$rows=$st->fetchAll();

/* == Helpers == */
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

function badge_site($text){
  return '<span class="badge site">'.h($text).'</span>';
}


function id_tanggal($ts){
  try {
    $dt = new DateTime($ts, new DateTimeZone('Asia/Jakarta'));
  } catch (Throwable $e) {
    return (string)$ts; // fallback jika format tak terduga
  }
  if (class_exists('IntlDateFormatter')) {
    $fmt = new IntlDateFormatter('id_ID', IntlDateFormatter::NONE, IntlDateFormatter::NONE,
                                 'Asia/Jakarta', IntlDateFormatter::GREGORIAN, 'd MMMM y');
    return $fmt->format($dt);
  }
  // fallback manual jika ekstensi intl tidak tersedia
  static $bulan = [1=>'Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
  return (int)$dt->format('j').' '.$bulan[(int)$dt->format('n')].' '.$dt->format('Y');
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

/* bar filter: semua item dirapatkan ke bawah agar sejajar */
.filters{display:grid; grid-template-columns:repeat(7,1fr); gap:6px; align-items:end}

label{font-size:11.5px; color:var(--muted)}
input,select{width:100%; padding:7px 9px; border-radius:8px; border:1px solid var(--line); background:#0b1328; color:var(--fg); font-size:13px}
.btn{display:inline-flex; padding:7px 12px; border-radius:10px; border:1px solid var(--line); background:#0b1328; color:var(--fg); cursor:pointer; font-size:13px}
.btn.primary{background:linear-gradient(135deg,#2563eb,#10b981); border:none}

/* TABLE (dense & stabil) */
.table-wrap{overflow:auto; border-radius:10px; margin-top:8px}
.table{ display:inline-table; width:auto; min-width:1100px; border-collapse:separate; border-spacing:0; table-layout:fixed; }
.table th,.table td{
  padding:6px 8px; border-bottom:1px solid var(--line);
  vertical-align:middle; font-size:13px; line-height:1.2;
  white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
}
.table thead th{ font-weight:600; position:sticky; top:0; z-index:1; background:var(--card); }
.table thead th > a{ display:block; width:100%; text-align:inherit; }
.table thead th .arr{opacity:.6; font-size:10px; margin-left:6px}
.table tbody tr:nth-child(even){background:rgba(255,255,255,.015)}
.table tbody tr:hover{background:rgba(59,130,246,.06)}
.center{text-align:center}
.left{text-align:left}
.right{ text-align:right; }
.mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:11.5px}
.badge{padding:2px 6px; border-radius:999px; font-size:11.5px; color:#fff; display:inline-block}
.badge.service{background:#424242}
/* badge hijau untuk kolom Site */
.badge.site{ background:#10b981; } /* emerald */

.ver{text-align:center}
.ver .chip{display:inline-block; max-width:180px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; vertical-align:middle}
.ver .chip code{background:#0b1328; border:1px solid var(--line); padding:1px 5px; border-radius:5px; display:inline-block}

.pager{display:flex; gap:6px; align-items:center; justify-content:flex-end; margin-top:10px}
.num{padding:5px 9px; border-radius:7px; border:1px solid var(--line); background:#0b1328}

/* subheader waktu & footer */
/* subheader waktu — dibuat lebih lega & manis */
.subhead{
  margin: 8px 0 14px;        /* tambah jarak atas & bawah */
  padding: 6px 10px;         /* beri ruang di dalam */
  display: inline-flex;
  align-items: center;
  gap: 10px;                  /* jarak titik hijau ↔ teks */
  color: var(--muted);
  font-size: 12.8px;
  border: 1px solid var(--line);
  border-radius: 10px;
  background: rgba(255,255,255,.02);
}
.subhead .dot{
  width: 8px; height: 8px; border-radius: 999px;
  background: #10b981;
  box-shadow: 0 0 10px rgba(16,185,129,.9);
}
#now{ font-variant-numeric: tabular-nums; letter-spacing: .2px; }

/* footer cantik */
.foot{
  position: relative;
  margin: 18px 0 10px;
  text-align: center;
  color: var(--muted);
  font-size: 12.8px;
  padding-top: 14px;                 /* ruang untuk garis gradasi */
}
.foot::before{
  content: "";
  position: absolute; left: 12%; right: 12%; top: 0;
  height: 1px;
  background: linear-gradient(90deg, transparent, #3b82f6 25%, #10b981 75%, transparent);
  opacity: .7;
}
.foot-chip{
  display: inline-block;
  padding: 6px 12px;
  border: 1px solid var(--line);
  border-radius: 999px;
  background: rgba(255,255,255,.02);
  backdrop-filter: blur(2px);
  letter-spacing: .2px;
}
.foot-chip::before{
  content: "⚙️";
  margin-right: 8px;
  opacity: .9;
}

/* === Search beautify (ikon + pill + fokus glow) === */
.filters > div:first-child{ position: relative; }
.filters > div:first-child input[name="q"]{
  padding-left: 34px;                 /* ruang buat ikon */
  border-radius: 999px;               /* pill */
  transition: box-shadow .12s, border-color .12s;
  background-image: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="%239ca3af" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>');
  background-repeat: no-repeat;
  background-position: 10px 50%;
  background-size: 16px;
}
.filters > div:first-child input[name="q"]:focus{
  outline: none;
  box-shadow: 0 0 0 2px rgba(59,130,246,.45), 0 0 0 5px rgba(16,185,129,.12);
}
.filters > div:first-child input[name="q"]::placeholder{ color:#9ca3af; opacity:.9; }

/* Tombol REFRESH (tombol pertama di sel kanan) — hijau solid */
.filters > div:last-child .btn:first-child{
  background:linear-gradient(135deg,#22c55e,#10b981);
  border-color:#059669;
  color:#031a12;
  box-shadow:
    0 0 0 1px rgba(16,185,129,.25) inset,
    0 6px 16px rgba(16,185,129,.22);
}
.filters > div:last-child .btn:first-child:hover{
  box-shadow:
    0 0 0 2px rgba(16,185,129,.35) inset,
    0 8px 22px rgba(16,185,129,.28);
}
.filters > div:last-child .btn:first-child:active{
  filter:brightness(.98);
}



/* Tombol EKSPOR CSV (tombol terakhir) — biru outline */
.filters > div:last-child .btn:last-child{
  background:transparent;
  border-color:#2563eb;
  color:#93c5fd;
  box-shadow:0 0 0 1px rgba(37,99,235,.25) inset;
}
.filters > div:last-child .btn:last-child:hover{
  background:rgba(59,130,246,.12);
  box-shadow:0 0 0 2px rgba(59,130,246,.35) inset;
}
.filters > div:last-child .btn:last-child:active{
  filter:brightness(.97);
}

/*---------------------------------------------*/
/* ==== Tipografi area filter lebih kecil ==== */
.filters label{
  font-size: 10.5px;            /* sebelumnya 11.5px */
  letter-spacing: .2px;
}

.filters input,
.filters select{
  font-size: 12px;              /* sebelumnya 13px */
}

.filters input::placeholder{
  font-size: 12px;
}

/* (opsional) samakan ukuran font tombol di bar filter */
.filters .btn{
  font-size: 12px;
}

/* NEW: override jumlah kolom grid agar muat filter Service tanpa mengubah rule lama */
.filters{ grid-template-columns: repeat(8, 1fr); }

</style>


</head>
<body>
<div class="wrap">
  <h2 style="margin:0 0 12px; display:flex; gap:10px; align-items:center">
    DevOps Deploy Monitoring
    <span class="mono" style="color:var(--muted)">— <?=h(number_format($total))?> entri</span>
  </h2>
<div class="subhead">
  <span class="dot"></span>
  <span id="now">Memuat waktu…</span>
</div>

  <div class="card">
    <form method="get" class="toolbar">
      <div class="filters">
        <input type="hidden" name="page" value="1">
<input type="hidden" name="sort" value="<?=h($sort)\?>">
<input type="hidden" name="dir"  value="<?=h(strtolower($dir)==='asc'?'asc':'desc')\?>">
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
            <?php foreach(['BACKEND-JAVA','BACK-OFFICE-JAVA','WEB-EMR','PDF-GENERATOR'] as $p): ?>
              <option value="<?=$p?>" <?=$project===$p?'selected':''?>><?=$p?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <!-- NEW: Filter Service (diletakkan persis setelah Project) -->
        <div><label>Service</label>
          <select name="service">
            <option value="">(All)</option>
            <?php foreach($services as $svc): ?>
              <option value="<?=h($svc)?>" <?=$service===$svc?'selected':''?>><?=h($svc)?></option>
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
<div style="display:flex; gap:8px; align-items:center">
  <button type="button" class="btn" id="refreshBtn" title="Refresh (R)">Refresh</button>
  <a class="btn" href="<?=h(url_with(['export'=>'csv']))?>">Ekspor CSV</a>
</div>

      </div>
    </form>

    <div class="table-wrap">
      <table class="table">
        <!-- lebar pasti per kolom agar tidak “loncat” -->
<colgroup>
  <col style="width:88px">   <!-- Server -->
  <col style="width:64px">   <!-- Site -->
  <col style="width:150px">  <!-- Project -->
  <col style="width:138px">  <!-- Service -->
  <col style="width:150px">  <!-- Latest -->
  <col style="width:150px">  <!-- New -->
  <col style="width:112px">  <!-- Creator (git_author) -->
  <col style="width:90px">   <!-- Git Hash (git_short) -->
  <col style="width:220px">  <!-- Changelog (git_title) -->
  <col style="width:150px">  <!-- Created -->
</colgroup>


        <thead>
          <tr>
            <?php
              function th($label,$key,$sort,$dir,$class=''){
                $is   = ($sort===$key);
                $next = ($is && $dir==='ASC') ? 'desc' : 'asc';
                $arrow= $is ? ($dir==='ASC'?'▲':'▼') : '';
                $aria = $is ? ($dir==='ASC'?'ascending':'descending') : 'none';
                $cls  = $class ? ' class="'.$class.'"' : '';
                echo '<th'.$cls.' aria-sort="'.$aria.'"><a href="'.h(url_with(['sort'=>$key,'dir'=>$next,'page'=>1])).'">'
                    .h($label).($arrow?'<span class="arr">'.$arrow.'</span>':'').'</a></th>';
              }
              th('Server','server',$sort,$dir,'center');
              th('Site','site',$sort,$dir,'center');
              th('Project','project',$sort,$dir,'left');
              th('Service','service',$sort,$dir,'center');
              th('Latest Version','latest_version',$sort,$dir,'center');
              th('New Version','new_version',$sort,$dir,'center');

              /* header baru */
              th('Creator','git_author',$sort,$dir,'center');
              th('Git Hash','git_short',$sort,$dir,'center');
              th('Changelog','git_title',$sort,$dir,'left');

              th('Created','created_at',$sort,$dir,'left');
            ?>
          </tr>
        </thead>

        <tbody>
          <?php if(!$rows): ?>
            <tr><td colspan="10" class="mono" style="color:#9ca3af; text-align:center">Tidak ada data.</td></tr>
          <?php else: foreach($rows as $r): ?>
            <tr>
              <td class="mono center"><?=h($r['server'])?></td>
              <td class="center"><?=badge_site($r['site'])?></td>
              <td class="mono left" title="<?=h($r['project'])?>"><?=h($r['project'])?></td>
              <td class="center"><span class="badge service" title="<?=h($r['service'])?>"><?=h($r['service'])?></span></td>
              <?php $lv=trim((string)$r['latest_version']); $nv=trim((string)$r['new_version']); ?>
              <td class="ver">
                <?= $lv==='' ? '<span style="color:#9ca3af">—</span>'
                              : '<span class="chip" title="'.h($lv).'"><code class="mono">'.h($lv).'</code></span>' ?>
              </td>
              <td class="ver">
                <?= $nv==='' ? '<span style="color:#9ca3af">—</span>'
                              : '<span class="chip" title="'.h($nv).'"><code class="mono">'.h($nv).'</code></span>' ?>
              </td>

              <!-- kolom baru -->
              <td class="center mono" title="<?=h($r['git_author'])?>"><?=h($r['git_author'])?></td>
              <td class="center"><code class="mono" title="<?=h($r['git_short'])?>"><?=h($r['git_short'])?></code></td>
              <td class="left" title="<?=h($r['git_title'])?>"><?=h($r['git_title'])?></td>

              <td class="mono left"><?=h(id_tanggal($r['created_at']))?></td>

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

<footer class="foot"><span class="foot-chip">Operated by DevOps BVK 2025</span></footer>

<script>
(function(){
  const el = document.getElementById('now');
  if(!el) return;
  function tick(){
    const now = new Date();
    // tampilkan waktu WIB secara konsisten
    el.textContent = new Intl.DateTimeFormat('id-ID',{
      weekday:'long', year:'numeric', month:'long', day:'numeric',
      hour:'2-digit', minute:'2-digit', second:'2-digit',
      hour12:false, timeZone:'Asia/Jakarta'
    }).format(now) + ' WIB';
  }
  tick();
  setInterval(tick, 1000);
})();
</script>

<script>
(function(){
  const form = document.querySelector('form.toolbar');
  if(!form) return;

  // pastikan hidden 'page' ada
  let pageInput = form.querySelector('input[name="page"]');
  if(!pageInput){ pageInput = document.createElement('input'); pageInput.type='hidden'; pageInput.name='page'; pageInput.value='1'; form.appendChild(pageInput); }

function submitNow(){
  pageInput.value = '1';    // reset ke halaman 1
  form.submit();            // langsung submit, tanpa requestSubmit
}
  

  // debounce cepat
  const debounce = (fn, ms) => { let t; return (...a)=>{ clearTimeout(t); t=setTimeout(()=>fn.apply(null,a), ms); }; };
  const submitFast = debounce(submitNow, 80);   // saat kosongkan / hapus cepat
  const submitQuick= debounce(submitNow, 120);  // ngetik normal

  // SEARCH: responsif saat ngetik panjang
  const q = form.querySelector('[name="q"]');
  if(q){
    q.addEventListener('input', () => {
      const len = q.value.trim().length;
      (len <= 2 ? submitFast : submitQuick)();   // <3 karakter -> lebih cepat
    });
    q.addEventListener('keydown', e => { if(e.key === 'Enter'){ e.preventDefault(); submitNow(); }});
  }

  // SELECT/DATE/PER PAGE: langsung submit saat berubah (kode lama tetap)
  ['server','project','from','to','pp'].forEach(name=>{
    const el = form.querySelector(`[name="${name}"]`);
    if(el) el.addEventListener('change', submitNow);
  });

  // NEW: listener terpisah untuk 'service' (tidak mengubah array di atas)
  const svc = form.querySelector('[name="service"]');
  if(svc) svc.addEventListener('change', submitNow);
})();
</script>

<script>
/* ==== Auto refresh setiap 5 detik ====
   - Men-submit form dengan nilai filter/sort saat ini
   - TIDAK mereset halaman (page tetap)
   - Pause saat user fokus di input/select atau tab tidak aktif
*/
(function () {
  const form = document.querySelector('form.toolbar');
  if (!form) return;

  const REFRESH_MS = 300000;
  let timer;

  function refreshNow() {
    // jangan refresh kalau tab disembunyikan
    if (document.hidden) return;

    // jangan refresh ketika user sedang interaksi di area filter
    const ae = document.activeElement;
    if (ae && (ae.tagName === 'INPUT' || ae.tagName === 'SELECT')) return;

    // submit GET dengan nilai form saat ini (tidak utak-atik 'page')
    form.submit();
  }

  function start() { stop(); timer = setInterval(refreshNow, REFRESH_MS); }
  function stop()  { if (timer) clearInterval(timer); }

  // pause saat mouse/focus berada di filter; lanjut lagi saat keluar
  const filters = document.querySelector('.filters');
  if (filters) {
    filters.addEventListener('mouseenter', stop);
    filters.addEventListener('mouseleave', start);
    filters.addEventListener('focusin',  stop);
    filters.addEventListener('focusout', start);
  }

  // pause kalau tab tidak aktif
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) stop(); else start();
  });

  start();
})();
</script>

<script>
(function(){
  const btn = document.getElementById('refreshBtn');
  if (!btn) return;

  // Clear semua filter: arahkan ke URL dasar tanpa query/hash.
  function clearFilters() {
    // contoh: dari /request.php?q=..&server=.. -> ke /request.php
    const basePath = window.location.pathname; 
    // replace agar tidak menumpuk histori ketika clear
    window.location.replace(basePath);
  }

  // Klik tombol Refresh => clear semua filter
  btn.addEventListener('click', clearFilters);

  // Shortcut: tekan "R" saat tidak fokus di input/select/textarea => clear juga
  document.addEventListener('keydown', (e) => {
    const tag = (e.target && e.target.tagName) || '';
    if ((e.key === 'r' || e.key === 'R') && !/INPUT|SELECT|TEXTAREA/.test(tag)) {
      e.preventDefault();
      clearFilters();
    }
  });
})();
</script>


</body>
</html>
