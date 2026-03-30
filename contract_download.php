<?php
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('contract_find_attachment')) {
    function contract_find_attachment($id) {
        $pdo = db();
        $stmt = $pdo->prepare('SELECT * FROM contract_attachment WHERE id = ? LIMIT 1');
        $stmt->execute([(int)$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('contract_client_ip')) {
    function contract_client_ip() {
        $keys = ['HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];
        foreach ($keys as $k) {
            if (!empty($_SERVER[$k])) {
                return is_string($_SERVER[$k]) ? trim(explode(',', $_SERVER[$k])[0]) : '';
            }
        }
        return '';
    }
}

if (!function_exists('contract_log_action')) {
    function contract_log_action($contractId, $actionType, $actionDesc) {
        try {
            $pdo = db();
            $user = $_SESSION['user'] ?? null;
            $stmt = $pdo->prepare('INSERT INTO contract_log (contract_id, action_type, action_desc, action_user_id, ip_address) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([
                (int)$contractId,
                (string)$actionType,
                (string)$actionDesc,
                isset($user['id']) ? (int)$user['id'] : null,
                contract_client_ip(),
            ]);
        } catch (Exception $e) {
            // ignore
        }
    }
}

if (empty($_SESSION['user'])) {
    http_response_code(401);
    exit('请先登录系统');
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    exit('附件ID无效');
}

try {
    $row = contract_find_attachment($id);
    if (!$row) {
        http_response_code(404);
        exit('附件不存在');
    }

    $full = __DIR__ . '/' . ltrim(str_replace('\\', '/', $row['file_path']), '/');
    if (!is_file($full)) {
        http_response_code(404);
        exit('文件不存在');
    }

    contract_log_action($row['contract_id'], 'download', '下载附件：' . $row['file_name']);

    $downloadName = $row['file_name'] ?: basename($full);
    $mime = $row['mime_type'] ?: 'application/octet-stream';

    header('Content-Description: File Transfer');
    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . rawurlencode($downloadName) . '"');
    header('Content-Length: ' . filesize($full));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');

    readfile($full);
    exit;
} catch (Exception $e) {
    http_response_code(500);
    exit('下载失败：' . $e->getMessage());
}
