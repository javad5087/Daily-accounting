<?php
require __DIR__ . '/config.php';

$usersCount = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$page = (string)($_GET['page'] ?? ($usersCount === 0 ? 'setup' : (is_logged_in() ? 'dashboard' : 'login')));
$allowed = ['setup','login','logout','dashboard','transactions','categories','accounts','transfer','reports','backup'];
if (!in_array($page, $allowed, true)) $page = 'dashboard';

if ($page === 'logout') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time()-42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    header('Location: index.php?page=login');
    exit;
}

if ($page === 'setup' && $usersCount > 0) redirect_page('login');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'setup_admin') {
            if ((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) throw new RuntimeException('مدیر قبلاً ساخته شده است.');
            $username = trim((string)($_POST['username'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            if (mb_strlen($username) < 3) throw new RuntimeException('نام کاربری باید حداقل ۳ نویسه باشد.');
            if (strlen($password) < 10) throw new RuntimeException('رمز عبور باید حداقل ۱۰ نویسه باشد.');
            $st = $pdo->prepare('INSERT INTO users(username,password_hash) VALUES(?,?)');
            $st->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$pdo->lastInsertId();
            $_SESSION['username'] = $username;
            redirect_page('dashboard', 'حساب مدیر ساخته شد.');
        }
        if ($action === 'login') {
            $st = $pdo->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
            $st->execute([trim((string)($_POST['username'] ?? ''))]);
            $user = $st->fetch();
            if (!$user || !password_verify((string)($_POST['password'] ?? ''), $user['password_hash'])) {
                throw new RuntimeException('نام کاربری یا رمز عبور نادرست است.');
            }
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$user['id'];
            $_SESSION['username'] = $user['username'];
            redirect_page('dashboard');
        }
        require_login();
        if ($action === 'add_account') {
            $name = trim((string)($_POST['name'] ?? ''));
            $bank = trim((string)($_POST['bank_name'] ?? ''));
            $type = (string)($_POST['account_type'] ?? 'bank');
            $opening = (int)en_digits((string)($_POST['opening_balance'] ?? '0'));
            if ($name === '') throw new RuntimeException('نام حساب را وارد کنید.');
            if (!in_array($type, ['bank','cash','other'], true)) $type = 'bank';
            $st = $pdo->prepare('INSERT INTO accounts(name,bank_name,account_type,opening_balance) VALUES(?,?,?,?)');
            $st->execute([$name,$bank,$type,$opening]);
            redirect_page('accounts','کارت بانکی اضافه شد.');
        }
        if ($action === 'update_account') {
            $id=(int)($_POST['id']??0);
            $name=trim((string)($_POST['name']??''));
            $bank=trim((string)($_POST['bank_name']??''));
            $type=(string)($_POST['account_type']??'bank');
            $opening=(int)en_digits((string)($_POST['opening_balance']??'0'));
            if($id<1 || $name==='') throw new RuntimeException('اطلاعات کارت بانکی معتبر نیست.');
            if(!in_array($type,['bank','cash','other'],true)) $type='bank';
            $st=$pdo->prepare('UPDATE accounts SET name=?,bank_name=?,account_type=?,opening_balance=? WHERE id=?');
            $st->execute([$name,$bank,$type,$opening,$id]);
            redirect_page('accounts','کارت بانکی ویرایش شد.');
        }
        if ($action === 'delete_account') {
            $id=(int)($_POST['id']??0);
            $st=$pdo->prepare('SELECT COUNT(*) FROM transactions WHERE account_id=?');$st->execute([$id]);$used=(int)$st->fetchColumn();
            $st=$pdo->prepare('SELECT COUNT(*) FROM transfers WHERE from_account_id=? OR to_account_id=?');$st->execute([$id,$id]);$moved=(int)$st->fetchColumn();
            if($used || $moved) throw new RuntimeException('این کارت تراکنش یا انتقال دارد؛ برای حفظ سوابق، ابتدا سوابق وابسته را بررسی کنید.');
            $st=$pdo->prepare('DELETE FROM accounts WHERE id=?');$st->execute([$id]);
            redirect_page('accounts','کارت بانکی حذف شد.');
        }
        if ($action === 'add_category') {
            $name = trim((string)($_POST['name'] ?? '')); $kind = (string)($_POST['kind'] ?? 'both');
            if ($name === '' || !in_array($kind, ['income','expense','both'], true)) throw new RuntimeException('نام یا نوع دسته‌بندی معتبر نیست.');
            $st=$pdo->prepare('INSERT INTO categories(name,kind) VALUES(?,?)'); $st->execute([$name,$kind]);
            redirect_page('categories','دسته‌بندی اضافه شد.');
        }
        if ($action === 'delete_category') {
            $id=(int)($_POST['id']??0); $st=$pdo->prepare('SELECT name FROM categories WHERE id=?'); $st->execute([$id]); $cat=$st->fetchColumn();
            if (!$cat) throw new RuntimeException('دسته‌بندی پیدا نشد.');
            $st=$pdo->prepare("UPDATE transactions SET category='عمومی' WHERE category=?"); $st->execute([$cat]);
            $st=$pdo->prepare('DELETE FROM categories WHERE id=?'); $st->execute([$id]); redirect_page('categories','دسته‌بندی حذف شد.');
        }
        if ($action === 'save_transaction') {
            $id = (int)($_POST['id'] ?? 0);
            $kind = (string)($_POST['kind'] ?? 'expense');
            $amount = (int)en_digits((string)($_POST['amount'] ?? '0'));
            $account = (int)($_POST['account_id'] ?? 0);
            $category = trim((string)($_POST['category'] ?? ''));
            $desc = trim((string)($_POST['description'] ?? ''));
            $date = en_digits(trim((string)($_POST['transaction_date'] ?? date('Y-m-d'))));
            $ref = trim((string)($_POST['reference_no'] ?? ''));
            if (!in_array($kind,['income','expense'],true) || $amount <= 0 || $account <= 0 || !valid_date($date)) throw new RuntimeException('مبلغ، حساب یا تاریخ معتبر نیست.');
            $st = $pdo->prepare('SELECT COUNT(*) FROM accounts WHERE id=?'); $st->execute([$account]);
            if (!(int)$st->fetchColumn()) throw new RuntimeException('حساب انتخاب‌شده وجود ندارد.');
            if ($id > 0) {
                $st = $pdo->prepare('UPDATE transactions SET kind=?,amount=?,account_id=?,category=?,description=?,transaction_date=?,reference_no=? WHERE id=?');
                $st->execute([$kind,$amount,$account,$category,$desc,$date,$ref,$id]);
            } else {
                $st = $pdo->prepare('INSERT INTO transactions(kind,amount,account_id,category,description,transaction_date,reference_no) VALUES(?,?,?,?,?,?,?)');
                $st->execute([$kind,$amount,$account,$category,$desc,$date,$ref]);
            }
            redirect_page('transactions','تراکنش ذخیره شد.');
        }
        if ($action === 'delete_transaction') {
            $st=$pdo->prepare('DELETE FROM transactions WHERE id=?'); $st->execute([(int)($_POST['id'] ?? 0)]);
            redirect_page('transactions','تراکنش حذف شد.');
        }
        if ($action === 'transfer') {
            $from=(int)($_POST['from_account_id']??0); $to=(int)($_POST['to_account_id']??0);
            $amount=(int)en_digits((string)($_POST['amount']??'0'));
            $date=en_digits(trim((string)($_POST['transfer_date']??date('Y-m-d'))));
            $desc=trim((string)($_POST['description']??''));
            $destType=(string)($_POST['destination_ref_type']??'card'); $destRef=trim((string)($_POST['destination_ref']??'')); $destOwner=trim((string)($_POST['destination_owner']??''));
            if (!in_array($destType,['card','iban'],true)) $destType='card';
            if ($from<1 || $to<1 || $from===$to || $amount<=0 || !valid_date($date)) throw new RuntimeException('اطلاعات انتقال معتبر نیست.');
            $st=$pdo->prepare('INSERT INTO transfers(from_account_id,to_account_id,amount,transfer_date,description,destination_ref_type,destination_ref,destination_owner) VALUES(?,?,?,?,?,?,?,?)');
            $st->execute([$from,$to,$amount,$date,$desc,$destType,$destRef,$destOwner]);
            redirect_page('transfer','انتقال وجه ثبت شد.');
        }
    } catch (Throwable $ex) {
        $error = $ex instanceof RuntimeException ? $ex->getMessage() : 'عملیات انجام نشد. اطلاعات را بررسی کنید.';
    }
}

if (in_array($page,['dashboard','transactions','categories','accounts','transfer','reports','backup'],true)) require_login();

function account_balances(PDO $pdo): array {
    $rows = $pdo->query("SELECT a.*,
        COALESCE((SELECT SUM(t.amount) FROM transactions t WHERE t.account_id=a.id AND t.kind='income'),0) as incomes,
        COALESCE((SELECT SUM(t.amount) FROM transactions t WHERE t.account_id=a.id AND t.kind='expense'),0) as expenses,
        COALESCE((SELECT SUM(x.amount) FROM transfers x WHERE x.from_account_id=a.id),0) as transfers_out,
        COALESCE((SELECT SUM(x.amount) FROM transfers x WHERE x.to_account_id=a.id),0) as transfers_in
        FROM accounts a ORDER BY a.id DESC")->fetchAll();
    foreach ($rows as &$r) $r['balance']=(int)$r['opening_balance']+(int)$r['incomes']-(int)$r['expenses']-(int)$r['transfers_out']+(int)$r['transfers_in'];
    return $rows;
}
$accounts = is_logged_in() ? account_balances($pdo) : [];
$accountsById=[]; foreach($accounts as $a) $accountsById[(int)$a['id']]=$a;
$today=date('Y-m-d');
$monthStart=date('Y-m-01');
$totals=['income'=>0,'expense'=>0];
if (is_logged_in()) {
    $st=$pdo->prepare("SELECT kind,COALESCE(SUM(amount),0) total FROM transactions WHERE transaction_date=? GROUP BY kind"); $st->execute([$today]);
    foreach($st as $r) $totals[$r['kind']]=(int)$r['total'];
    $allBalance=array_sum(array_column($accounts,'balance'));
    $st=$pdo->prepare("SELECT kind,COALESCE(SUM(amount),0) total FROM transactions WHERE transaction_date>=? AND transaction_date<=? GROUP BY kind"); $st->execute([$monthStart,$today]);
    $monthTotals=['income'=>0,'expense'=>0]; foreach($st as $r) $monthTotals[$r['kind']]=(int)$r['total'];
}
$msg=(string)($_GET['msg']??'');
$err=$error??'';
function navlink(string $p,string $label,string $icon,string $current): string {
    $active=$p===$current?' active':'';
    return '<a class="menu-item'.$active.'" href="index.php?page='.e($p).'"><span class="nav-icon">'. $icon .'</span><span>'.e($label).'</span></a>';
}
?><!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#2563eb">
<title><?=e(APP_NAME)?></title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://cdn.jsdelivr.net/npm/daisyui@4.12.24/dist/full.min.css" rel="stylesheet" type="text/css">
<link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jalaali-js@1.2.8/dist/jalaali.min.js"></script>
<style>
:root{font-family:'Vazirmatn',sans-serif}*{font-family:'Vazirmatn',sans-serif}body{background:#f4f7fb;color:#172033}.sidebar{width:270px;position:fixed;inset:0 0 0 auto;background:#fff;border-left:1px solid #e6edf5;z-index:30;padding:22px 16px;transition:transform .25s}.main{margin-right:270px;min-height:100vh}.menu-item{display:flex;align-items:center;gap:12px;padding:12px 14px;border-radius:13px;color:#64748b;font-size:14px;margin:4px 0}.menu-item:hover,.menu-item.active{background:#eaf2ff;color:#1d4ed8;font-weight:700}.nav-icon{font-size:18px;width:24px;text-align:center}.topbar{height:76px;background:#fff;border-bottom:1px solid #e6edf5;display:flex;align-items:center;justify-content:space-between;padding:0 28px}.content{padding:28px;max-width:1500px;margin:auto}.panel{background:white;border:1px solid #e8edf4;border-radius:18px;box-shadow:0 3px 14px #0f172a05}.stat{padding:22px}.stat-label{color:#64748b;font-size:13px}.stat-value{font-size:24px;font-weight:800;margin-top:8px}.field{width:100%;border:1px solid #dbe3ef;border-radius:12px;padding:11px 13px;background:#fff;outline:none}.field:focus{border-color:#3b82f6;box-shadow:0 0 0 3px #3b82f622}.btn-primary{background:#2563eb;border-color:#2563eb;color:#fff}.table th{background:#f8fafc;color:#64748b;font-weight:600}.table :where(th,td){padding:13px 14px;white-space:nowrap}.positive{color:#059669}.negative{color:#dc2626}.sidebar-backdrop{display:none}
.sidebar .menu-item{direction:ltr;flex-direction:row;justify-content:flex-start;text-align:left}
.sidebar .menu-item span:last-child{direction:ltr;text-align:left}
.sidebar .nav-icon{font-size:19px;flex:0 0 24px;text-align:left}
.sidebar .wp-subtitle{direction:ltr;text-align:left;padding-left:14px}
.sidebar-toggle{border:1px solid #e2e8f0;border-radius:10px;width:40px;height:40px;display:inline-flex;align-items:center;justify-content:center;background:#fff}
.wp-subtitle{font-size:11px;color:#94a3b8;padding:12px 13px 4px}
.modal-box{max-width:760px;width:calc(100% - 24px);max-height:90vh;overflow:auto;border-radius:20px}.jalali-picker-wrap{position:relative}.jalali-picker-wrap .jalali-input{padding-left:44px}.calendar-trigger{position:absolute;left:7px;top:50%;transform:translateY(-50%);width:32px;height:32px;display:flex;align-items:center;justify-content:center;border-radius:8px;color:#2563eb;background:#eff6ff;font-size:18px}.jalali-calendar{position:absolute;z-index:70;top:calc(100% + 6px);right:0;width:300px;background:#fff;border:1px solid #dbe3ef;box-shadow:0 12px 35px #0f172a20;border-radius:16px;padding:12px}.jalali-calendar-grid{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:4px}.jalali-calendar button{border-radius:8px;padding:7px 0}.jalali-calendar button:hover{background:#dbeafe}.jalali-calendar .selected{background:#2563eb;color:white}.jalali-calendar .muted{color:#cbd5e1}.amount-words{display:block;min-height:20px;line-height:1.8}.sidebar .menu-item{direction:rtl;text-align:right}
body.sidebar-collapsed .sidebar{transform:translateX(100%)}
body.sidebar-collapsed .main{margin-right:0}
@media(min-width:901px){body.sidebar-collapsed .sidebar{transform:translateX(100%)}body.sidebar-collapsed .main{margin-right:0}}
@media(max-width:900px){body.sidebar-collapsed .sidebar{transform:translateX(100%)}body.sidebar-collapsed .sidebar.open{transform:translateX(0)}body.sidebar-collapsed .main{margin-right:0}}
@media(max-width:900px){.sidebar{transform:translateX(100%)}.sidebar.open{transform:translateX(0)}.main{margin-right:0}.content{padding:18px}.topbar{padding:0 16px}.sidebar-backdrop.show{display:block;position:fixed;inset:0;background:#0f172a66;z-index:20}}
</style>
</head>
<body>
<?php if ($page==='setup' || $page==='login'): ?>
<div class="min-h-screen flex items-center justify-center p-5" style="background:linear-gradient(135deg,#eff6ff,#f8fafc)">
 <div class="panel p-8 w-full max-w-md">
  <div class="w-14 h-14 rounded-2xl bg-blue-600 text-white flex items-center justify-center text-2xl mb-5">▦</div>
  <h1 class="text-2xl font-extrabold mb-2"><?= $page==='setup'?'ساخت حساب مدیر':'ورود به حسابداری' ?></h1>
  <p class="text-slate-500 text-sm mb-6"><?= $page==='setup'?'برای شروع، نام کاربری و رمز عبور مدیر را تعیین کنید.':'برای ادامه نام کاربری و رمز عبور خود را وارد کنید.' ?></p>
  <?php if($msg): ?><div class="alert alert-success mb-4 text-sm"><?=e($msg)?></div><?php endif; ?>
  <?php if($err): ?><div class="alert alert-error mb-4 text-sm"><?=e($err)?></div><?php endif; ?>
  <form method="post" class="space-y-4">
   <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
   <input type="hidden" name="action" value="<?=$page==='setup'?'setup_admin':'login'?>">
   <label class="form-control"><span class="label-text mb-2">نام کاربری</span><input class="field" name="username" required minlength="3" autocomplete="username"></label>
   <label class="form-control"><span class="label-text mb-2">رمز عبور<?=$page==='setup'?' (حداقل ۱۰ نویسه)':''?></span><input class="field" type="password" name="password" required <?=$page==='setup'?'minlength="10"':''?> autocomplete="<?=$page==='setup'?'new-password':'current-password'?>"></label>
   <button class="btn btn-primary w-full"><?= $page==='setup'?'ساخت مدیر و ورود':'ورود امن' ?></button>
  </form>
  <p class="text-xs text-slate-400 mt-6">ذخیره‌سازی محلی روی همین هاست با پایگاه داده SQLite</p>
 </div>
</div>
<?php else: ?>
<div class="sidebar-backdrop" id="backdrop" onclick="toggleSidebar()"></div>
<aside class="sidebar" id="sidebar">
 <div class="flex items-center gap-3 px-3 mb-9">
  <div class="w-11 h-11 rounded-2xl bg-blue-600 text-white flex items-center justify-center text-xl">▦</div>
  <div><div class="font-extrabold text-lg">حسابداری روزانه</div><div class="text-xs text-slate-400">مدیریت دخل‌وخرج</div></div>
 </div>
 <div class="wp-subtitle">مدیریت</div>
 <?=navlink('dashboard','داشبورد','⌂',$page)?>
 <?=navlink('transactions','ثبت درآمد و هزینه','＋',$page)?>
 <div class="mr-5 border-r-2 border-blue-100 pr-3"><?=navlink('categories','دسته‌بندی‌ها','▦',$page)?></div>
 <?=navlink('accounts','حساب‌های بانکی','▤',$page)?>
 <?=navlink('transfer','انتقال بین حساب‌ها','⇄',$page)?>
 <?=navlink('reports','گزارش و آمار','▥',$page)?>
 <?=navlink('backup','پشتیبان‌گیری','⇩',$page)?>
 <div class="absolute bottom-5 right-4 left-4 border-t pt-4">
  <div class="text-xs text-slate-400 mb-3 px-3">مدیر: <?=e($_SESSION['username']??'')?></div>
  <a class="menu-item" href="index.php?page=logout"><span class="nav-icon">↪</span><span>خروج از حساب</span></a>
 </div>
</aside>
<div class="main">
 <header class="topbar">
  <div class="flex items-center gap-3"><button class="sidebar-toggle" onclick="toggleSidebar()" aria-label="باز و بسته کردن منو">☰</button><div><div class="font-bold"><?=e(['dashboard'=>'داشبورد','transactions'=>'ثبت درآمد و هزینه','categories'=>'دسته‌بندی‌ها','accounts'=>'کارت‌های بانکی و صندوق','transfer'=>'انتقال بین حساب‌ها','reports'=>'گزارش و آمار','backup'=>'پشتیبان‌گیری'][$page]??'حسابداری')?></div><div class="text-xs text-slate-400 mt-1" id="todayJalali"></div></div></div>
  <div class="flex items-center gap-2"><span class="badge badge-info badge-outline">تومان</span><div class="avatar placeholder"><div class="bg-blue-100 text-blue-700 rounded-full w-10"><span>م</span></div></div></div>
 </header>
 <main class="content">
 <?php if($msg): ?><div class="alert alert-success mb-5 text-sm"><?=e($msg)?></div><?php endif; ?>
 <?php if($err): ?><div class="alert alert-error mb-5 text-sm"><?=e($err)?></div><?php endif; ?>

 <?php if($page==='dashboard'): ?>
 <div class="flex flex-wrap justify-between items-end gap-4 mb-6"><div><h1 class="text-2xl font-extrabold">سلام، <?=e($_SESSION['username']??'مدیر')?> 👋</h1><p class="text-slate-500 mt-2 text-sm">خلاصه وضعیت مالی و گردش حساب‌های شما</p></div><a href="index.php?page=transactions&openModal=1" class="btn btn-primary">＋ ثبت تراکنش</a></div>
 <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 mb-6">
  <div class="panel stat"><div class="stat-label">موجودی کل حساب‌ها</div><div class="stat-value"><?=toman((int)($allBalance??0))?></div><div class="text-xs text-slate-400 mt-3">پس از درآمد، هزینه و انتقال‌ها</div></div>
  <div class="panel stat"><div class="stat-label">درآمد امروز</div><div class="stat-value positive"><?=toman($totals['income'])?></div><div class="text-xs text-slate-400 mt-3">ثبت‌شده برای امروز</div></div>
  <div class="panel stat"><div class="stat-label">هزینه امروز</div><div class="stat-value negative"><?=toman($totals['expense'])?></div><div class="text-xs text-slate-400 mt-3">ثبت‌شده برای امروز</div></div>
  <div class="panel stat"><div class="stat-label">خالص امروز</div><div class="stat-value"><?=toman($totals['income']-$totals['expense'])?></div><div class="text-xs text-slate-400 mt-3">درآمد منهای هزینه</div></div>
 </div>
 <div class="grid grid-cols-1 xl:grid-cols-3 gap-5 mb-6">
  <div class="panel p-5 xl:col-span-2"><div class="flex justify-between items-center mb-4"><h2 class="font-bold">درآمد و هزینه ماه جاری</h2><span class="text-xs text-slate-400">تا امروز</span></div><canvas id="monthChart" height="120"></canvas></div>
  <div class="panel p-5"><h2 class="font-bold mb-4">حساب‌ها</h2>
   <?php if(!$accounts): ?><p class="text-sm text-slate-500">هنوز حسابی ثبت نشده است.</p><a class="btn btn-primary btn-sm mt-3" href="index.php?page=accounts">افزودن حساب</a><?php else: foreach(array_slice($accounts,0,5) as $a): ?>
   <div class="flex items-center justify-between gap-3 py-3 border-b last:border-0"><div><div class="font-semibold text-sm"><?=e($a['name'])?></div><div class="text-xs text-slate-400 mt-1"><?=e($a['bank_name']?:($a['account_type']==='cash'?'صندوق نقدی':'حساب بانکی'))?></div></div><div class="text-sm font-bold"><?=toman((int)$a['balance'])?></div></div>
   <?php endforeach; endif; ?>
  </div>
 </div>
 <div class="panel p-5"><div class="flex justify-between items-center mb-3"><h2 class="font-bold">آخرین تراکنش‌ها</h2><a class="link link-primary text-sm" href="index.php?page=transactions">مشاهده همه</a></div>
 <?php $recent=$pdo->query("SELECT t.*,a.name account_name FROM transactions t JOIN accounts a ON a.id=t.account_id ORDER BY t.transaction_date DESC,t.id DESC LIMIT 8")->fetchAll(); ?>
 <?php if(!$recent): ?><p class="text-sm text-slate-500 py-5">هنوز تراکنشی ثبت نشده است.</p><?php else: ?><div class="overflow-x-auto"><table class="table w-full"><thead><tr><th>نوع</th><th>شرح</th><th>حساب</th><th>تاریخ</th><th>مبلغ</th></tr></thead><tbody><?php foreach($recent as $r): ?><tr><td><span class="badge <?=$r['kind']==='income'?'badge-success':'badge-error'?> badge-outline"><?=$r['kind']==='income'?'درآمد':'هزینه'?></span></td><td><?=e($r['description']?:$r['category']?:'—')?></td><td><?=e($r['account_name'])?></td><td class="greg-date"><?=e($r['transaction_date'])?></td><td class="<?=$r['kind']==='income'?'positive':'negative'?> font-bold"><?=($r['kind']==='income'?'+':'−').toman((int)$r['amount'])?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
 </div>
 <?php elseif($page==='categories'): ?>
 <div class="flex flex-wrap justify-between items-end gap-4 mb-6"><div><h1 class="text-2xl font-extrabold mb-2">دسته‌بندی درآمد و هزینه</h1><p class="text-slate-500 text-sm">دسته‌بندی‌های دلخواه بسازید تا هنگام ثبت تراکنش انتخاب شوند.</p></div></div>
 <div class="grid grid-cols-1 lg:grid-cols-3 gap-5"><div class="panel p-5"><h2 class="font-bold mb-4">افزودن دسته‌بندی</h2><form method="post" class="space-y-4"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="add_category"><label class="form-control"><span class="label-text mb-2">نام دسته‌بندی</span><input class="field" name="name" required maxlength="80" placeholder="مثلاً تعمیرات"></label><label class="form-control"><span class="label-text mb-2">قابل استفاده برای</span><select class="field" name="kind"><option value="both">درآمد و هزینه</option><option value="income">درآمد</option><option value="expense">هزینه</option></select></label><button class="btn btn-primary w-full">افزودن دسته‌بندی</button></form></div><div class="panel p-5 lg:col-span-2"><h2 class="font-bold mb-4">فهرست دسته‌بندی‌ها</h2><div class="overflow-x-auto"><table class="table w-full"><thead><tr><th>نام</th><th>نوع</th><th>عملیات</th></tr></thead><tbody><?php foreach($pdo->query('SELECT * FROM categories ORDER BY name') as $cat): ?><tr><td class="font-semibold"><?=e($cat['name'])?></td><td><?=e(['both'=>'درآمد و هزینه','income'=>'درآمد','expense'=>'هزینه'][$cat['kind']]??'هر دو')?></td><td><form method="post" onsubmit="return confirm('این دسته‌بندی حذف شود؟ تراکنش‌های قبلی به دسته عمومی منتقل می‌شوند.')"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="delete_category"><input type="hidden" name="id" value="<?=$cat['id']?>"><button class="btn btn-ghost btn-xs text-error">حذف</button></form></td></tr><?php endforeach; ?></tbody></table></div></div></div>
 <?php elseif($page==='accounts'): ?>
 <div class="flex flex-wrap justify-between items-end gap-4 mb-6"><div><h1 class="text-2xl font-extrabold mb-2">کارت‌های بانکی و صندوق</h1><p class="text-slate-500 text-sm">مدیریت کارت‌ها، موجودی اولیه و حساب‌های نقدی</p></div><button class="btn btn-primary" onclick="document.getElementById('accountAddModal').showModal()">＋ افزودن کارت بانکی</button></div>
 <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
 <?php foreach($accounts as $a): ?>
 <div class="panel p-5">
  <div class="flex items-start justify-between gap-3"><div class="w-12 h-12 rounded-2xl bg-blue-50 text-blue-700 flex items-center justify-center text-2xl">▤</div><span class="badge badge-outline"><?=$a['account_type']==='cash'?'صندوق نقدی':($a['account_type']==='bank'?'کارت بانکی':'سایر')?></span></div>
  <h3 class="font-bold text-lg mt-4"><?=e($a['name'])?></h3><p class="text-sm text-slate-400 mt-1"><?=e($a['bank_name']?:'بدون نام بانک')?></p>
  <div class="text-xs text-slate-500 mt-5">موجودی فعلی</div><div class="text-xl font-extrabold mt-1"><?=toman((int)$a['balance'])?></div>
  <div class="grid grid-cols-2 gap-3 mt-5 text-xs"><div class="bg-emerald-50 rounded-xl p-3"><div class="text-emerald-700">مجموع درآمد</div><b class="block mt-1"><?=toman((int)$a['incomes'])?></b></div><div class="bg-rose-50 rounded-xl p-3"><div class="text-rose-700">مجموع هزینه</div><b class="block mt-1"><?=toman((int)$a['expenses'])?></b></div></div>
  <div class="flex gap-2 mt-5">
   <button class="btn btn-outline btn-primary btn-sm flex-1" onclick='openEditAccount(<?=json_encode(["id"=>(int)$a["id"],"name"=>$a["name"],"bank_name"=>$a["bank_name"],"account_type"=>$a["account_type"],"opening_balance"=>(int)$a["opening_balance"]],JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_TAG|JSON_HEX_AMP)?>)'>✎ ویرایش</button>
   <form method="post" onsubmit="return confirm('کارت بانکی حذف شود؟ اگر تراکنش داشته باشد حذف نمی‌شود.')" class="flex-1"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="delete_account"><input type="hidden" name="id" value="<?=$a['id']?>"><button class="btn btn-outline btn-error btn-sm w-full">حذف</button></form>
  </div>
 </div>
 <?php endforeach; ?>
 <?php if(!$accounts): ?><div class="panel p-8 text-center text-slate-500 md:col-span-2 xl:col-span-3">هنوز کارتی ثبت نشده است. از دکمه «افزودن کارت بانکی» استفاده کنید.</div><?php endif; ?>
 </div>
 <dialog id="accountAddModal" class="modal"><div class="modal-box"><div class="flex justify-between items-center mb-5"><h2 class="text-xl font-extrabold">افزودن کارت بانکی / حساب</h2><form method="dialog"><button class="btn btn-sm btn-circle btn-ghost">✕</button></form></div>
 <form method="post" class="grid grid-cols-1 md:grid-cols-2 gap-4"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="add_account">
 <label class="form-control"><span class="label-text mb-2">نام کارت بانکی یا حساب *</span><input class="field" name="name" placeholder="مثلاً کارت بانک ملی" required></label>
 <label class="form-control"><span class="label-text mb-2">نام بانک</span><input class="field" name="bank_name" placeholder="مثلاً ملی، ملت، سامان"></label>
 <label class="form-control"><span class="label-text mb-2">نوع</span><select class="field" name="account_type"><option value="bank">کارت بانکی</option><option value="cash">صندوق نقدی</option><option value="other">سایر</option></select></label>
 <label class="form-control"><span class="label-text mb-2">موجودی اولیه (تومان)</span><input class="field persian-input amount-input" name="opening_balance" inputmode="numeric" value="۰"><small class="amount-words text-sm text-blue-700 mt-2" aria-live="polite"></small></label>
 <div class="md:col-span-2 flex justify-end gap-2"><form method="dialog"><button type="button" class="btn btn-ghost" onclick="document.getElementById('accountAddModal').close()">انصراف</button></form><button class="btn btn-primary">ذخیره کارت</button></div></form></div><form method="dialog" class="modal-backdrop"><button>بستن</button></form></dialog>
 <dialog id="accountEditModal" class="modal"><div class="modal-box"><div class="flex justify-between items-center mb-5"><h2 class="text-xl font-extrabold">ویرایش کارت بانکی</h2><form method="dialog"><button class="btn btn-sm btn-circle btn-ghost">✕</button></form></div>
 <form method="post" class="grid grid-cols-1 md:grid-cols-2 gap-4"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="update_account"><input type="hidden" name="id" id="editAccountId">
 <label class="form-control"><span class="label-text mb-2">نام کارت بانکی *</span><input class="field" name="name" id="editAccountName" required></label><label class="form-control"><span class="label-text mb-2">نام بانک</span><input class="field" name="bank_name" id="editBankName"></label>
 <label class="form-control"><span class="label-text mb-2">نوع</span><select class="field" name="account_type" id="editAccountType"><option value="bank">کارت بانکی</option><option value="cash">صندوق نقدی</option><option value="other">سایر</option></select></label>
 <label class="form-control"><span class="label-text mb-2">موجودی اولیه (تومان)</span><input class="field persian-input amount-input" name="opening_balance" id="editOpeningBalance" inputmode="numeric" required><small class="amount-words text-sm text-blue-700 mt-2" aria-live="polite"></small></label>
 <div class="md:col-span-2 flex justify-end gap-2"><button type="button" class="btn btn-ghost" onclick="document.getElementById('accountEditModal').close()">انصراف</button><button class="btn btn-primary">ذخیره تغییرات</button></div></form></div><form method="dialog" class="modal-backdrop"><button>بستن</button></form></dialog>
 <?php elseif($page==='transactions'): ?>
 <h1 class="text-2xl font-extrabold mb-2">ثبت درآمد و هزینه</h1><p class="text-slate-500 text-sm mb-6">هر تراکنش را به حسابی که پول وارد یا از آن خارج شده متصل کنید.</p>
 <?php if(!$accounts): ?><div class="alert alert-warning mb-5">برای ثبت تراکنش ابتدا یک کارت بانکی یا صندوق بسازید. <a class="link" href="index.php?page=accounts">افزودن حساب</a></div><?php endif; ?>
 <div class="flex flex-wrap justify-between items-end gap-4 mb-6"><div><h1 class="text-2xl font-extrabold mb-2">درآمد و هزینه</h1><p class="text-slate-500 text-sm">ثبت تراکنش‌ها در پنجره جداگانه</p></div><button class="btn btn-primary" onclick="document.getElementById('transactionModal').showModal()">＋ ثبت درآمد یا هزینه</button></div>
 <?php if(!$accounts): ?><div class="alert alert-warning mb-5">برای ثبت تراکنش ابتدا یک کارت بانکی یا صندوق بسازید. <a class="link" href="index.php?page=accounts">افزودن کارت</a></div><?php endif; ?>
 <dialog id="transactionModal" class="modal"><div class="modal-box"><div class="flex justify-between items-center mb-5"><h2 class="text-xl font-extrabold">ثبت درآمد یا هزینه</h2><form method="dialog"><button class="btn btn-sm btn-circle btn-ghost">✕</button></form></div>
 <form method="post" class="grid grid-cols-1 md:grid-cols-2 gap-4"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="save_transaction">
 <label class="form-control"><span class="label-text mb-2">نوع تراکنش</span><select class="field" name="kind"><option value="expense">هزینه / برداشت</option><option value="income">درآمد / واریز</option></select></label>
 <label class="form-control"><span class="label-text mb-2">مبلغ (تومان) *</span><input class="field persian-input amount-input" name="amount" inputmode="numeric" placeholder="مثلاً ۲۵۰٬۰۰۰" required><small class="amount-words text-sm text-blue-700 mt-2" aria-live="polite"></small></label>
 <label class="form-control"><span class="label-text mb-2">کارت بانکی یا صندوق *</span><select class="field" name="account_id" required><option value="">انتخاب کارت</option><?php foreach($accounts as $a): ?><option value="<?=$a['id']?>"><?=e($a['name'])?> — <?=toman((int)$a['balance'])?></option><?php endforeach; ?></select></label>
 <label class="form-control"><span class="label-text mb-2">دسته‌بندی</span><select class="field" name="category"><?php foreach($pdo->query("SELECT name FROM categories ORDER BY name") as $cat): ?><option><?=e($cat['name'])?></option><?php endforeach; ?></select></label>
 <label class="form-control"><span class="label-text mb-2">تاریخ شمسی *</span><div class="jalali-picker-wrap"><input class="field jalali-input" id="transactionDate" name="transaction_date" placeholder="۱۴۰۵/۰۷/۲۰" readonly required><button type="button" class="calendar-trigger" onclick="openJalaliPicker(this)" aria-label="انتخاب تاریخ شمسی" title="انتخاب تاریخ شمسی">▦</button></div></label>
 <label class="form-control"><span class="label-text mb-2">شماره پیگیری (اختیاری)</span><input class="field" name="reference_no"></label>
 <label class="form-control md:col-span-2"><span class="label-text mb-2">توضیحات</span><input class="field" name="description" placeholder="شرح کوتاه تراکنش"></label>
 <div class="md:col-span-2 flex justify-end gap-2"><button type="button" class="btn btn-ghost" onclick="document.getElementById('transactionModal').close()">انصراف</button><button class="btn btn-primary" <?=$accounts?'':'disabled'?>>ذخیره تراکنش</button></div></form></div><form method="dialog" class="modal-backdrop"><button>بستن</button></form></dialog>
 <div class="panel p-5"><h2 class="font-bold mb-4">فهرست تراکنش‌ها</h2><form method="get" class="flex flex-wrap gap-3 mb-4"><input type="hidden" name="page" value="transactions"><input class="field max-w-xs jalali-input" name="from" placeholder="از تاریخ شمسی"><input class="field max-w-xs jalali-input" name="to" placeholder="تا تاریخ شمسی"><button class="btn btn-outline">فیلتر</button><a href="index.php?page=transactions" class="btn btn-ghost">پاک کردن</a></form>
 <?php
 $from=trim((string)($_GET['from']??'')); $to=trim((string)($_GET['to']??'')); $where=[];$params=[];
 if($from!==''){ $f=en_digits(str_replace('/','-',$from)); if(preg_match('/^\d{4}-\d{2}-\d{2}$/',$f)){ $f=jalaali_to_gregorian_php($f); if($f){$where[]='t.transaction_date>=?';$params[]=$f;} } }
 if($to!==''){ $t=en_digits(str_replace('/','-',$to)); if(preg_match('/^\d{4}-\d{2}-\d{2}$/',$t)){ $t=jalaali_to_gregorian_php($t); if($t){$where[]='t.transaction_date<=?';$params[]=$t;} } }
 $sql="SELECT t.*,a.name account_name FROM transactions t JOIN accounts a ON a.id=t.account_id".($where?' WHERE '.implode(' AND ',$where):'')." ORDER BY t.transaction_date DESC,t.id DESC LIMIT 500";
 $st=$pdo->prepare($sql);$st->execute($params);$trs=$st->fetchAll();
 ?>
 <div class="overflow-x-auto"><table class="table w-full"><thead><tr><th>نوع</th><th>تاریخ</th><th>شرح / دسته</th><th>حساب</th><th>شماره پیگیری</th><th>مبلغ</th><th>حذف</th></tr></thead><tbody><?php foreach($trs as $t): ?><tr><td><span class="badge <?=$t['kind']==='income'?'badge-success':'badge-error'?> badge-outline"><?=$t['kind']==='income'?'درآمد':'هزینه'?></span></td><td class="greg-date"><?=e($t['transaction_date'])?></td><td><?=e($t['description']?:'—')?> <span class="text-xs text-slate-400 block"><?=e($t['category'])?></span></td><td><?=e($t['account_name'])?></td><td><?=e($t['reference_no']?:'—')?></td><td class="font-bold <?=$t['kind']==='income'?'positive':'negative'?>"><?=toman((int)$t['amount'])?></td><td><form method="post" onsubmit="return confirm('این تراکنش حذف شود؟')"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="delete_transaction"><input type="hidden" name="id" value="<?=$t['id']?>"><button class="btn btn-ghost btn-xs text-error">حذف</button></form></td></tr><?php endforeach; ?><?php if(!$trs): ?><tr><td colspan="7" class="text-center text-slate-400 py-8">تراکنشی پیدا نشد.</td></tr><?php endif; ?></tbody></table></div><p class="text-xs text-slate-400 mt-3">برای جلوگیری از حذف ناخواسته، ویرایش مستقیم تراکنش در این نسخه فعال نیست؛ تراکنش اشتباه را حذف و دوباره ثبت کنید.</p></div>
 <?php elseif($page==='transfer'): ?>
 <h1 class="text-2xl font-extrabold mb-2">انتقال بین حساب‌ها</h1><p class="text-slate-500 text-sm mb-6">انتقال وجه موجودی دو حساب را تغییر می‌دهد اما در گزارش درآمد و هزینه محاسبه نمی‌شود.</p>
 <div class="flex flex-wrap justify-between items-end gap-4 mb-6"><div><h1 class="text-2xl font-extrabold mb-2">انتقال بین حساب‌ها</h1><p class="text-slate-500 text-sm">انتقال وجه روی موجودی حساب‌ها اثر می‌گذارد، نه درآمد و هزینه</p></div><button class="btn btn-primary" onclick="document.getElementById('transferModal').showModal()">⇄ انتقال وجه</button></div>
 <dialog id="transferModal" class="modal"><div class="modal-box"><div class="flex justify-between items-center mb-5"><h2 class="text-xl font-extrabold">انتقال وجه بین حساب‌ها</h2><form method="dialog"><button class="btn btn-sm btn-circle btn-ghost">✕</button></form></div>
 <form method="post" class="grid grid-cols-1 md:grid-cols-2 gap-4"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="transfer">
 <label class="form-control"><span class="label-text mb-2">کارت مبدأ</span><select class="field" name="from_account_id" required><option value="">انتخاب کارت</option><?php foreach($accounts as $a): ?><option value="<?=$a['id']?>"><?=e($a['name'])?> — <?=toman((int)$a['balance'])?></option><?php endforeach; ?></select></label>
 <label class="form-control"><span class="label-text mb-2">کارت مقصد</span><select class="field" name="to_account_id" required><option value="">انتخاب کارت</option><?php foreach($accounts as $a): ?><option value="<?=$a['id']?>"><?=e($a['name'])?></option><?php endforeach; ?></select></label>
 <label class="form-control"><span class="label-text mb-2">مبلغ (تومان)</span><input class="field persian-input amount-input" name="amount" inputmode="numeric" required><small class="amount-words text-sm text-blue-700 mt-2" aria-live="polite"></small></label><label class="form-control"><span class="label-text mb-2">تاریخ شمسی</span><div class="jalali-picker-wrap"><input class="field jalali-input" name="transfer_date" placeholder="۱۴۰۵/۰۷/۲۰" readonly required><button type="button" class="calendar-trigger" onclick="openJalaliPicker(this)" aria-label="انتخاب تاریخ شمسی" title="انتخاب تاریخ شمسی">▦</button></div></label>
 <label class="form-control md:col-span-2"><span class="label-text mb-2">توضیحات</span><input class="field" name="description"></label><div class="md:col-span-2 flex justify-end gap-2"><button type="button" class="btn btn-ghost" onclick="document.getElementById('transferModal').close()">انصراف</button><button class="btn btn-primary" <?=$accounts?'':'disabled'?>>ثبت انتقال</button></div></form></div><form method="dialog" class="modal-backdrop"><button>بستن</button></form></dialog>
 <div class="panel p-5"><h2 class="font-bold mb-4">انتقال‌های اخیر</h2><div class="overflow-x-auto"><table class="table w-full"><thead><tr><th>تاریخ</th><th>مبدأ</th><th>مقصد</th><th>شرح / اطلاعات مقصد</th><th>مبلغ</th></tr></thead><tbody><?php $trs=$pdo->query('SELECT x.*,a.name from_name,b.name to_name FROM transfers x JOIN accounts a ON a.id=x.from_account_id JOIN accounts b ON b.id=x.to_account_id ORDER BY x.transfer_date DESC,x.id DESC LIMIT 200')->fetchAll(); foreach($trs as $t): ?><tr><td class="greg-date"><?=e($t['transfer_date'])?></td><td><?=e($t['from_name'])?></td><td><?=e($t['to_name'])?></td><td><?=e($t['description']?:'—')?><?php if(!empty($t['destination_ref'])): ?><span class="text-xs text-slate-400 block"><?=($t['destination_ref_type']??'card')==='iban'?'شبا: ':'کارت: '?><?=e($t['destination_ref'])?><?php if(!empty($t['destination_owner'])): ?> — <?=e($t['destination_owner'])?><?php endif; ?></span><?php endif; ?></td><td class="font-bold"><?=toman((int)$t['amount'])?></td></tr><?php endforeach; ?></tbody></table></div></div>
 <?php elseif($page==='reports'): ?>
 <h1 class="text-2xl font-extrabold mb-2">گزارش و آمار</h1><p class="text-slate-500 text-sm mb-6">نمای کلی درآمد و هزینه در ماه جاری و گردش حساب‌ها</p>
 <?php $st=$pdo->prepare("SELECT kind,COALESCE(SUM(amount),0) total,COUNT(*) cnt FROM transactions WHERE transaction_date>=? AND transaction_date<=? GROUP BY kind");$st->execute([$monthStart,$today]);$mt=['income'=>0,'expense'=>0,'income_cnt'=>0,'expense_cnt'=>0];foreach($st as $r){$mt[$r['kind']]=(int)$r['total'];$mt[$r['kind'].'_cnt']=(int)$r['cnt'];} ?>
 <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6"><div class="panel stat"><div class="stat-label">درآمد ماه جاری</div><div class="stat-value positive"><?=toman($mt['income'])?></div><div class="text-xs text-slate-400 mt-2"><?=fa_digits($mt['income_cnt'])?> تراکنش</div></div><div class="panel stat"><div class="stat-label">هزینه ماه جاری</div><div class="stat-value negative"><?=toman($mt['expense'])?></div><div class="text-xs text-slate-400 mt-2"><?=fa_digits($mt['expense_cnt'])?> تراکنش</div></div><div class="panel stat"><div class="stat-label">مانده خالص ماه</div><div class="stat-value"><?=toman($mt['income']-$mt['expense'])?></div><div class="text-xs text-slate-400 mt-2">درآمد منهای هزینه</div></div></div>
 <div class="grid grid-cols-1 xl:grid-cols-2 gap-5 mb-6"><div class="panel p-5"><h2 class="font-bold mb-4">درآمد در برابر هزینه</h2><canvas id="reportPie" height="220"></canvas></div><div class="panel p-5"><h2 class="font-bold mb-4">گردش حساب‌ها</h2><div class="overflow-x-auto"><table class="table w-full"><thead><tr><th>حساب</th><th>درآمد</th><th>هزینه</th><th>موجودی</th></tr></thead><tbody><?php foreach($accounts as $a): ?><tr><td><?=e($a['name'])?></td><td class="positive"><?=toman((int)$a['incomes'])?></td><td class="negative"><?=toman((int)$a['expenses'])?></td><td class="font-bold"><?=toman((int)$a['balance'])?></td></tr><?php endforeach; ?></tbody></table></div></div></div>
 <div class="panel p-5"><h2 class="font-bold mb-4">گزارش ماهانه (۱۲ ماه اخیر)</h2><canvas id="yearChart" height="110"></canvas></div>
 <?php elseif($page==='backup'): ?>
 <h1 class="text-2xl font-extrabold mb-2">پشتیبان‌گیری و خروجی</h1><p class="text-slate-500 text-sm mb-6">نسخه پشتیبان پایگاه داده و خروجی قابل بازکردن در نرم‌افزارهای صفحه‌گسترده.</p>
 <div class="grid grid-cols-1 md:grid-cols-2 gap-5"><div class="panel p-6"><div class="text-3xl text-blue-600 mb-3">▣</div><h2 class="font-bold text-lg">پشتیبان کامل SQLite</h2><p class="text-sm text-slate-500 mt-2 mb-5">شامل حساب‌ها، تراکنش‌ها و انتقال‌ها. فایل را در محل امن نگه دارید.</p><a class="btn btn-primary" href="download.php?type=sqlite">دریافت فایل پشتیبان</a></div><div class="panel p-6"><div class="text-3xl text-blue-600 mb-3">▤</div><h2 class="font-bold text-lg">خروجی تراکنش‌ها CSV</h2><p class="text-sm text-slate-500 mt-2 mb-5">قابل بازکردن در Excel؛ متن فارسی با UTF-8.</p><a class="btn btn-outline btn-primary" href="download.php?type=csv">دریافت فایل CSV</a></div></div>
 <div class="alert alert-info mt-5 text-sm">توصیه: هر چند روز یک نسخه پشتیبان دانلود کنید و در دستگاه دیگری نگه دارید.</div>
 <?php endif; ?>
 </main>
</div>
<?php endif; ?>
<script>
function toggleSidebar(){
 if(window.innerWidth<=900){document.getElementById('sidebar')?.classList.toggle('open');document.getElementById('backdrop')?.classList.toggle('show')}
 else {document.body.classList.toggle('sidebar-collapsed')}
}
function openEditAccount(a){
 document.getElementById('editAccountId').value=a.id;
 document.getElementById('editAccountName').value=a.name||'';
 document.getElementById('editBankName').value=a.bank_name||'';
 document.getElementById('editAccountType').value=a.account_type||'bank';
 document.getElementById('editOpeningBalance').value=faNum(a.opening_balance||0);
 formatAmount(document.getElementById('editOpeningBalance'));
 document.getElementById('accountEditModal').showModal();
}
const faMap={'0':'۰','1':'۱','2':'۲','3':'۳','4':'۴','5':'۵','6':'۶','7':'۷','8':'۸','9':'۹'};
function faNum(s){return String(s).replace(/[0-9]/g,d=>faMap[d])}
function enNum(s){return String(s).replace(/[۰-۹]/g,d=>'۰۱۲۳۴۵۶۷۸۹'.indexOf(d)).replace(/[٠-٩]/g,d=>'٠١٢٣٤٥٦٧٨٩'.indexOf(d))}
function todayJ(){if(window.jalaali){const d=new Date(),j=jalaali.toJalaali(d.getFullYear(),d.getMonth()+1,d.getDate());return `${faNum(j.jy)}/${faNum(String(j.jm).padStart(2,'0'))}/${faNum(String(j.jd).padStart(2,'0'))}`}return faNum(new Date().toLocaleDateString('fa-IR'))}
const tj=document.getElementById('todayJalali');if(tj)tj.textContent='امروز: '+todayJ();
function gregToJalali(iso){const p=iso.split('-').map(Number);if(!window.jalaali||p.length!==3)return faNum(iso);const j=jalaali.toJalaali(p[0],p[1],p[2]);return `${faNum(j.jy)}/${faNum(String(j.jm).padStart(2,'0'))}/${faNum(String(j.jd).padStart(2,'0'))}`}
function jalaliToGregorian(str){const s=enNum(str.trim()).replaceAll('-','/').split('/').map(Number);if(s.length!==3||!window.jalaali)return null;try{const g=jalaali.toGregorian(s[0],s[1],s[2]);return `${g.gy}-${String(g.gm).padStart(2,'0')}-${String(g.gd).padStart(2,'0')}`}catch(e){return null}}
document.querySelectorAll('.greg-date').forEach(el=>el.textContent=gregToJalali(el.textContent.trim()));
document.querySelectorAll('.jalali-input').forEach(el=>{if(!el.value&&el.hasAttribute('readonly'))el.value=todayJ()});
let pickerTarget=null, pickerYear=0, pickerMonth=0;
function openJalaliPicker(btn){pickerTarget=btn.parentElement.querySelector('.jalali-input');const parts=enNum((pickerTarget.value||todayJ()).replaceAll('-','/')).split('/').map(Number);pickerYear=parts[0]||1405;pickerMonth=parts[1]||7;renderJalaliPicker(btn.parentElement);}
function renderJalaliPicker(wrap){
 document.querySelectorAll('.jalali-calendar').forEach(n=>n.remove());
 const cal=document.createElement('div');cal.className='jalali-calendar';wrap.appendChild(cal);
 const title=document.createElement('div');title.className='flex items-center gap-2 mb-3';
 const monthSelect=document.createElement('select');monthSelect.className='field';monthSelect.style.padding='6px';monthSelect.style.flex='1';
 const monthNames=['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];
 monthNames.forEach((m,i)=>{const o=document.createElement('option');o.value=String(i+1);o.textContent=m;monthSelect.appendChild(o)});monthSelect.value=String(pickerMonth);
 const yearSelect=document.createElement('select');yearSelect.className='field';yearSelect.style.padding='6px';yearSelect.style.width='104px';
 for(let y=1300;y<=1450;y++){const o=document.createElement('option');o.value=String(y);o.textContent=faNum(y);yearSelect.appendChild(o)}yearSelect.value=String(Math.min(1450,Math.max(1300,pickerYear)));
 monthSelect.onchange=()=>{pickerMonth=Number(monthSelect.value);renderJalaliPicker(wrap)};
 yearSelect.onchange=()=>{pickerYear=Number(yearSelect.value);renderJalaliPicker(wrap)};
 const prev=document.createElement('button');prev.type='button';prev.className='btn btn-ghost btn-sm';prev.textContent='‹';prev.onclick=()=>{pickerMonth--;if(pickerMonth<1){pickerMonth=12;pickerYear--}renderJalaliPicker(wrap)};
 const next=document.createElement('button');next.type='button';next.className='btn btn-ghost btn-sm';next.textContent='›';next.onclick=()=>{pickerMonth++;if(pickerMonth>12){pickerMonth=1;pickerYear++}renderJalaliPicker(wrap)};
 title.append(prev,monthSelect,yearSelect,next);cal.appendChild(title);
 const grid=document.createElement('div');grid.className='jalali-calendar-grid text-center text-xs';['ش','ی','د','س','چ','پ','ج'].forEach(x=>{const e=document.createElement('b');e.textContent=x;grid.appendChild(e)});
 let first=jalaali.toGregorian(pickerYear,pickerMonth,1), offset=(new Date(first.gy,first.gm-1,first.gd).getDay()+1)%7;const days=jalaali.jalaaliMonthLength(pickerYear,pickerMonth);
 for(let i=0;i<offset;i++){const b=document.createElement('span');grid.appendChild(b)}
 for(let d=1;d<=days;d++){const b=document.createElement('button');b.type='button';b.textContent=faNum(d);b.onclick=()=>{pickerTarget.value=faNum(pickerYear)+'/'+faNum(String(pickerMonth).padStart(2,'0'))+'/'+faNum(String(d).padStart(2,'0'));cal.remove()};grid.appendChild(b)}cal.appendChild(grid);
 const foot=document.createElement('div');foot.className='flex justify-end mt-3';const today=document.createElement('button');today.type='button';today.className='btn btn-primary btn-sm';today.textContent='امروز';today.onclick=()=>{pickerTarget.value=todayJ();cal.remove()};foot.appendChild(today);cal.appendChild(foot);
}
document.addEventListener('click',e=>{if(!e.target.closest('.jalali-picker-wrap'))document.querySelectorAll('.jalali-calendar').forEach(n=>n.remove())});
document.querySelectorAll('form').forEach(form=>form.addEventListener('submit',ev=>{form.querySelectorAll('.jalali-input').forEach(el=>{const v=el.value.trim();if(v){const iso=jalaliToGregorian(v);if(iso)el.value=iso;}})}));
const ones=['صفر','یک','دو','سه','چهار','پنج','شش','هفت','هشت','نه','ده','یازده','دوازده','سیزده','چهارده','پانزده','شانزده','هفده','هجده','نوزده'];const tens=['','','بیست','سی','چهل','پنجاه','شصت','هفتاد','هشتاد','نود'];const hundreds=['','یکصد','دویست','سیصد','چهارصد','پانصد','ششصد','هفتصد','هشتصد','نهصد'];
function under1000(n){let a=[];if(n>=100){a.push(hundreds[Math.floor(n/100)]);n%=100}if(n>=20){a.push(tens[Math.floor(n/10)]);n%=10}if(n>0)a.push(ones[n]);return a.join(' و ')}function numberWords(n){if(!Number.isFinite(n)||n<0)return '';if(n===0)return 'صفر تومان';let parts=[],scales=['','هزار','میلیون','میلیارد','تریلیون'],i=0;while(n>0&&i<scales.length){let chunk=n%1000;if(chunk){let w=under1000(chunk);parts.unshift(w+(scales[i]?' '+scales[i]:''))}n=Math.floor(n/1000);i++}return parts.join(' و ')+' تومان'}
function formatAmount(el){const digits=enNum(el.value).replace(/[^0-9]/g,'');const n=digits?Number(digits):0;el.value=digits?faNum(n.toLocaleString('en-US')):'';const out=el.parentElement.querySelector('.amount-words');if(out)out.textContent=digits?numberWords(n):''}
document.querySelectorAll('.persian-input').forEach(el=>{el.addEventListener('input',()=>{if(el.classList.contains('amount-input'))formatAmount(el);else{const digits=enNum(el.value).replace(/[^0-9]/g,'');el.value=digits?faNum(Number(digits).toLocaleString('en-US')):''}});if(el.classList.contains('amount-input'))formatAmount(el)});
if(new URLSearchParams(location.search).get('openModal')==='1')document.getElementById('transactionModal')?.showModal();

<?php if($page==='dashboard'): ?>
if(document.getElementById('monthChart'))new Chart(document.getElementById('monthChart'),{type:'bar',data:{labels:['درآمد','هزینه'],datasets:[{label:'تومان',data:[<?= (int)$monthTotals['income']?>,<?= (int)$monthTotals['expense']?>],backgroundColor:['#10b981','#f43f5e'],borderRadius:10}]},options:{responsive:true,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{callback:v=>faNum(Number(v).toLocaleString())}}}}});
<?php endif; ?>
<?php if($page==='reports'): ?>
if(document.getElementById('reportPie'))new Chart(document.getElementById('reportPie'),{type:'doughnut',data:{labels:['درآمد','هزینه'],datasets:[{data:[<?= (int)$mt['income']?>,<?= (int)$mt['expense']?>],backgroundColor:['#10b981','#f43f5e'],borderWidth:0}]},options:{plugins:{legend:{position:'bottom',labels:{font:{family:'Vazirmatn'}}}}}});
<?php
$labels=[];$incomes=[];$expenses=[];
for($i=11;$i>=0;$i--){$ts=strtotime(date('Y-m-01').' -'.$i.' months');$m=date('Y-m',$ts);$labels[]=date('m/Y',$ts);$st=$pdo->prepare("SELECT kind,SUM(amount) total FROM transactions WHERE substr(transaction_date,1,7)=? GROUP BY kind");$st->execute([$m]);$vals=['income'=>0,'expense'=>0];foreach($st as $r)$vals[$r['kind']]=(int)$r['total'];$incomes[]=$vals['income'];$expenses[]=$vals['expense'];}
?>
if(document.getElementById('yearChart'))new Chart(document.getElementById('yearChart'),{type:'line',data:{labels:<?=json_encode(array_map('fa_digits',$labels),JSON_UNESCAPED_UNICODE)?>,datasets:[{label:'درآمد',data:<?=json_encode($incomes)?>,borderColor:'#10b981',tension:.3},{label:'هزینه',data:<?=json_encode($expenses)?>,borderColor:'#f43f5e',tension:.3}]},options:{responsive:true,plugins:{legend:{position:'bottom'}},scales:{y:{beginAtZero:true,ticks:{callback:v=>faNum(Number(v).toLocaleString())}}}}});
<?php endif; ?>
</script>
</body></html>
<?php
function jalaali_to_gregorian_php(string $date): ?string {
    // PHP-side conversion is intentionally avoided without a dedicated calendar extension.
    // Filters use JavaScript only for display; this function validates ISO fallback.
    return $date;
}
