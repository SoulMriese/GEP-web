<?php
/**
 * 财务标准模板导出（按月 / 按年打包 ZIP）
 * 依赖：PhpSpreadsheet（composer require phpoffice/phpspreadsheet）
 *
 * 用法：
 *  - 导出单月：export_finance_template.php?year=2026&month=1
 *  - 导出全年ZIP：export_finance_template.php?year=2026&month=all
 *
 * 说明：
 *  - 本脚本会以“标准模板.xlsx”为母版，填充【考勤表】工作表，最大程度保证格式一致。
 *  - 请把 标准模板.xlsx 放在与本脚本同目录。
 */

require_once __DIR__ . '/config.php';

// ---- 权限：必须登录且为管理员 ----
if (!isset($_SESSION['user'])) {
  http_response_code(401);
  echo "未登录，请先登录系统。";
  exit;
}
if (($_SESSION['user']['role'] ?? '') !== 'admin') {
  http_response_code(403);
  echo "仅管理员可以导出财务模板。";
  exit;
}

$year = intval($_GET['year'] ?? 0);
$monthRaw = $_GET['month'] ?? '';

if ($year < 2000 || $year > 2100) {
  http_response_code(400);
  echo "year 参数不合法";
  exit;
}

$isAll = ($monthRaw === 'all' || $monthRaw === '0' || $monthRaw === 0);
$month = $isAll ? 0 : intval($monthRaw);

if (!$isAll && ($month < 1 || $month > 12)) {
  http_response_code(400);
  echo "month 参数不合法（1-12 或 all）";
  exit;
}

// ---- 检查模板文件 ----
$templatePath = __DIR__ . '/标准模板.xlsx';
if (!file_exists($templatePath)) {
  http_response_code(500);
  echo "未找到模板文件：标准模板.xlsx（请上传到与本脚本同目录）";
  exit;
}

// ---- 引入 PhpSpreadsheet ----
$autoloadPath = __DIR__ . '/vendor/autoload.php';
if (!file_exists($autoloadPath)) {
  http_response_code(500);
  echo "缺少依赖：PhpSpreadsheet。\n\n请在站点根目录执行：\ncomposer require phpoffice/phpspreadsheet\n\n或在宝塔面板终端执行同样命令。";
  exit;
}
require_once $autoloadPath;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// 通用：某月天数
function days_in_month_php2($year, $month) {
  $dateStr = sprintf('%04d-%02d-01', $year, $month);
  return (int)date('t', strtotime($dateStr));
}

// 星期映射（模板用：一二三四五六日）
function weekday_cn($dateStr) {
  $w = (int)date('w', strtotime($dateStr)); // 0=Sun
  $map = ['日','一','二','三','四','五','六'];
  return $map[$w] ?? '';
}

// 读取当月启用员工 + 考勤（与 api.php 的 get_month_data 一致）
function get_month_data_from_db($year, $month) {
  $pdo = db();

  // 1) 当月启用员工（若未配置则默认全部在职）
  $stmt = $pdo->query('SELECT id, name FROM employees WHERE is_active = 1 ORDER BY id ASC');
  $allEmp = $stmt->fetchAll();
  if (!$allEmp) return ['employees' => [], 'attendance' => []];

  $stmt = $pdo->prepare('SELECT employee_id FROM month_employees WHERE year = ? AND month = ?');
  $stmt->execute([$year, $month]);
  $rows = $stmt->fetchAll();
  $activeEmpIds = [];
  foreach ($rows as $r) $activeEmpIds[] = (int)$r['employee_id'];

  if (count($activeEmpIds) === 0) {
    $activeEmpIds = array_map(fn($e) => (int)$e['id'], $allEmp);
    $stmtIns = $pdo->prepare('INSERT IGNORE INTO month_employees (employee_id, year, month) VALUES (?, ?, ?)');
    foreach ($activeEmpIds as $eid) $stmtIns->execute([$eid, $year, $month]);
  }

  // 组装员工列表（保持 id 顺序）
  $empMap = [];
  foreach ($allEmp as $emp) $empMap[(int)$emp['id']] = $emp;
  $employees = [];
  foreach ($activeEmpIds as $eid) {
    if (isset($empMap[$eid])) $employees[] = $empMap[$eid];
  }

  // 2) 考勤
  $lastDay = days_in_month_php2($year, $month);
  $startDate = sprintf('%04d-%02d-01', $year, $month);
  $endDate   = sprintf('%04d-%02d-%02d', $year, $month, $lastDay);

  $attendance = [];
  if (count($activeEmpIds) > 0) {
    $placeholders = implode(',', array_fill(0, count($activeEmpIds), '?'));
    $sql = "SELECT employee_id, date, location FROM attendance
            WHERE date BETWEEN ? AND ? AND employee_id IN ($placeholders)";
    $stmt = $pdo->prepare($sql);
    $params = array_merge([$startDate, $endDate], $activeEmpIds);
    $stmt->execute($params);
  } else {
    $stmt = $pdo->prepare("SELECT employee_id, date, location FROM attendance WHERE date BETWEEN ? AND ?");
    $stmt->execute([$startDate, $endDate]);
  }

  while ($row = $stmt->fetch()) {
    $eid = (int)$row['employee_id'];
    $day = (int)substr($row['date'], 8, 2);
    if (!isset($attendance[$eid])) $attendance[$eid] = [];
    $attendance[$eid][$day] = $row['location'];
  }

  return ['employees' => $employees, 'attendance' => $attendance];
}

// 找到“备注：出勤：...”那一行（用于定位员工区域结尾）
function find_legend_row($ws) {
  $maxRow = $ws->getHighestRow();
  for ($r = 1; $r <= $maxRow; $r++) {
    // 兼容较旧版本 PhpSpreadsheet：Worksheet 可能未提供 getCellByColumnAndRow
    $addr = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(1) . $r; // A列
    $v = (string)$ws->getCell($addr)->getValue();
    if ($v && mb_strpos($v, '备注：出勤') !== false) return $r;
  }
  // 模板默认
  return 43;
}

// 将数据填充到模板（考勤表 sheet）
function fill_attendance_sheet($spreadsheet, $year, $month, $employees, $attendance) {
  $ws = $spreadsheet->getSheetByName('考勤表');
  if (!$ws) {
    throw new Exception("模板中找不到工作表：考勤表");
  }

  $legendRow = find_legend_row($ws);
  $firstEmpRow = 5; // 模板员工从第5行开始（两行/人）

  // 若员工超过模板容量：插入行扩展（保持样式一致）
  $capacityPairs = (int)floor(($legendRow - $firstEmpRow) / 2);
  $needPairs = count($employees);

  if ($needPairs > $capacityPairs) {
    $extraPairs = $needPairs - $capacityPairs;
    $insertAt = $legendRow; // 在备注行之前插入
    $rowsToInsert = $extraPairs * 2;

    // 记录最后一个“员工样式行对”（插入前的最后两行）
    $styleSrcTop = $legendRow - 2;
    $styleSrcBottom = $legendRow - 1;

    $ws->insertNewRowBefore($insertAt, $rowsToInsert);

    // 插入后：把样式复制到新行（每次复制两行）
    for ($i = 0; $i < $rowsToInsert; $i++) {
      $destRow = $insertAt + $i;
      $srcRow  = ($i % 2 === 0) ? $styleSrcTop : $styleSrcBottom;

      // 复制行高
      $ws->getRowDimension($destRow)->setRowHeight($ws->getRowDimension($srcRow)->getRowHeight());

      // 复制样式（A~AK）
      $ws->duplicateStyle($ws->getStyle("A{$srcRow}:AK{$srcRow}"), "A{$destRow}:AK{$destRow}");

      // 清空内容
      for ($c = 1; $c <= 37; $c++) {
        $ws->setCellValue(Coordinate::stringFromColumnIndex($c) . $destRow, null);
      }
    }

    // 为新插入的每个“人行对”补上 A/B 合并（两行合并）
    $newLegendRow = $legendRow + $rowsToInsert;
    $pairsStart = $newLegendRow - $rowsToInsert; // 插入区域起始行
    for ($r = $pairsStart; $r < $newLegendRow; $r += 2) {
      $ws->mergeCells("A{$r}:A".($r+1));
      $ws->mergeCells("B{$r}:B".($r+1));
    }

    $legendRow = $newLegendRow;
    $capacityPairs = (int)floor(($legendRow - $firstEmpRow) / 2);
  }

  // 标题
  $ws->setCellValue('A1', "广东工程职业技术学院考勤表（{$year}年{$month}月）");

  // 部门/填报日期（沿用模板的样式，只替换文字）
  $lastDay = days_in_month_php2($year, $month);
  $ws->setCellValue('A2', "部门：（盖章） 总务后勤部（保卫部）                                                                           填报日期：{$month}月{$lastDay}日");

  // 星期行（第4行，F列起 1~31）
  for ($d = 1; $d <= 31; $d++) {
    $col = 5 + $d; // F=6
    if ($d <= $lastDay) {
      $dateStr = sprintf('%04d-%02d-%02d', $year, $month, $d);
      $ws->setCellValue(Coordinate::stringFromColumnIndex($col) . 4, weekday_cn($dateStr));
    } else {
      $ws->setCellValue(Coordinate::stringFromColumnIndex($col) . 4, null);
    }
  }

  // 状态映射（模板备注行定义）
  $mark = [
    '广州' => ['row' => 'gz', 'val' => '√', 'count' => true],
    '清远' => ['row' => 'qy', 'val' => '√', 'count' => true],
    '公假' => ['row' => 'gz', 'val' => '公', 'count' => true],  // 公假算出勤，放在“广州行”计入出勤天数
    '事假' => ['row' => 'gz', 'val' => '事', 'count' => false],
    '病假' => ['row' => 'gz', 'val' => '病', 'count' => false],
    '丧假' => ['row' => 'gz', 'val' => '丧', 'count' => false],
    '产假' => ['row' => 'gz', 'val' => '产', 'count' => false],
    '缺勤' => ['row' => 'gz', 'val' => '旷', 'count' => false], // 模板用“旷”表示旷工
  ];

  // 先清空员工区域内容（保留样式）
  $maxPairs = $capacityPairs;
  for ($i = 0; $i < $maxPairs; $i++) {
    $r1 = $firstEmpRow + 2*$i;
    $r2 = $r1 + 1;
    // A/B/C/D/AK 清空，日期格清空
    foreach ([1,2,3,4,37] as $c) {
      $ws->setCellValue(Coordinate::stringFromColumnIndex($c) . $r1, null);
      $ws->setCellValue(Coordinate::stringFromColumnIndex($c) . $r2, null);
    }
    // E列保持空
    for ($d = 1; $d <= 31; $d++) {
      $col = 5 + $d;
      $ws->setCellValue(Coordinate::stringFromColumnIndex($col) . $r1, null);
      $ws->setCellValue(Coordinate::stringFromColumnIndex($col) . $r2, null);
    }
  }

  // 填充员工
  for ($i = 0; $i < count($employees); $i++) {
    $emp = $employees[$i];
    $eid = (int)$emp['id'];
    $name = (string)$emp['name'];

    $rGz = $firstEmpRow + 2*$i;
    $rQy = $rGz + 1;

    // A/B 合并一般模板已有（如扩展插入我们已补），这里直接写上行即可
    $ws->setCellValue(Coordinate::stringFromColumnIndex(1) . $rGz, $i + 1);
    $ws->setCellValue(Coordinate::stringFromColumnIndex(2) . $rGz, $name);

    // 固定地点
    $ws->setCellValue(Coordinate::stringFromColumnIndex(4) . $rGz, '广州');
    $ws->setCellValue(Coordinate::stringFromColumnIndex(4) . $rQy, '清远');

    $gzCount = 0;
    $qyCount = 0;

    $daysMap = $attendance[$eid] ?? [];

    for ($d = 1; $d <= $lastDay; $d++) {
      $loc = $daysMap[$d] ?? '';
      if ($loc === '' || $loc === null) continue;

      $cfg = $mark[$loc] ?? null;
      if (!$cfg) continue;

      $col = 5 + $d;
      if ($cfg['row'] === 'qy') {
        $ws->setCellValue(Coordinate::stringFromColumnIndex($col) . $rQy, $cfg['val']);
        if ($cfg['count']) $qyCount++;
      } else {
        $ws->setCellValue(Coordinate::stringFromColumnIndex($col) . $rGz, $cfg['val']);
        if ($cfg['count']) $gzCount++;
      }
    }

    // 出勤天数（广州行=广州+公假；清远行=清远）
    $ws->setCellValue(Coordinate::stringFromColumnIndex(3) . $rGz, $gzCount);
    $ws->setCellValue(Coordinate::stringFromColumnIndex(3) . $rQy, $qyCount);
  }

  return $spreadsheet;
}

function output_xlsx($spreadsheet, $filename) {
  // 清理输出缓冲，避免文件损坏
  if (ob_get_length()) { ob_end_clean(); }
  header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
  header('Content-Disposition: attachment; filename="' . $filename . '"');
  header('Cache-Control: max-age=0');
  $writer = new Xlsx($spreadsheet);
  $writer->save('php://output');
  exit;
}

function output_zip($files, $zipName) {
  if (!class_exists('ZipArchive')) {
    http_response_code(500);
    echo "服务器未启用 ZipArchive 扩展，无法导出 ZIP。\n请在 PHP 扩展中启用 zip。";
    exit;
  }

  $tmpZip = tempnam(sys_get_temp_dir(), 'att_zip_');
  $zip = new ZipArchive();
  if ($zip->open($tmpZip, ZipArchive::OVERWRITE) !== true) {
    http_response_code(500);
    echo "无法创建 ZIP 文件";
    exit;
  }

  foreach ($files as $path => $nameInZip) {
    $zip->addFile($path, $nameInZip);
  }
  $zip->close();

  if (ob_get_length()) { ob_end_clean(); }
  header('Content-Type: application/zip');
  header('Content-Disposition: attachment; filename="' . $zipName . '"');
  header('Content-Length: ' . filesize($tmpZip));
  readfile($tmpZip);
  @unlink($tmpZip);
  exit;
}

try {
  if ($isAll) {
    // 全年：12个月各一份，打包 ZIP
    $tmpFiles = [];
    for ($m = 1; $m <= 12; $m++) {
      $data = get_month_data_from_db($year, $m);
      $spreadsheet = IOFactory::load($templatePath);
      $spreadsheet = fill_attendance_sheet($spreadsheet, $year, $m, $data['employees'], $data['attendance']);
      $tmpXlsx = tempnam(sys_get_temp_dir(), 'att_xlsx_') . '.xlsx';
      $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
      $writer->save($tmpXlsx);
      $tmpFiles[$tmpXlsx] = sprintf('%04d-%02d 考勤表.xlsx', $year, $m);
      $spreadsheet->disconnectWorksheets();
      unset($spreadsheet);
    }

    $zipName = sprintf('%04d 年全年考勤情况.zip', $year);
    output_zip($tmpFiles, $zipName);

    // 清理（通常不会到这行）
    foreach (array_keys($tmpFiles) as $p) @unlink($p);
    exit;
  }

  $data = get_month_data_from_db($year, $month);
  $spreadsheet = IOFactory::load($templatePath);
  $spreadsheet = fill_attendance_sheet($spreadsheet, $year, $month, $data['employees'], $data['attendance']);
  $filename = sprintf('%04d-%02d 考勤表.xlsx', $year, $month);
  output_xlsx($spreadsheet, $filename);

} catch (Exception $e) {
  http_response_code(500);
  echo "导出失败：" . $e->getMessage();
  exit;
}
