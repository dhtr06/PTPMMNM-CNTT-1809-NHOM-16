<?php
require 'config.php'; require_login();$id=max(0,(int)($_GET['run_id']??0));if(!$id){http_response_code(400);exit('Thiếu run_id hợp lệ.');}
$check=$pdo->prepare('SELECT id FROM scan_runs WHERE id=?');$check->execute([$id]);if(!$check->fetch()){http_response_code(404);exit('Không tìm thấy phiên quét.');}
$stmt=$pdo->prepare("SELECT f.normalized_url,f.base_target_url,f.fragment,f.resource_type,f.status_category,f.http_status,f.redirect_chain,f.error_detail,f.checked_at,COALESCE(GROUP_CONCAT(DISTINCT CONCAT(l.source_url,' (dòng ',l.line_number,')') SEPARATOR ' | '),'') AS source_pages,COALESCE(GROUP_CONCAT(DISTINCT l.tag_snippet SEPARATOR ' | '),'') AS source_html FROM findings f LEFT JOIN links l ON l.run_id=f.run_id AND l.normalized_url=f.base_target_url WHERE f.run_id=? GROUP BY f.id ORDER BY f.id");$stmt->execute([$id]);
header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="linkscope_report_'.$id.'_'.date('Ymd_His').'.csv"');header('X-Content-Type-Options: nosniff');
$out=fopen('php://output','w');fwrite($out,"\xEF\xBB\xBF");fputcsv($out,['URL','URL đích','Fragment','Loại','Phân loại','Mã HTTP','Chuỗi chuyển hướng','Chi tiết','Thời điểm kiểm tra','Trang nguồn / dòng','Thẻ HTML nguồn']);while($row=$stmt->fetch()){
 // Prefix formula-like user-controlled values to avoid CSV formula injection in spreadsheet apps.
 foreach($row as $key=>$value){if(is_string($value)&&preg_match('/^[=+@\-\t\r]/',$value))$row[$key]="'".$value;}
 fputcsv($out,$row);
}fclose($out);exit;
