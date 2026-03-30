<?php
if (!isset($action)) {
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
}
if (!function_exists('contract_json_response')) {
    function contract_json_response($ok, $data = null, $msg = '') {
        if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>(bool)$ok,'msg'=>$msg,'data'=>$data], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
if (!function_exists('contract_get_json_input')) {
    function contract_get_json_input() {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
}
if (!function_exists('contract_me')) {
    function contract_me() {
        if (session_status() === PHP_SESSION_NONE) session_start();
        if (!empty($_SESSION['user']) && is_array($_SESSION['user'])) return $_SESSION['user'];
        if (!empty($_SESSION['username'])) return ['id'=>$_SESSION['user_id'] ?? 0, 'username'=>$_SESSION['username'], 'role'=>$_SESSION['role'] ?? 'user'];
        return null;
    }
}
if (!function_exists('contract_require_login')) {
    function contract_require_login() {
        $u = contract_me();
        if (!$u) contract_json_response(false, null, '请先登录');
        return $u;
    }
}
if (!function_exists('contract_require_admin')) {
    function contract_require_admin() {
        $u = contract_require_login();
        if (($u['role'] ?? '') !== 'admin') contract_json_response(false, null, '仅管理员可执行此操作');
        return $u;
    }
}
if (!function_exists('contract_client_ip')) {
    function contract_client_ip() {
        foreach (['HTTP_X_FORWARDED_FOR','HTTP_X_REAL_IP','REMOTE_ADDR'] as $k) if (!empty($_SERVER[$k])) return trim(explode(',', $_SERVER[$k])[0]);
        return '';
    }
}
if (!function_exists('contract_build_warning')) {
    function contract_build_warning($endDate) {
        if (!$endDate) return ['warning_level'=>'normal','days_to_expire'=>null,'warning_sort'=>99];
        $today = new DateTime(date('Y-m-d'));
        $end = DateTime::createFromFormat('Y-m-d', substr((string)$endDate, 0, 10));
        if (!$end) return ['warning_level'=>'normal','days_to_expire'=>null,'warning_sort'=>99];
        $days = (int)$today->diff($end)->format('%r%a');
        if ($days < 0) return ['warning_level'=>'expired','days_to_expire'=>$days,'warning_sort'=>1];
        if ($days <= 30) return ['warning_level'=>'thirty_days','days_to_expire'=>$days,'warning_sort'=>2];
        if ($days <= 90) return ['warning_level'=>'ninety_days','days_to_expire'=>$days,'warning_sort'=>3];
        if ($days <= 180) return ['warning_level'=>'half_year','days_to_expire'=>$days,'warning_sort'=>4];
        return ['warning_level'=>'normal','days_to_expire'=>$days,'warning_sort'=>5];
    }
}
if (!function_exists('contract_enrich_warning')) {
    function contract_enrich_warning(array $row) { return array_merge($row, contract_build_warning($row['end_date'] ?? null)); }
}
if (!function_exists('contract_log_action')) {
    function contract_log_action($contractId, $type, $desc, $userId = null) {
        $pdo = db();
        $stmt = $pdo->prepare("INSERT INTO contract_log (contract_id, action_type, action_desc, action_user_id, ip_address) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$contractId, $type, $desc, $userId, contract_client_ip()]);
    }
}
if ($action === 'me') {
    $u = contract_me();
    $u ? contract_json_response(true, $u, '') : contract_json_response(false, null, '未登录');
}
if ($action === 'get_contracts') {
    contract_require_login();
    $pdo = db();
    $keyword = trim($_GET['keyword'] ?? '');
    $vendorName = trim($_GET['vendor_name'] ?? '');
    $status = trim($_GET['status'] ?? '');
    $warningLevel = trim($_GET['warning_level'] ?? '');
    $dateFrom = trim($_GET['date_from'] ?? '');
    $dateTo = trim($_GET['date_to'] ?? '');
    $sql = "SELECT c.*, u.username AS manager_username FROM contract_info c LEFT JOIN users u ON u.id = c.manager_user_id WHERE 1=1";
    $params = [];
    if ($keyword !== '') { $sql .= " AND (c.contract_no LIKE ? OR c.contract_name LIKE ? OR c.vendor_name LIKE ?)"; $kw = "%$keyword%"; array_push($params, $kw, $kw, $kw); }
    if ($vendorName !== '') { $sql .= " AND c.vendor_name LIKE ?"; $params[] = "%$vendorName%"; }
    if ($status !== '') { $sql .= " AND c.contract_status = ?"; $params[] = $status; }
    if ($dateFrom !== '') { $sql .= " AND c.end_date >= ?"; $params[] = $dateFrom; }
    if ($dateTo !== '') { $sql .= " AND c.end_date <= ?"; $params[] = $dateTo; }
    $sql .= " ORDER BY c.end_date IS NULL, c.end_date ASC, c.id DESC";
    $stmt = $pdo->prepare($sql); $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $rows = array_map('contract_enrich_warning', $rows);
    if ($warningLevel !== '') $rows = array_values(array_filter($rows, function($row) use ($warningLevel){ return ($row['warning_level'] ?? 'normal') === $warningLevel; }));
    usort($rows, function($a, $b){ $sa = $a['warning_sort'] ?? 99; $sb = $b['warning_sort'] ?? 99; if ($sa === $sb) return strcmp($a['end_date'] ?? '9999-12-31', $b['end_date'] ?? '9999-12-31'); return $sa <=> $sb; });
    contract_json_response(true, $rows, '');
}
if ($action === 'get_contract_detail') {
    contract_require_login();
    $id = intval($_GET['id'] ?? 0); if ($id <= 0) contract_json_response(false, null, '参数错误');
    $pdo = db();
    $stmt = $pdo->prepare("SELECT c.*, u.username AS manager_username FROM contract_info c LEFT JOIN users u ON u.id = c.manager_user_id WHERE c.id = ?");
    $stmt->execute([$id]); $contract = $stmt->fetch(PDO::FETCH_ASSOC); if (!$contract) contract_json_response(false, null, '合同不存在');
    $contract = contract_enrich_warning($contract);
    $stmt = $pdo->prepare("SELECT a.*, u.username AS uploaded_by_name FROM contract_attachment a LEFT JOIN users u ON u.id = a.uploaded_by WHERE a.contract_id = ? ORDER BY a.id DESC");
    $stmt->execute([$id]); $attachments = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $stmt = $pdo->prepare("SELECT l.*, u.username AS action_user_name FROM contract_log l LEFT JOIN users u ON u.id = l.action_user_id WHERE l.contract_id = ? ORDER BY l.id DESC");
    $stmt->execute([$id]); $logs = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $changes = [];
    try { $stmt = $pdo->prepare("SELECT * FROM contract_change WHERE contract_id = ? ORDER BY id DESC"); $stmt->execute([$id]); $changes = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []; } catch (Exception $e) {}
    contract_json_response(true, ['contract'=>$contract,'attachments'=>$attachments,'logs'=>$logs,'changes'=>$changes], '');
}
if ($action === 'create_contract') {
    $user = contract_require_admin(); $data = contract_get_json_input();
    $contractNo = trim($data['contract_no'] ?? ''); $contractName = trim($data['contract_name'] ?? ''); $vendorName = trim($data['vendor_name'] ?? '');
    if ($contractNo === '' || $contractName === '' || $vendorName === '') contract_json_response(false, null, '合同编号、合同名称、乙方单位为必填项');
    $pdo = db(); $stmt = $pdo->prepare("SELECT id FROM contract_info WHERE contract_no = ?"); $stmt->execute([$contractNo]); if ($stmt->fetch()) contract_json_response(false, null, '合同编号已存在');
    $stmt = $pdo->prepare("INSERT INTO contract_info (contract_no, contract_name, contract_type, vendor_name, vendor_contact, vendor_phone, contract_amount, sign_date, start_date, end_date, payment_method, contract_status, manager_user_id, content_summary, remark, original_received, original_count, original_location, archive_status, archive_date, created_by, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$contractNo, $contractName, trim($data['contract_type'] ?? ''), $vendorName, trim($data['vendor_contact'] ?? ''), trim($data['vendor_phone'] ?? ''), ($data['contract_amount'] === '' || !isset($data['contract_amount'])) ? null : $data['contract_amount'], trim($data['sign_date'] ?? '') ?: null, trim($data['start_date'] ?? '') ?: null, trim($data['end_date'] ?? '') ?: null, trim($data['payment_method'] ?? ''), trim($data['contract_status'] ?? 'draft') ?: 'draft', ($data['manager_user_id'] === '' || !isset($data['manager_user_id'])) ? null : intval($data['manager_user_id']), trim($data['content_summary'] ?? ''), trim($data['remark'] ?? ''), intval($data['original_received'] ?? 0), intval($data['original_count'] ?? 0), trim($data['original_location'] ?? ''), intval($data['archive_status'] ?? 0), trim($data['archive_date'] ?? '') ?: null, $user['id'] ?? null, $user['id'] ?? null]);
    $id = intval($pdo->lastInsertId()); contract_log_action($id, 'create', '创建合同', $user['id'] ?? null); contract_json_response(true, ['id'=>$id], '保存成功');
}
if ($action === 'update_contract') {
    $user = contract_require_admin(); $data = contract_get_json_input(); $id = intval($data['id'] ?? 0); if ($id <= 0) contract_json_response(false, null, '参数错误');
    $pdo = db(); $stmt = $pdo->prepare("SELECT id FROM contract_info WHERE id = ?"); $stmt->execute([$id]); if (!$stmt->fetch()) contract_json_response(false, null, '合同不存在');
    $stmt = $pdo->prepare("UPDATE contract_info SET contract_no = ?, contract_name = ?, contract_type = ?, vendor_name = ?, vendor_contact = ?, vendor_phone = ?, contract_amount = ?, sign_date = ?, start_date = ?, end_date = ?, payment_method = ?, contract_status = ?, manager_user_id = ?, content_summary = ?, remark = ?, original_received = ?, original_count = ?, original_location = ?, archive_status = ?, archive_date = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
    $stmt->execute([trim($data['contract_no'] ?? ''), trim($data['contract_name'] ?? ''), trim($data['contract_type'] ?? ''), trim($data['vendor_name'] ?? ''), trim($data['vendor_contact'] ?? ''), trim($data['vendor_phone'] ?? ''), ($data['contract_amount'] === '' || !isset($data['contract_amount'])) ? null : $data['contract_amount'], trim($data['sign_date'] ?? '') ?: null, trim($data['start_date'] ?? '') ?: null, trim($data['end_date'] ?? '') ?: null, trim($data['payment_method'] ?? ''), trim($data['contract_status'] ?? 'draft') ?: 'draft', ($data['manager_user_id'] === '' || !isset($data['manager_user_id'])) ? null : intval($data['manager_user_id']), trim($data['content_summary'] ?? ''), trim($data['remark'] ?? ''), intval($data['original_received'] ?? 0), intval($data['original_count'] ?? 0), trim($data['original_location'] ?? ''), intval($data['archive_status'] ?? 0), trim($data['archive_date'] ?? '') ?: null, $user['id'] ?? null, $id]);
    contract_log_action($id, 'update', '更新合同', $user['id'] ?? null); contract_json_response(true, ['id'=>$id], '保存成功');
}
if ($action === 'delete_contract') {
    contract_require_admin(); $data = contract_get_json_input(); $id = intval($data['id'] ?? 0); if ($id <= 0) contract_json_response(false, null, '参数错误');
    $pdo = db(); $stmt = $pdo->prepare("SELECT * FROM contract_attachment WHERE contract_id = ?"); $stmt->execute([$id]); $files = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($files as $f) { $path = __DIR__ . '/' . ltrim($f['file_path'], '/'); if (is_file($path)) @unlink($path); }
    $pdo->prepare("DELETE FROM contract_attachment WHERE contract_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM contract_log WHERE contract_id = ?")->execute([$id]);
    try { $pdo->prepare("DELETE FROM contract_change WHERE contract_id = ?")->execute([$id]); } catch (Exception $e) {}
    try { $pdo->prepare("DELETE FROM contract_approval WHERE contract_id = ?")->execute([$id]); } catch (Exception $e) {}
    $pdo->prepare("DELETE FROM contract_info WHERE id = ?")->execute([$id]);
    contract_json_response(true, null, '删除成功');
}
if ($action === 'upload_contract_file') {
    $user = contract_require_admin(); $contractId = intval($_POST['contract_id'] ?? 0); $attachmentType = trim($_POST['attachment_type'] ?? 'contract_file'); $isOriginalScan = intval($_POST['is_original_scan'] ?? 0); $remark = trim($_POST['remark'] ?? '');
    if ($contractId <= 0) contract_json_response(false, null, '合同ID无效');
    if (empty($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) contract_json_response(false, null, '请先选择文件');
    $file = $_FILES['file']; if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) contract_json_response(false, null, '文件上传失败'); if (($file['size'] ?? 0) > 20 * 1024 * 1024) contract_json_response(false, null, '文件不能超过20MB');
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)); $allow = ['pdf','doc','docx','jpg','jpeg','png']; if (!in_array($ext, $allow, true)) contract_json_response(false, null, '仅支持 pdf/doc/docx/jpg/jpeg/png');
    $subDir = 'uploads/contracts/' . date('Y') . '/' . date('m'); $targetDir = __DIR__ . '/' . $subDir; if (!is_dir($targetDir) && !mkdir($targetDir, 0777, true) && !is_dir($targetDir)) contract_json_response(false, null, '创建上传目录失败');
    $storedName = 'contract_' . $contractId . '_' . time() . '_' . mt_rand(1000, 9999) . '.' . $ext; $targetPath = $targetDir . '/' . $storedName; if (!move_uploaded_file($file['tmp_name'], $targetPath)) contract_json_response(false, null, '保存文件失败');
    $relativePath = $subDir . '/' . $storedName; $pdo = db(); $stmt = $pdo->prepare("INSERT INTO contract_attachment (contract_id, attachment_type, file_name, stored_name, file_path, file_ext, file_size, mime_type, is_original_scan, version_no, uploaded_by, remark) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)");
    $mimeType = function_exists('mime_content_type') ? (mime_content_type($targetPath) ?: null) : null;
    $stmt->execute([$contractId, $attachmentType, $file['name'], $storedName, $relativePath, $ext, intval($file['size'] ?? 0), $mimeType, $isOriginalScan, $user['id'] ?? null, $remark]);
    contract_log_action($contractId, 'upload', '上传附件：' . $file['name'], $user['id'] ?? null); contract_json_response(true, null, '上传成功');
}
if ($action === 'delete_contract_file') {
    $user = contract_require_admin(); $data = contract_get_json_input(); $id = intval($data['id'] ?? 0); if ($id <= 0) contract_json_response(false, null, '参数错误');
    $pdo = db(); $stmt = $pdo->prepare("SELECT * FROM contract_attachment WHERE id = ?"); $stmt->execute([$id]); $row = $stmt->fetch(PDO::FETCH_ASSOC); if (!$row) contract_json_response(false, null, '附件不存在');
    $path = __DIR__ . '/' . ltrim($row['file_path'], '/'); if (is_file($path)) @unlink($path);
    $pdo->prepare("DELETE FROM contract_attachment WHERE id = ?")->execute([$id]); contract_log_action(intval($row['contract_id']), 'delete_file', '删除附件：' . ($row['file_name'] ?? ''), $user['id'] ?? null); contract_json_response(true, null, '删除成功');
}
?>
