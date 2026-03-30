<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';

// 通用的“某年某月有多少天”函数，避免使用 cal_days_in_month（部分环境未启用扩展）
function days_in_month_php($year, $month) {
    $year  = (int)$year;
    $month = (int)$month;
    if ($year < 1970 || $month < 1 || $month > 12) {
        return 30;
    }
    $dateStr = sprintf('%04d-%02d-01', $year, $month);
    return (int)date('t', strtotime($dateStr));
}

// ----------- 公共工具函数 -----------

function json_response($ok, $data = null, $msg = '') {
    echo json_encode([
        'ok'   => $ok,
        'msg'  => $msg,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function current_user() {
    return $_SESSION['user'] ?? null;
}

function require_login() {
    if (!isset($_SESSION['user'])) {
        json_response(false, null, '未登录，请先登录');
    }
}

function require_admin() {
    require_login();
    if ($_SESSION['user']['role'] !== 'admin') {
        json_response(false, null, '只有管理员可以执行此操作');
    }
}

// 读取输入（JSON 或表单）
function get_input() {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        $data = $_POST; // 兼容表单
    }
    return $data;
}

// ----------- 清远常驻相关辅助函数（按年份） -----------

/**
 * 读取某一年的常驻规则，返回：
 * [ employee_id => 'none'|'first'|'second'|'full', ... ]
 */
function qy_get_resident_map($year) {
    $year = (int)$year;
    $map = [];
    if ($year < 2000) return $map;
    try {
        $pdo = db();
        $stmt = $pdo->prepare("SELECT employee_id, rule FROM qy_resident_rules WHERE year = ?");
        $stmt->execute([$year]);
        while ($row = $stmt->fetch()) {
            $eid = (int)$row['employee_id'];
            $rule = $row['rule'] ?? 'none';
            $map[$eid] = $rule;
        }
    } catch (Exception $e) {
        // 忽略错误，保持空 map
    }
    return $map;
}

/**
 * 判断某个 rule 在指定月份是否视为“本月常驻清远（不计补贴）”
 * rule: none=非常驻, first=上半年(1-6), second=下半年(7-12), full=全年
 */
function qy_is_resident_for_month($rule, $month) {
    $month = (int)$month;
    if ($month < 1 || $month > 12) return false;
    if ($rule === 'full')   return true;
    if ($rule === 'first')  return ($month >= 1 && $month <= 6);
    if ($rule === 'second') return ($month >= 7 && $month <= 12);
    return false; // none 或空
}

$action = $_GET['action'] ?? '';

// ----------- 登录相关：me / login / logout -----------

// 获取当前登录用户
if ($action === 'me') {
    $user = current_user();
    if (!$user) {
        json_response(false, null, '未登录');
    }
    json_response(true, $user, '已登录');
}

// 登录
if ($action === 'login') {
    $input = get_input();
    if (!$input || (!isset($input['username']) && !isset($_GET['username']))) {
        // 兼容用 GET 方式测试
        $input = array_merge($_GET, $input ?? []);
    }

    $username = trim($input['username'] ?? '');
    $password = trim($input['password'] ?? '');
    // 记住登录：1/true/on 都视为开启
    $remember = $input['remember'] ?? ($input['remember_me'] ?? 0);
    $remember = ($remember === 1 || $remember === '1' || $remember === true || $remember === 'true' || $remember === 'on');

    if ($username === '' || $password === '') {
        json_response(false, null, '用户名或密码不能为空');
    }

    try {
        $pdo = db();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            json_response(false, null, '用户名或密码错误');
        }

        $_SESSION['user'] = [
            'id'       => $user['id'],
            'username' => $user['username'],
            'role'     => $user['role'],
        ];

        // 安全：更新 session id，避免固定会话攻击
        session_regenerate_id(true);

        // 如果勾选“记住我”，则把 PHPSESSID cookie 设为持久化（例如 30 天）
        if ($remember) {
            $ttl = 60 * 60 * 24 * 30;
            $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
            setcookie(session_name(), session_id(), [
                'expires'  => time() + $ttl,
                'path'     => '/',
                'secure'   => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            // 标记（可选）：便于前端展示或后续调试
            setcookie('remember_login', '1', [
                'expires'  => time() + $ttl,
                'path'     => '/',
                'secure'   => $secure,
                'httponly' => false,
                'samesite' => 'Lax',
            ]);
        }

        json_response(true, $_SESSION['user'], '登录成功');
    } catch (Exception $e) {
        json_response(false, null, '服务器错误：'.$e->getMessage());
    }
}

// 退出登录
if ($action === 'logout') {
    // 清理 session 数据
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'] ?? '/', $params['domain'] ?? '', $params['secure'] ?? false, $params['httponly'] ?? true);
    }
    // 清理“记住登录”标记
    setcookie('remember_login', '', time() - 42000, '/');
    session_destroy();
    json_response(true, null, '已退出登录');
}

// ----------- 员工管理：获取 / 添加 / 删除 -----------

if ($action === 'get_employees') {
    require_login();
    try {
        $pdo = db();
        $stmt = $pdo->query('SELECT id, name, is_active, created_at FROM employees ORDER BY id ASC');
        $rows = $stmt->fetchAll();
        json_response(true, $rows, '');
    } catch (Exception $e) {
        json_response(false, null, '获取员工失败：'.$e->getMessage());
    }
}

if ($action === 'add_employee') {
    require_admin();
    $input = get_input();
    $name = trim($input['name'] ?? '');
    if ($name === '') {
        json_response(false, null, '姓名不能为空');
    }

    $year = isset($input['year']) ? intval($input['year']) : 0;
    $month = isset($input['month']) ? intval($input['month']) : 0;

    try {
        $pdo = db();
        $stmt = $pdo->prepare('INSERT INTO employees (name, is_active) VALUES (?, 1)');
        $stmt->execute([$name]);
        $empId = (int)$pdo->lastInsertId();

        // 如果前端带了当前年月，则自动加入该月启用列表
        if ($year > 0 && $month > 0) {
            $stmt2 = $pdo->prepare('INSERT IGNORE INTO month_employees (employee_id, year, month) VALUES (?, ?, ?)');
            $stmt2->execute([$empId, $year, $month]);
        }

        json_response(true, ['id' => $empId, 'name' => $name], '添加成功');
    } catch (Exception $e) {
        json_response(false, null, '添加员工失败：'.$e->getMessage());
    }
}

if ($action === 'delete_employee') {
    require_admin();
    $input = get_input();
    $empId = intval($input['employee_id'] ?? 0);
    if ($empId <= 0) {
        json_response(false, null, 'employee_id 无效');
    }

    try {
        $pdo = db();
        $stmt = $pdo->prepare('DELETE FROM employees WHERE id = ?');
        $stmt->execute([$empId]);
        json_response(true, null, '已删除');
    } catch (Exception $e) {
        json_response(false, null, '删除员工失败：'.$e->getMessage());
    }
}

// ----------- 月度数据：获取当月人员 + 考勤 -----------

if ($action === 'get_month_data') {
    require_login();

    $year = intval($_GET['year'] ?? 0);
    $month = intval($_GET['month'] ?? 0);

    if ($year < 2000 || $month < 1 || $month > 12) {
        json_response(false, null, '年份或月份不合法');
    }

    try {
        $pdo = db();

        // 1. 所有在职员工
        $stmt = $pdo->query('SELECT id, name FROM employees WHERE is_active = 1 ORDER BY id ASC');
        $allEmp = $stmt->fetchAll();
        if (!$allEmp) {
            json_response(true, ['employees' => [], 'attendance' => []], '暂无员工');
        }

        // 2. 查该月启用的员工
        $stmt = $pdo->prepare('SELECT employee_id FROM month_employees WHERE year = ? AND month = ?');
        $stmt->execute([$year, $month]);
        $rows = $stmt->fetchAll();
        $activeEmpIds = [];
        foreach ($rows as $r) {
            $activeEmpIds[] = (int)$r['employee_id'];
        }

        // 如果该月还没有任何配置，则默认所有员工启用，并写入 month_employees
        if (count($activeEmpIds) === 0) {
            $activeEmpIds = array_map(fn($e) => (int)$e['id'], $allEmp);
            $stmtIns = $pdo->prepare('INSERT IGNORE INTO month_employees (employee_id, year, month) VALUES (?, ?, ?)');
            foreach ($activeEmpIds as $eid) {
                $stmtIns->execute([$eid, $year, $month]);
            }
        }

        // 过滤出当月启用员工列表
        $empMap = [];
        foreach ($allEmp as $emp) {
            $empMap[(int)$emp['id']] = $emp;
        }
        $employees = [];
        foreach ($activeEmpIds as $eid) {
            if (isset($empMap[$eid])) {
                $employees[] = $empMap[$eid];
            }
        }

        // 3. 查询当月考勤
        $lastDay = days_in_month_php($year, $month);
        $startDate = sprintf('%04d-%02d-01', $year, $month);
        $endDate   = sprintf('%04d-%02d-%02d', $year, $month, $lastDay);

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

        $attendance = [];
        while ($row = $stmt->fetch()) {
            $eid = (int)$row['employee_id'];
            $day = intval(substr($row['date'], 8, 2));
            if (!isset($attendance[$eid])) {
                $attendance[$eid] = [];
            }
            $attendance[$eid][$day] = $row['location'];
        }

        json_response(true, [
            'year'       => $year,
            'month'      => $month,
            'employees'  => $employees,
            'attendance' => $attendance,
        ], '');
    } catch (Exception $e) {
        json_response(false, null, '获取月度数据失败：'.$e->getMessage());
    }
}

// ----------- 保存某天考勤：save_attendance -----------

if ($action === 'save_attendance') {
    require_admin();
    $input = get_input();

    $empId   = intval($input['employee_id'] ?? 0);
    $year    = intval($input['year'] ?? 0);
    $month   = intval($input['month'] ?? 0);
    $day     = intval($input['day'] ?? 0);
    $loc     = $input['location'] ?? '';

    if ($empId <= 0 || $year < 2000 || $month < 1 || $month > 12 || $day < 1 || $day > 31) {
        json_response(false, null, '参数不合法');
    }

    $date = sprintf('%04d-%02d-%02d', $year, $month, $day);

    try {
        $pdo = db();

        // 确保该员工存在
        $stmt = $pdo->prepare('SELECT id FROM employees WHERE id = ? AND is_active = 1');
        $stmt->execute([$empId]);
        $emp = $stmt->fetch();
        if (!$emp) {
            json_response(false, null, '员工不存在或已停用');
        }

        if ($loc === '' || $loc === null) {
            // 清空该天记录
            $stmt = $pdo->prepare('DELETE FROM attendance WHERE employee_id = ? AND date = ?');
            $stmt->execute([$empId, $date]);
        } else {
            // 新增：公假（算出勤）、事假/病假/产假/丧假（不算出勤）
            $validLoc = ['广州', '清远', '缺勤', '公假', '事假', '病假', '产假', '丧假'];
            if (!in_array($loc, $validLoc, true)) {
                json_response(false, null, 'location 不合法，只能是：广州 / 清远 / 缺勤 / 公假 / 事假 / 病假 / 产假 / 丧假 或空');
            }

            // 插入或更新
            $sql = "INSERT INTO attendance (employee_id, date, location)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE location = VALUES(location)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$empId, $date, $loc]);
        }

        // 确保 month_employees 中有该员工
        $stmt2 = $pdo->prepare('INSERT IGNORE INTO month_employees (employee_id, year, month) VALUES (?, ?, ?)');
        $stmt2->execute([$empId, $year, $month]);

        json_response(true, null, '保存成功');
    } catch (Exception $e) {
        json_response(false, null, '保存考勤失败：'.$e->getMessage());
    }
}

// ----------- 本月移除某人：remove_employee_from_month -----------

if ($action === 'remove_employee_from_month') {
    require_admin();
    $input = get_input();

    $empId = intval($input['employee_id'] ?? 0);
    $year  = intval($input['year'] ?? 0);
    $month = intval($input['month'] ?? 0);

    if ($empId <= 0 || $year < 2000 || $month < 1 || $month > 12) {
        json_response(false, null, '参数不合法');
    }

    try {
        $pdo = db();

        // 从当月启用列表移除
        $stmt = $pdo->prepare('DELETE FROM month_employees WHERE employee_id = ? AND year = ? AND month = ?');
        $stmt->execute([$empId, $year, $month]);

        // 同时删除该员工当月的考勤记录
        $lastDay = days_in_month_php($year, $month);
        $startDate = sprintf('%04d-%02d-01', $year, $month);
        $endDate   = sprintf('%04d-%02d-%02d', $year, $month, $lastDay);

        $stmt2 = $pdo->prepare('DELETE FROM attendance WHERE employee_id = ? AND date BETWEEN ? AND ?');
        $stmt2->execute([$empId, $startDate, $endDate]);

        json_response(true, null, '已从当月移除该员工');
    } catch (Exception $e) {
        json_response(false, null, '移除失败：'.$e->getMessage());
    }
}

// ----------- 管理员账户管理：仅 admin 可用 -----------

if ($action === 'admin_list_users') {
    require_admin();
    try {
        $pdo = db();
        $stmt = $pdo->query("SELECT id, username, role, created_at FROM users ORDER BY id ASC");
        $rows = $stmt->fetchAll();
        json_response(true, $rows, '');
    } catch (Exception $e) {
        json_response(false, null, '获取用户列表失败：'.$e->getMessage());
    }
}

if ($action === 'admin_create_user') {
    require_admin();
    $input = get_input();
    $username = trim($input['username'] ?? '');
    $password = trim($input['password'] ?? '');
    $role     = trim($input['role'] ?? 'user');

    if ($username === '' || $password === '') {
        json_response(false, null, '用户名和密码均不能为空');
    }
    if (!in_array($role, ['admin', 'user'], true)) {
        json_response(false, null, '角色不合法');
    }

    try {
        $pdo = db();
        // 检查重名
        $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            json_response(false, null, '该用户名已存在');
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare('INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)');
        $stmt->execute([$username, $hash, $role]);
        $id = (int)$pdo->lastInsertId();

        json_response(true, [
            'id'       => $id,
            'username' => $username,
            'role'     => $role,
        ], '创建用户成功');
    } catch (Exception $e) {
        json_response(false, null, '创建用户失败：'.$e->getMessage());
    }
}

if ($action === 'admin_update_user') {
    require_admin();
    $input = get_input();
    $id       = (int)($input['id'] ?? 0);
    $username = trim($input['username'] ?? '');
    $role     = trim($input['role'] ?? '');

    if ($id <= 0) {
        json_response(false, null, 'id 无效');
    }
    if ($username === '' || $role === '') {
        json_response(false, null, '用户名和角色不能为空');
    }
    if (!in_array($role, ['admin', 'user'], true)) {
        json_response(false, null, '角色不合法');
    }

    try {
        $pdo = db();

        // 查询旧数据
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $user = $stmt->fetch();
        if (!$user) {
            json_response(false, null, '用户不存在');
        }

        // 检查重名（排除自己）
        $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? AND id <> ? LIMIT 1');
        $stmt->execute([$username, $id]);
        if ($stmt->fetch()) {
            json_response(false, null, '该用户名已被其他账号使用');
        }

        // 如果这次操作会导致“没有任何 admin”，需要阻止
        if ($user['role'] === 'admin' && $role !== 'admin') {
            $countStmt = $pdo->query("SELECT COUNT(*) AS c FROM users WHERE role='admin'");
            $c = (int)$countStmt->fetch()['c'];
            if ($c <= 1) {
                json_response(false, null, '系统至少需要保留一个管理员，不能将最后一个管理员改为普通用户');
            }
        }

        $stmt = $pdo->prepare('UPDATE users SET username = ?, role = ? WHERE id = ?');
        $stmt->execute([$username, $role, $id]);

        json_response(true, null, '更新成功');
    } catch (Exception $e) {
        json_response(false, null, '更新用户失败：'.$e->getMessage());
    }
}

if ($action === 'admin_reset_password') {
    require_admin();
    $input = get_input();
    $id          = (int)($input['id'] ?? 0);
    $newPassword = trim($input['new_password'] ?? '');

    if ($id <= 0) {
        json_response(false, null, 'id 无效');
    }
    if ($newPassword === '') {
        json_response(false, null, '新密码不能为空');
    }

    try {
        $pdo = db();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $user = $stmt->fetch();
        if (!$user) {
            json_response(false, null, '用户不存在');
        }

        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $stmt->execute([$hash, $id]);

        json_response(true, null, '密码重置成功，新密码：'.$newPassword);
    } catch (Exception $e) {
        json_response(false, null, '重置密码失败：'.$e->getMessage());
    }
}

if ($action === 'admin_delete_user') {
    require_admin();
    $input = get_input();
    $id = (int)($input['id'] ?? 0);

    if ($id <= 0) {
        json_response(false, null, 'id 无效');
    }

    try {
        $pdo = db();

        // 查出用户信息
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $user = $stmt->fetch();
        if (!$user) {
            json_response(false, null, '用户不存在');
        }

        // 不建议删除自己（当前登录管理员）
        if (isset($_SESSION['user']) && (int)$_SESSION['user']['id'] === $id) {
            json_response(false, null, '不允许删除当前登录账号');
        }

        // 如果删除的是管理员，要保证至少还剩一个管理员
        if ($user['role'] === 'admin') {
            $countStmt = $pdo->query("SELECT COUNT(*) AS c FROM users WHERE role='admin'");
            $c = (int)$countStmt->fetch()['c'];
            if ($c <= 1) {
                json_response(false, null, '系统至少需要保留一个管理员，不能删除最后一个管理员');
            }
        }

        $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
        $stmt->execute([$id]);

        json_response(true, null, '删除用户成功');
    } catch (Exception $e) {
        json_response(false, null, '删除用户失败：'.$e->getMessage());
    }
}

// ----------- 清远常驻规则管理（按年份） -----------

if ($action === 'get_qy_resident_rules') {
    // 任意已登录用户都可以查看，用于前端展示和计算
    require_login();
    $year = intval($_GET['year'] ?? 0);
    if ($year < 2000) {
        json_response(false, null, '年份不合法');
    }
    try {
        $pdo = db();
        $stmt = $pdo->prepare("SELECT employee_id, rule FROM qy_resident_rules WHERE year = ?");
        $stmt->execute([$year]);
        $rows = [];
        while ($row = $stmt->fetch()) {
            $rows[] = [
                'employee_id' => (int)$row['employee_id'],
                'rule'        => $row['rule'],
            ];
        }
        json_response(true, $rows, '');
    } catch (Exception $e) {
        json_response(false, null, '获取常驻清远设置失败：'.$e->getMessage());
    }
}

if ($action === 'set_qy_resident_rule') {
    // 仅管理员可修改规则
    require_admin();
    $input = get_input();
    $empId = intval($input['employee_id'] ?? 0);
    $year  = intval($input['year'] ?? 0);
    $rule  = trim($input['rule'] ?? 'none');

    if ($empId <= 0 || $year < 2000) {
        json_response(false, null, '参数不合法');
    }
    $validRules = ['none', 'first', 'second', 'full'];
    if (!in_array($rule, $validRules, true)) {
        json_response(false, null, 'rule 不合法');
    }

    try {
        $pdo = db();
        // 确认员工存在
        $stmt = $pdo->prepare("SELECT id FROM employees WHERE id = ?");
        $stmt->execute([$empId]);
        if (!$stmt->fetch()) {
            json_response(false, null, '员工不存在');
        }

        // upsert
        $sql = "INSERT INTO qy_resident_rules (employee_id, year, rule)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE rule = VALUES(rule)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$empId, $year, $rule]);

        json_response(true, null, '常驻清远设置已保存');
    } catch (Exception $e) {
        json_response(false, null, '保存常驻清远设置失败：'.$e->getMessage());
    }
}
require_once __DIR__ . '/contract_module.php';
// ----------- 未匹配到任何 action -----------

json_response(false, null, '未知操作：'.$action);
