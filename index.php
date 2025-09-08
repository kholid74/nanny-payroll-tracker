<?php
// Nanny Payroll & Loans — single-file PHP app (MySQL + .env)
// Drop this as index.php under a subdomain. Requires PHP 7.4+ with PDO_MySQL.
// Features: weekly payroll, meal allowance per day, kasbon (same-week advance), loans with installments, CSV export.

session_start();

// ======= ENV LOADER =======
function loadEnv($path) {
    if (!file_exists($path)) return;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) continue;
        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) continue;
        list($name, $value) = $parts;
        $name = trim($name);
        $value = trim($value);
        if (!array_key_exists($name, $_ENV)) {
            $_ENV[$name] = $value;
            putenv("$name=$value");
        }
    }
}
loadEnv(__DIR__ . '/.env');

// ======= CONFIG =======
$APP_TITLE    = $_ENV['APP_TITLE']    ?? 'Nanny Payroll & Loans';
$APP_PASSWORD = $_ENV['APP_PASSWORD'] ?? 'changeme'; // change in .env

// MySQL Database config (from .env with safe defaults)
$DB_HOST = $_ENV['DB_HOST'] ?? 'localhost';
$DB_NAME = $_ENV['DB_NAME'] ?? 'nanny';
$DB_USER = $_ENV['DB_USER'] ?? 'root';
$DB_PASS = $_ENV['DB_PASS'] ?? '';
$DB_DSN  = "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4";

// ======= DB INIT =======
try {
    $pdo = new PDO($DB_DSN, $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo 'DB connection failed: ' . htmlspecialchars($e->getMessage());
    exit;
}

// Create tables if not exist
$pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS config (
  id INT PRIMARY KEY,
  salary_weekly INT NOT NULL DEFAULT 325000,
  meal_per_day INT NOT NULL DEFAULT 10000,
  workdays_standard INT NOT NULL DEFAULT 5
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL);

$pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS entries (
  id INT AUTO_INCREMENT PRIMARY KEY,
  payday DATE NOT NULL,
  workdays INT NOT NULL DEFAULT 5,
  kasbon INT NOT NULL DEFAULT 0,
  installment INT NOT NULL DEFAULT 0,
  new_loan INT NOT NULL DEFAULT 0,
  note TEXT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL);

// Seed config
$existsRow = $pdo->query('SELECT COUNT(*) AS c FROM config')->fetch();
$exists = (int)($existsRow['c'] ?? 0);
if ($exists === 0) {
    $stmt = $pdo->prepare('INSERT INTO config(id,salary_weekly,meal_per_day,workdays_standard) VALUES (1,?,?,?)');
    $stmt->execute([
        (int)($_ENV['SALARY_WEEKLY'] ?? 325000),
        (int)($_ENV['MEAL_PER_DAY'] ?? 10000),
        (int)($_ENV['WORKDAYS_STANDARD'] ?? 5)
    ]);
}

// ======= AUTH (simple password) ======= (simple password) =======
if (isset($_GET['logout'])) { unset($_SESSION['authed']); header('Location: ./'); exit; }
if (!isset($_SESSION['authed'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
        if (hash_equals($APP_PASSWORD, $_POST['password'])) { $_SESSION['authed'] = true; header('Location: ./'); exit; }
        $login_error = 'Password salah';
    }
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>Login</title><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">';
    echo '</head><body class="bg-light"><div class="container py-5"><div class="row justify-content-center"><div class="col-md-4">';
    echo '<div class="card shadow-sm"><div class="card-body">';
    echo '<h5 class="mb-3">' . htmlspecialchars($APP_TITLE) . ' — Login</h5>';
    if (!empty($login_error)) echo '<div class="alert alert-danger py-2">' . htmlspecialchars($login_error) . '</div>';
    echo '<form method="post"><div class="mb-3"><label class="form-label">Password</label><input type="password" class="form-control" name="password" required></div><button class="btn btn-primary w-100">Masuk</button></form>';
    echo '</div></div><p class="text-muted small mt-3">Tip: set <code>APP_PASSWORD</code> pada file <code>.env</code>.</p></div></div></div></body></html>';
    exit;
}

// ======= HELPERS =======
function rupiah($v){ return 'Rp' . number_format((int)$v,0,',','.'); }
function post($k,$d=null){ return $_POST[$k] ?? $d; }
function indoDate($date){
    if(!$date) return '';
    $bulan = [1=>'Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
    $ts = strtotime($date);
    if ($ts === false) return htmlspecialchars($date);
    $tgl = date('j',$ts);
    $bln = $bulan[(int)date('n',$ts)];
    $thn = date('Y',$ts);
    return "$tgl $bln $thn";
}

// Load config
$config = $pdo->query('SELECT * FROM config WHERE id=1')->fetch();
$salaryWeekly     = (int)($config['salary_weekly'] ?? 325000);
$mealPerDay       = (int)($config['meal_per_day'] ?? 10000);
$workdaysStandard = (int)($config['workdays_standard'] ?? 5);
if ($workdaysStandard < 1) $workdaysStandard = 5;

// ======= ACTIONS =======
$msg = null; $err = null;

if (($_POST['action'] ?? '') === 'save_entry') {
    try {
        $payday      = post('payday');
        $workdays    = (int)post('workdays',5);
        $kasbon      = (int)preg_replace('/\D/','', post('kasbon',0));
        $installment = (int)preg_replace('/\D/','', post('installment',0));
        $newLoan     = (int)preg_replace('/\D/','', post('new_loan',0));
        $note        = trim(post('note',''));
        if (!$payday) throw new Exception('Tanggal gajian wajib diisi');
        $stmt = $pdo->prepare('INSERT INTO entries(payday,workdays,kasbon,installment,new_loan,note) VALUES (?,?,?,?,?,?)');
        $stmt->execute([$payday,$workdays,$kasbon,$installment,$newLoan,$note]);
        $msg = 'Minggu berhasil disimpan';
    } catch (Throwable $e) { $err = $e->getMessage(); }
}

if (($_POST['action'] ?? '') === 'delete' && isset($_POST['id'])) {
    $id = (int)$_POST['id'];
    $pdo->prepare('DELETE FROM entries WHERE id=?')->execute([$id]);
    $msg = 'Baris dihapus';
}

if (($_POST['action'] ?? '') === 'save_config') {
    try {
        $sw = (int)preg_replace('/\D/','', post('salary_weekly',325000));
        $mpd = (int)preg_replace('/\D/','', post('meal_per_day',10000));
        $ws = (int)post('workdays_standard',5);
        if ($ws < 1 || $ws > 7) throw new Exception('Hari kerja standar harus 1..7');
        $pdo->prepare('UPDATE config SET salary_weekly=?, meal_per_day=?, workdays_standard=? WHERE id=1')
            ->execute([$sw,$mpd,$ws]);
        header('Location: ./?saved=1'); exit;
    } catch (Throwable $e) { $err = $e->getMessage(); }
}

// Export CSV
if (isset($_GET['export']) && $_GET['export']==='csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ledger.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Minggu ke','Tanggal Gajian','Hari Kerja','Gaji Pokok','Uang Makan','Kasbon Minggu Ini','Cicilan','Pinjaman Baru','Sisa Pinjaman','Total Dibayar','Catatan']);
    $rows = $pdo->query('SELECT * FROM entries ORDER BY payday ASC, id ASC')->fetchAll();
    $loan = 0; $week=1;
    foreach ($rows as $row) {
        $gajiPerHari = $salaryWeekly / $workdaysStandard;
        $gaji  = $gajiPerHari * (int)$row['workdays'];
        $makan = $mealPerDay   * (int)$row['workdays'];
        $loan  = $loan + (int)$row['new_loan'] - (int)$row['installment'];
        $total = $gaji + $makan - (int)$row['kasbon'] - (int)$row['installment'];
        fputcsv($out,[
            $week++, indoDate($row['payday']),$row['workdays'],$gaji,$makan,$row['kasbon'],$row['installment'],$row['new_loan'],$loan,$total,$row['note']
        ]);
    }
    fclose($out); exit;
}

// Load entries
$entries = $pdo->query('SELECT * FROM entries ORDER BY payday ASC, id ASC')->fetchAll();

// Compute running balances for render
$running = []; $loanBal = 0; $i=0;
foreach ($entries as $row) {
    $gajiPerHari = $salaryWeekly / $workdaysStandard;
    $gaji  = $gajiPerHari * (int)$row['workdays'];
    $makan = $mealPerDay   * (int)$row['workdays'];
    $loanBal = $loanBal + (int)$row['new_loan'] - (int)$row['installment'];
    $total = $gaji + $makan - (int)$row['kasbon'] - (int)$row['installment'];
    $running[] = [
        'week' => ++$i,
        'row' => $row,
        'gaji' => $gaji,
        'makan' => $makan,
        'loan' => $loanBal,
        'total' => $total,
    ];
}

// Sisa pinjaman terbaru
$currentLoan = $loanBal;

// ======= RENDER =======
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($APP_TITLE) ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<style>
  .card-rounded { border-radius: 1rem; }
  .table thead th { position: sticky; top: 0; background: #f8f9fa; z-index: 1; }
</style>
</head>
<body class="bg-light">
<nav class="navbar navbar-expand-lg bg-white border-bottom">
  <div class="container">
    <a class="navbar-brand" href="#"><?= htmlspecialchars($APP_TITLE) ?></a>
    <div class="ms-auto d-flex gap-2">
      <a class="btn btn-sm btn-outline-secondary" href="?export=csv">Export CSV</a>
      <a class="btn btn-sm btn-outline-danger" href="?logout=1">Logout</a>
    </div>
  </div>
</nav>

<div class="container py-4">
  <?php if($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
  <?php if($err): ?><div class="alert alert-danger"><?= htmlspecialchars($err) ?></div><?php endif; ?>

  <div class="row g-4">
    <div class="col-lg-8">
      <div class="card card-rounded shadow-sm">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="mb-0">Ledger Mingguan</h5>
            <span class="badge bg-warning text-dark">Sisa Pinjaman: <strong><?= rupiah($currentLoan) ?></strong></span>
          </div>
          <div class="table-responsive" style="max-height: 60vh;">
            <table class="table table-sm align-middle">
              <thead>
                <tr>
                  <th>#</th>
                  <th>Tgl Gajian</th>
                  <th>HK</th>
                  <th>Gaji</th>
                  <th>Makan</th>
                  <th>Kasbon</th>
                  <th>Cicilan</th>
                  <th>Pinj. Baru</th>
                  <th>Sisa Pinj.</th>
                  <th>Total Dibayar</th>
                  <th>Catatan</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach($running as $r): $row = $r['row']; ?>
                <tr>
                  <td><?= (int)$r['week'] ?></td>
                  <td><?= indoDate($row['payday']) ?></td>
                  <td><?= (int)$row['workdays'] ?></td>
                  <td><?= rupiah($r['gaji']) ?></td>
                  <td><?= rupiah($r['makan']) ?></td>
                  <td><?= rupiah($row['kasbon']) ?></td>
                  <td><?= rupiah($row['installment']) ?></td>
                  <td><?= rupiah($row['new_loan']) ?></td>
                  <td><?= rupiah($r['loan']) ?></td>
                  <td><strong><?= rupiah($r['total']) ?></strong></td>
                  <td><?= htmlspecialchars($row['note'] ?? '') ?></td>
                  <td>
                    <form method="post" onsubmit="return confirm('Hapus baris ini?');">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                      <button class="btn btn-sm btn-outline-danger">Hapus</button>
                    </form>
                  </td>
                </tr>
                <?php endforeach; if (empty($running)): ?>
                <tr><td colspan="12" class="text-muted">Belum ada data. Tambahkan di formulir di kanan.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <div class="col-lg-4">
      <div class="card card-rounded shadow-sm mb-4">
        <div class="card-body">
          <h5 class="mb-3">Tambah / Catat Mingguan</h5>
          <form method="post" class="vstack gap-2">
            <input type="hidden" name="action" value="save_entry">
            <div>
              <label class="form-label">Tanggal Gajian (Jumat)</label>
              <input type="date" name="payday" class="form-control" required>
            </div>
            <div>
              <label class="form-label">Hari Kerja (0-5)</label>
              <input type="number" min="0" max="5" name="workdays" class="form-control" value="5" required>
            </div>
            <div class="row g-2">
              <div class="col-6">
                <label class="form-label">Kasbon Minggu Ini</label>
                <input type="text" name="kasbon" class="form-control" placeholder="100000">
              </div>
              <div class="col-6">
                <label class="form-label">Cicilan Pinjaman</label>
                <input type="text" name="installment" class="form-control" placeholder="100000">
              </div>
            </div>
            <div>
              <label class="form-label">Pinjaman Baru</label>
              <input type="text" name="new_loan" class="form-control" placeholder="200000">
            </div>
            <div>
              <label class="form-label">Catatan</label>
              <input type="text" name="note" class="form-control" placeholder="opsional, misal: kasbon selasa 100k">
            </div>
            <button class="btn btn-primary mt-2 w-100">Simpan</button>
            <div class="small text-muted mt-2">
              Gaji mingguan standar: <strong><?= rupiah($salaryWeekly) ?></strong> untuk <?= $workdaysStandard ?> hari · Uang makan/HR: <strong><?= rupiah($mealPerDay) ?></strong>
            </div>
          </form>
        </div>
      </div>

      <div class="card card-rounded shadow-sm">
        <div class="card-body">
          <h5 class="mb-3">Pengaturan</h5>
          <form method="post" class="vstack gap-2">
            <input type="hidden" name="action" value="save_config">
            <div>
              <label class="form-label">Gaji Mingguan Standar (Rp)</label>
              <input type="text" name="salary_weekly" class="form-control" value="<?= (int)$salaryWeekly ?>">
            </div>
            <div>
              <label class="form-label">Uang Makan per Hari (Rp)</label>
              <input type="text" name="meal_per_day" class="form-control" value="<?= (int)$mealPerDay ?>">
            </div>
            <div>
              <label class="form-label">Hari Kerja Standar per Minggu</label>
              <input type="number" min="1" max="7" name="workdays_standard" class="form-control" value="<?= (int)$workdaysStandard ?>">
            </div>
            <button class="btn btn-outline-primary mt-2 w-100">Simpan Pengaturan</button>
          </form>
          <?php if(isset($_GET['saved'])): ?><div class="alert alert-success mt-3 py-2">Pengaturan tersimpan</div><?php endif; ?>
        </div>
      </div>

    </div>
  </div>
</div>

</body>
</html>
