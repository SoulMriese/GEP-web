<?php
// 指定年份的“每人每月出勤 + 年度总结（含清远补贴）”为一个 CSV（Excel 可直接打开）

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

// 参数：year（必填）、first/next（可选，清远补贴首日/后续金额）
$year  = intval($_GET['year'] ?? 0);
$first = isset($_GET['first']) ? floatval($_GET['first']) : 110;
$next  = isset($_GET['next'])  ? floatval($_GET['next'])  : 35;

if (!$year) {
    http_response_code(400);
    echo "缺少 year 参数";
    exit;
}

try {
    $pdo = db();
} catch (Exception $e) {
    http_response_code(500);
    echo "数据库连接失败：" . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    exit;
}

// 工具：计算某个月内清远补贴
function calc_qy_allowance_from_days(array $daysMap, float $first, float $next): array {
    // $daysMap: day => location
    $qyDaysList = [];
    foreach ($daysMap as $day => $loc) {
        if ($loc === '清远') {
            $qyDaysList[] = (int)$day;
        }
    }
    sort($qyDaysList);
    $totalQyDays = count($qyDaysList);
    if ($totalQyDays === 0) {
        return ['qyDays' => 0, 'allowance' => 0.0];
    }

    $allowance = 0.0;
    $i = 0;
    $n = $totalQyDays;
    while ($i < $n) {
        $streakLen = 1;
        while ($i + 1 < $n && $qyDaysList[$i + 1] === $qyDaysList[$i] + 1) {
            $i++;
            $streakLen++;
        }
        $allowance += $first + max(0, $streakLen - 1) * $next;
        $i++;
    }
    return ['qyDays' => $totalQyDays, 'allowance' => $allowance];
}

// 1）拿员工名单
$stmt = $pdo->query("SELECT id, name FROM employees ORDER BY id ASC");
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (!$employees) {
    http_response_code(500);
    echo "员工表为空，无法导出。";
    exit;
}

// summary[empId] = [
//   'name' => ...,
//   'monthly' => [1=>['total'=>..,'gz'=>..,'qy'=>..], ...],
//   'totalYear' => ..,
//   'gzYear' => ..,
//   'qyYear' => ..,
//   'allowanceYear' => ..
// ]
$summary = [];
foreach ($employees as $e) {
    $summary[$e['id']] = [
        'name'          => $e['name'],
        'monthly'       => [],
        'totalYear'     => 0,
        'gzYear'        => 0,
        'qyYear'        => 0,
        'allowanceYear' => 0.0
    ];
}

// 2）逐月统计
for ($m = 1; $m <= 12; $m++) {
    // 提取该年该月的所有考勤
    $stmt = $pdo->prepare("SELECT employee_id, day, location FROM attendance WHERE year = ? AND month = ?");
    $stmt->execute([$year, $m]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        continue;
    }

    // daysMap[empId][day] = location
    $daysMap = [];
    foreach ($rows as $r) {
        $empId = (int)$r['employee_id'];
        $day   = (int)$r['day'];
        $loc   = $r['location'];
        if (!isset($daysMap[$empId])) {
            $daysMap[$empId] = [];
        }
        $daysMap[$empId][$day] = $loc;
    }

    foreach ($employees as $e) {
        $empId    = (int)$e['id'];
        $empDays  = $daysMap[$empId] ?? [];

        $total = 0; // 广州+清远+公假
        $gz    = 0;
        $qy    = 0;

        foreach ($empDays as $loc) {
            if ($loc === '广州' || $loc === '清远' || $loc === '公假') {
                $total++;
            }
            if ($loc === '广州') $gz++;
            if ($loc === '清远') $qy++;
        }

        // 清远补贴：
        $qyRes = calc_qy_allowance_from_days($empDays, $first, $next);

        // 累加到年度
        $summary[$empId]['totalYear']     += $total;
        $summary[$empId]['gzYear']        += $gz;
        $summary[$empId]['qyYear']        += $qy;
        $summary[$empId]['allowanceYear'] += $qyRes['allowance'];

        // 保存每月
        $summary[$empId]['monthly'][$m] = [
            'total' => $total,
            'gz'    => $gz,
            'qy'    => $qy
        ];
    }
}

// 3）生成 CSV 输出
$filename = "年度汇总_{$year}.csv";
header("Content-Type: text/csv; charset=UTF-8");
header("Content-Disposition: attachment; filename=\"{$filename}\"");
echo "\xEF\xBB\xBF"; // UTF-8 BOM，避免 Excel 乱码

// 表头
$head = ['姓名'];
for ($m = 1; $m <= 12; $m++) {
    $head[] = "{$m}月出勤";
    $head[] = "{$m}月广州";
    $head[] = "{$m}月清远";
}
$head[] = "全年出勤天数";
$head[] = "全年广州天数";
$head[] = "全年清远天数";
$head[] = "全年清远补贴（元）";
fputcsv(fopen('php://output', 'w'), []);
// 重新写输出逻辑
// 打开一个输出流
$output = fopen('php://output', 'w');
fputcsv($output, $head);

// 每人一行
foreach ($summary as $empId => $s) {
    $line = [];
    $line[] = $s['name'];
    for ($m = 1; $m <= 12; $m++) {
        $mon = $s['monthly'][$m] ?? ['total' => 0, 'gz' => 0, 'qy' => 0];
        $line[] = $mon['total'];
        $line[] = $mon['gz'];
        $line[] = $mon['qy'];
    }
    $line[] = $s['totalYear'];
    $line[] = $s['gzYear'];
    $line[] = $s['qyYear'];
    $line[] = number_format($s['allowanceYear'], 2, '.', '');
    fputcsv($output, $line);
}

fclose($output);
exit;
