<?php
// 数据库配置 - 按照宝塔中创建的数据库信息填写
define('DB_HOST', '127.0.0.1');       // 一般是 127.0.0.1
define('DB_NAME', 'attendance_db');   // TODO：数据库名
define('DB_USER', 'attendance_db'); // TODO：数据库用户名
define('DB_PASS', 'SoulMriese1123'); // TODO：密码

// 清远补贴规则（每段第一天 + 后续每天）
define('QY_FIRST_RATE', 110);
define('QY_NEXT_RATE', 35);

/**
 * 获取 PDO 数据库连接
 */
function db() {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4';
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    }
    return $pdo;
}

// 开启 Session
if (session_status() === PHP_SESSION_NONE) {
    // 让“记住登录”可用：把服务器端 Session 保留时间延长（例如 30 天）
    // 注意：这只决定服务端多久回收 session 数据；浏览器端是否持久化由登录时是否设置持久 Cookie 决定。
    $ttl = 60 * 60 * 24 * 30;
    ini_set('session.gc_maxlifetime', (string)$ttl);

    // 统一 cookie 安全属性（不影响现有逻辑）
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params([
        'lifetime' => 0,          // 默认：关闭浏览器即失效（是否“记住”由登录时单独设置）
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}
