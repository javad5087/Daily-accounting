<?php
require __DIR__ . '/config.php';
require_login();
$type=(string)($_GET['type']??'');
if($type==='sqlite'){
    // Checkpoint WAL before copying so the backup includes committed changes.
    $pdo->exec('PRAGMA wal_checkpoint(FULL)');
    $tmp=tempnam(sys_get_temp_dir(),'dbbackup_');
    if(!$tmp || !copy(DB_PATH,$tmp)){http_response_code(500);exit('تهیه نسخه پشتیبان انجام نشد.');}
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="accounting-backup-'.date('Y-m-d').'.sqlite"');
    header('Content-Length: '.filesize($tmp));
    readfile($tmp);unlink($tmp);exit;
}
if($type==='csv'){
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="transactions-'.date('Y-m-d').'.csv"');
    $out=fopen('php://output','w');fwrite($out,"\xEF\xBB\xBF");
    fputcsv($out,['شناسه','نوع','مبلغ تومان','حساب','بانک','دسته‌بندی','شرح','تاریخ میلادی','شماره پیگیری']);
    $rows=$pdo->query("SELECT t.*,a.name account_name,a.bank_name FROM transactions t JOIN accounts a ON a.id=t.account_id ORDER BY t.transaction_date DESC,t.id DESC");
    foreach($rows as $r) fputcsv($out,[$r['id'],$r['kind']==='income'?'درآمد':'هزینه',$r['amount'],$r['account_name'],$r['bank_name'],$r['category'],$r['description'],$r['transaction_date'],$r['reference_no']]);
    fclose($out);exit;
}
http_response_code(400);echo 'نوع خروجی نامعتبر است.';
