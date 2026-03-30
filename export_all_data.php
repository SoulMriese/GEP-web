<?php
// 一键导出整套系统数据：employees / attendance / users（不含密码）
// 导出为一个 ZIP，内含 CSV 和 README.txt（动态适配实际字段）

require_once __DIR__ . '/config.php';

// 只有管理员可以导出
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['user']) || ($_SESSION['user']['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo "需要管理员登录且具备管理员权限才能导出数据。";
    exit;
}

try {
    $pdo = db();
} catch (Exception $e) {
    http_response_code(500);
    echo "数据库连接失败：" . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    exit;
}

// 生成临时目录
$baseTmp   = sys_get_temp_dir();
$exportDir = $baseTmp . '/attendance_export_' . uniqid();
if (!@mkdir($exportDir, 0777, true) && !is_dir($exportDir)) {
    http_response_code(500);
    echo "无法创建临时目录：{$exportDir}";
    exit;
}

// 写 CSV 工具：UTF-8 + BOM
function write_csv($filename, $header, $rows) {
    $fp = fopen($filename, 'w');
    if (!$fp) {
        throw new RuntimeException("无法写入文件：{$filename}");
    }
    // BOM
    fwrite($fp, "\xEF\xBB\xBF");
    if ($header) {
        fputcsv($fp, $header);
    }
    foreach ($rows as $row) {
        fputcsv($fp, $row);
    }
    fclose($fp);
}

// 通用导出表函数：自动根据实际字段生成表头，可排除部分列
function export_table_dynamic(PDO $pdo, string $table, string $filename, array $excludeCols = []) {
    global $exportDir;

    // 先查所有数据
    $stmt = $pdo->query("SELECT * FROM `{$table}`");
    $rowsAssoc = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 决定表头
    $header = [];
    if (!empty($rowsAssoc)) {
        $header = array_keys($rowsAssoc[0]);
    } else {
        // 没有数据时，用 SHOW COLUMNS 拿字段列表
        $colStmt = $pdo->query("SHOW COLUMNS FROM `{$table}`");
        $cols = $colStmt->fetchAll(PDO::FETCH_ASSOC);
        $header = array_column($cols, 'Field');
    }

    // 排除某些列
    if (!empty($excludeCols)) {
        $header = array_values(array_diff($header, $excludeCols));
    }

    // 将每行按 header 顺序输出
    $rows = [];
    foreach ($rowsAssoc as $row) {
        $line = [];
        foreach ($header as $h) {
            $line[] = $row[$h] ?? '';
        }
        $rows[] = $line;
    }

    write_csv(
        $exportDir . '/' . $filename,
        $header,
        $rows
    );
}

try {
    // 1）employees：我们知道至少有 id, name，就用动态导出
    export_table_dynamic($pdo, 'employees', 'employees.csv');

    // 2）users：用动态导出，但排除 password_hash
    export_table_dynamic($pdo, 'users', 'users.csv', ['password_hash']);

    // 3）attendance：完全动态导出（不再写死 year/month/day 字段名）
    export_table_dynamic($pdo, 'attendance', 'attendance.csv');

    // 4）README
    $readme = <<<TXT
广东工程职业技术学院 总务后勤部（保卫部）考勤系统
一键导出数据包说明（动态字段版）

包含文件：
1）employees.csv
   - 员工表的全部字段（具体字段以文件表头为准）

2）users.csv
   - 账户表的全部字段（除 password_hash）
   - 出于安全考虑，密码散列字段不会导出

3）attendance.csv
   - 考勤表的全部字段
   - 包含年/月/日或日期字段、出勤状态等（以表头为准）

编码：
- 所有 CSV 使用 UTF-8 编码并带有 BOM 头，便于在 Excel 中直接打开。

使用建议：
- 可在 Excel 中通过数据透视表对 attendance.csv 进行各类统计。
- 如需恢复数据，请通过数据库导入，而不是直接编辑 CSV 后导回。

TXT;

    file_put_contents($exportDir . '/README.txt', $readme);

    // 5）打包成 ZIP
    $zipFilename = $exportDir . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($zipFilename, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException("无法创建 ZIP 文件：{$zipFilename}");
    }

    $files = scandir($exportDir);
    foreach ($files as $f) {
        if ($f === '.' || $f === '..') continue;
        $filePath = $exportDir . '/' . $f;
        if (is_file($filePath)) {
            $zip->addFile($filePath, $f);
        }
    }
    $zip->close();

    // 输出给浏览器下载
    $downloadName = 'attendance_system_export_' . date('Ymd_His') . '.zip';
    header('Content-Type: application/zip');
    header('Content-Length: ' . filesize($zipFilename));
    header('Content-Disposition: attachment; filename="' . $downloadName . '"');

    readfile($zipFilename);

    // 清理临时文件
    @unlink($zipFilename);
    foreach ($files as $f) {
        if ($f === '.' || $f === '..') continue;
        @unlink($exportDir . '/' . $f);
    }
    @rmdir($exportDir);

} catch (Exception $e) {
    http_response_code(500);
    echo "导出失败：" . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    exit;
}
