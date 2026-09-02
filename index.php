<?php
// ============================================================
// GAMING WEBSITE BUILDER — index.php
// Single file · Single database · Full stack
// ============================================================

// ── Configuration ────────────────────────────────────────────
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'gaming_builder');
define('SITE_NAME', 'GameForge');
// Build a correct base URL including the current script directory so
// AJAX calls work when the project is hosted in a subdirectory.
$proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? '') == 443 ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
define('SITE_URL', $proto . '://' . $host . ($basePath === '/' ? '' : $basePath) . '/index.php');

// ── Session ──────────────────────────────────────────────────
session_start();

// ── Database Connection ──────────────────────────────────────
function db(): mysqli {
    static $conn = null;
    if ($conn === null) {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if ($conn->connect_error) {
            http_response_code(500);
            die(json_encode(['error' => 'Database connection failed: ' . $conn->connect_error]));
        }
        $conn->set_charset('utf8mb4');
    }
    return $conn;
}

// ── Helper Functions ─────────────────────────────────────────
function q(string $sql, array $params = [], string $types = ''): mysqli_result|bool {
    $db = db();
    if (empty($params)) {
        $result = $db->query($sql);
        if ($result === false) error_log("SQL Error: " . $db->error . " | SQL: $sql");
        return $result;
    }
    $stmt = $db->prepare($sql);
    if (!$stmt) { error_log("Prepare failed: " . $db->error . " | SQL: $sql"); return false; }
    if ($types === '') $types = str_repeat('s', count($params));
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();
    return $result !== false ? $result : true;
}

function insert(string $sql, array $params = [], string $types = ''): int {
    $db = db();
    $stmt = $db->prepare($sql);
    if (!$stmt) return 0;
    if (!empty($params)) {
        if ($types === '') $types = str_repeat('s', count($params));
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $id = $db->insert_id;
    $stmt->close();
    return $id;
}

function rows(string $sql, array $params = [], string $types = ''): array {
    $result = q($sql, $params, $types);
    if (!$result || $result === true) return [];
    return $result->fetch_all(MYSQLI_ASSOC);
}

function row(string $sql, array $params = [], string $types = ''): ?array {
    $result = q($sql, $params, $types);
    if (!$result || $result === true) return null;
    $row = $result->fetch_assoc();
    return $row ?: null;
}

function e(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function isLoggedIn(): bool { return !empty($_SESSION['user_id']) && !empty($_SESSION['user']) && is_array($_SESSION['user']); }
function currentUser(): ?array { return isLoggedIn() ? $_SESSION['user'] : null; }
function isAdmin(): bool { return (currentUser()['role'] ?? '') === 'admin'; }
function redirect(string $url): void { header("Location: $url"); exit; }
function csrf(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function verifyCsrf(): bool {
    return isset($_POST['_csrf']) && hash_equals($_SESSION['csrf'] ?? '', $_POST['_csrf']);
}
function sanitize(string $v): string { return trim(htmlspecialchars($v, ENT_QUOTES, 'UTF-8')); }
function jsonOut(array $data): void {
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// ── Auth Functions ───────────────────────────────────────────
function loginUser(string $email, string $password): array {
    $user = row("SELECT * FROM users WHERE email=? AND is_active=1", [$email]);
    if (!$user || !password_verify($password, $user['password'])) {
        return ['success' => false, 'message' => 'Invalid email or password.'];
    }
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user'] = $user;
    return ['success' => true, 'message' => 'Welcome back, ' . $user['username'] . '!'];
}

function registerUser(string $username, string $email, string $password, string $fullName): array {
    if (strlen($username) < 3) return ['success' => false, 'message' => 'Username must be at least 3 characters.'];
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return ['success' => false, 'message' => 'Invalid email address.'];
    if (strlen($password) < 6) return ['success' => false, 'message' => 'Password must be at least 6 characters.'];
    $exists = row("SELECT id FROM users WHERE email=? OR username=?", [$email, $username]);
    if ($exists) return ['success' => false, 'message' => 'Email or username already taken.'];
    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
    $id = insert("INSERT INTO users (username, email, password, full_name) VALUES (?,?,?,?)", [$username, $email, $hash, $fullName]);
    if (!$id) return ['success' => false, 'message' => 'Registration failed. Try again.'];
    insert("INSERT INTO user_settings (user_id) VALUES (?)", [$id], 'i');
    $user = row("SELECT * FROM users WHERE id=?", [$id], 'i');
    $_SESSION['user_id'] = $id;
    $_SESSION['user'] = $user;
    return ['success' => true, 'message' => 'Account created! Welcome, ' . $username . '!'];
}

// ── AJAX / POST Handler ───────────────────────────────────────
$isAjax = isset($_GET['ajax']) || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($isAjax && $action) {
  // Log incoming AJAX requests for debugging. Redact sensitive fields like passwords.
  try {
    $log = [];
    $log[] = "[" . date('Y-m-d H:i:s') . "] AJAX " . ($_SERVER['REMOTE_ADDR'] ?? 'cli') . " " . ($_SERVER['REQUEST_URI'] ?? '') . "\n";
    $log[] = "Action: " . $action . "\n";
    foreach ($_POST as $k => $v) {
      if (stripos($k, 'pass') !== false || $k === 'password' || $k === 'current_password' || $k === 'new_password') {
        $log[] = "  $k = [REDACTED]\n";
      } else {
        $val = is_string($v) ? (strlen($v) > 200 ? substr($v,0,200) . '...[truncated]' : $v) : json_encode($v);
        $log[] = "  $k = " . $val . "\n";
      }
    }
    $log[] = "---\n";
    @file_put_contents(__DIR__ . '/ajax_debug.log', implode('', $log), FILE_APPEND | LOCK_EX);
  } catch (Throwable $ex) { /* ignore logging failures */ }

  switch ($action) {
        case 'login':
            $r = loginUser($_POST['email'] ?? '', $_POST['password'] ?? '');
            jsonOut($r);
        case 'register':
            $r = registerUser($_POST['username'] ?? '', $_POST['email'] ?? '', $_POST['password'] ?? '', $_POST['full_name'] ?? '');
            jsonOut($r);
        case 'logout':
            session_destroy();
            jsonOut(['success' => true]);
        case 'update_profile':
            if (!isLoggedIn()) jsonOut(['success' => false, 'message' => 'Not logged in.']);
            $uid = $_SESSION['user_id'];
            $fullName = sanitize($_POST['full_name'] ?? '');
            $bio = sanitize($_POST['bio'] ?? '');
            $avatar = sanitize($_POST['avatar'] ?? '');
            q("UPDATE users SET full_name=?, bio=?, avatar=? WHERE id=?", [$fullName, $bio, $avatar, $uid], 'sssi');
            $user = row("SELECT * FROM users WHERE id=?", [$uid], 'i');
            $_SESSION['user'] = $user;
            jsonOut(['success' => true, 'message' => 'Profile updated successfully.']);
        case 'change_password':
            if (!isLoggedIn()) jsonOut(['success' => false, 'message' => 'Not logged in.']);
            $uid = $_SESSION['user_id'];
            $current = $_POST['current_password'] ?? '';
            $new = $_POST['new_password'] ?? '';
            $user = row("SELECT * FROM users WHERE id=?", [$uid], 'i');
            if (!password_verify($current, $user['password'])) jsonOut(['success' => false, 'message' => 'Current password incorrect.']);
            if (strlen($new) < 6) jsonOut(['success' => false, 'message' => 'New password must be 6+ characters.']);
            $hash = password_hash($new, PASSWORD_BCRYPT, ['cost' => 10]);
            q("UPDATE users SET password=? WHERE id=?", [$hash, $uid], 'si');
            jsonOut(['success' => true, 'message' => 'Password changed successfully.']);
        case 'update_settings':
            if (!isLoggedIn()) jsonOut(['success' => false, 'message' => 'Not logged in.']);
            $uid = $_SESSION['user_id'];
            $emailNotif = isset($_POST['email_notifications']) ? 1 : 0;
            $newsletter = isset($_POST['newsletter']) ? 1 : 0;
            $darkMode = isset($_POST['dark_mode']) ? 1 : 0;
            $lang = sanitize($_POST['language'] ?? 'en');
            $privacy = in_array($_POST['privacy'] ?? '', ['public','friends','private']) ? $_POST['privacy'] : 'public';
            q("INSERT INTO user_settings (user_id, email_notifications, newsletter, dark_mode, language, privacy) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE email_notifications=?, newsletter=?, dark_mode=?, language=?, privacy=?",
              [$uid, $emailNotif, $newsletter, $darkMode, $lang, $privacy, $emailNotif, $newsletter, $darkMode, $lang, $privacy], 'iiiiisiiiss');
            jsonOut(['success' => true, 'message' => 'Settings saved successfully.']);
        case 'add_game':
            if (!isAdmin()) jsonOut(['success' => false, 'message' => 'Unauthorized.']);
            $tid = (int)($_POST['template_id'] ?? 0);
            $title = sanitize($_POST['title'] ?? '');
            $genre = sanitize($_POST['genre'] ?? '');
            $platform = sanitize($_POST['platform'] ?? '');
            $rating = (float)($_POST['rating'] ?? 0);
            $year = (int)($_POST['release_year'] ?? date('Y'));
            $desc = sanitize($_POST['description'] ?? '');
            $featured = isset($_POST['is_featured']) ? 1 : 0;
            if (!$title) jsonOut(['success' => false, 'message' => 'Title required.']);
            $id = insert("INSERT INTO games (template_id, title, genre, platform, rating, release_year, description, is_featured) VALUES (?,?,?,?,?,?,?,?)",
                         [$tid, $title, $genre, $platform, $rating, $year, $desc, $featured], 'isssdiss');
            jsonOut(['success' => true, 'message' => 'Game added.', 'id' => $id]);
        case 'delete_game':
            if (!isAdmin()) jsonOut(['success' => false, 'message' => 'Unauthorized.']);
            $id = (int)($_POST['id'] ?? 0);
            q("DELETE FROM games WHERE id=?", [$id], 'i');
            jsonOut(['success' => true, 'message' => 'Game deleted.']);
        case 'edit_game':
            if (!isAdmin()) jsonOut(['success' => false, 'message' => 'Unauthorized.']);
            $id = (int)($_POST['id'] ?? 0);
            $title = sanitize($_POST['title'] ?? '');
            $genre = sanitize($_POST['genre'] ?? '');
            $platform = sanitize($_POST['platform'] ?? '');
            $rating = (float)($_POST['rating'] ?? 0);
            $desc = sanitize($_POST['description'] ?? '');
            $featured = isset($_POST['is_featured']) ? 1 : 0;
            q("UPDATE games SET title=?, genre=?, platform=?, rating=?, description=?, is_featured=? WHERE id=?",
              [$title, $genre, $platform, $rating, $desc, $featured, $id], 'sssdsii');
            jsonOut(['success' => true, 'message' => 'Game updated.']);
        case 'add_setup':
            if (!isLoggedIn()) jsonOut(['success' => false, 'message' => 'Login required.']);
            $tid = (int)($_POST['template_id'] ?? 0);
            $uid = $_SESSION['user_id'];
            $currentUser = currentUser();
            $setupName = sanitize($_POST['setup_name'] ?? '');
            $cpu = sanitize($_POST['cpu'] ?? '');
            $gpu = sanitize($_POST['gpu'] ?? '');
            $ram = sanitize($_POST['ram'] ?? '');
            $storage = sanitize($_POST['storage'] ?? '');
            $monitor = sanitize($_POST['monitor'] ?? '');
            $cost = sanitize($_POST['total_cost'] ?? '');
            $desc = sanitize($_POST['description'] ?? '');
            if (!$setupName) jsonOut(['success' => false, 'message' => 'Setup name required.']);
            $id = insert("INSERT INTO gaming_setups (template_id, user_id, setup_name, owner_name, cpu, gpu, ram, storage, monitor, total_cost, description) VALUES (?,?,?,?,?,?,?,?,?,?,?)",
                [$tid, $uid, $setupName, $currentUser['username'] ?? '', $cpu, $gpu, $ram, $storage, $monitor, $cost, $desc], 'iisssssssss');
            jsonOut(['success' => true, 'message' => 'Setup submitted!', 'id' => $id]);
        case 'like_setup':
            $id = (int)($_POST['id'] ?? 0);
            q("UPDATE gaming_setups SET likes_count = likes_count + 1 WHERE id=?", [$id], 'i');
            $s = row("SELECT likes_count FROM gaming_setups WHERE id=?", [$id], 'i');
            jsonOut(['success' => true, 'likes' => $s['likes_count'] ?? 0]);
        case 'add_comment':
            if (!isLoggedIn()) jsonOut(['success' => false, 'message' => 'Login required.']);
            $aid = (int)($_POST['article_id'] ?? 0);
            $uid = $_SESSION['user_id'];
            $currentUser = currentUser();
            $content = sanitize($_POST['content'] ?? '');
            if (strlen($content) < 3) jsonOut(['success' => false, 'message' => 'Comment too short.']);
            $id = insert("INSERT INTO comments (user_id, article_id, content) VALUES (?,?,?)", [$uid, $aid, $content], 'iis');
            jsonOut(['success' => true, 'message' => 'Comment added.', 'id' => $id, 'username' => $currentUser['username'] ?? '']);
        case 'contact':
            $name = sanitize($_POST['name'] ?? '');
            $email = sanitize($_POST['email'] ?? '');
            $subject = sanitize($_POST['subject'] ?? '');
            $message = sanitize($_POST['message'] ?? '');
            if (!$name || !$email || !$message) jsonOut(['success' => false, 'message' => 'All fields required.']);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) jsonOut(['success' => false, 'message' => 'Invalid email.']);
            insert("INSERT INTO contact_messages (name, email, subject, message) VALUES (?,?,?,?)", [$name, $email, $subject, $message]);
            jsonOut(['success' => true, 'message' => 'Message sent! We\'ll respond within 24 hours.']);
        case 'search_games':
            $tid = (int)($_GET['template_id'] ?? 0);
            $q2 = '%' . db()->real_escape_string($_GET['q'] ?? '') . '%';
            $genre = sanitize($_GET['genre'] ?? '');
            $sql = "SELECT * FROM games WHERE template_id=? AND (title LIKE ? OR genre LIKE ? OR description LIKE ?)";
            $params = [$tid, $q2, $q2, $q2];
            $types = 'isss';
            if ($genre) { $sql .= " AND genre=?"; $params[] = $genre; $types .= 's'; }
            $sql .= " ORDER BY is_featured DESC, rating DESC LIMIT 20";
            $games = rows($sql, $params, $types);
            jsonOut(['success' => true, 'games' => $games]);
        case 'get_game':
            $id = (int)($_GET['id'] ?? 0);
            $game = row("SELECT * FROM games WHERE id=?", [$id], 'i');
            jsonOut(['success' => true, 'game' => $game]);
        case 'delete_setup':
            if (!isLoggedIn()) jsonOut(['success' => false, 'message' => 'Unauthorized.']);
            $id = (int)($_POST['id'] ?? 0);
            $uid = $_SESSION['user_id'];
            $cond = isAdmin() ? "id=?" : "id=? AND user_id=?";
            $params = isAdmin() ? [$id] : [$id, $uid];
            $types = isAdmin() ? 'i' : 'ii';
            q("DELETE FROM gaming_setups WHERE $cond", $params, $types);
            jsonOut(['success' => true, 'message' => 'Setup deleted.']);
        default:
            jsonOut(['error' => 'Unknown action.']);
    }
}

// ── Logout (non-AJAX) ─────────────────────────────────────────
if (isset($_GET['logout'])) {
    session_destroy();
    redirect(SITE_URL);
}

// ── Routing ────────────────────────────────────────────────────
$page = $_GET['page'] ?? 'home';
$templateId = (int)($_GET['id'] ?? 0);
$section = $_GET['section'] ?? 'home';
$articleId = (int)($_GET['article'] ?? 0);

// Template data
$templates = rows("SELECT * FROM templates WHERE is_active=1 ORDER BY sort_order ASC");
$templateData = $templateId ? row("SELECT * FROM templates WHERE id=?", [$templateId], 'i') : null;

// ─────────────────────────────────────────────────────────────
// Template theme configs
// ─────────────────────────────────────────────────────────────
$themes = [
    1 => [
        'name' => 'CyberNeon',
        'primary' => '#A855F7',
        'secondary' => '#06B6D4',
        'bg' => '#050510',
        'bg2' => '#0D0D1E',
        'bg3' => '#13132B',
        'text' => '#E2E8F0',
        'muted' => '#94A3B8',
        'border' => '#1E1E3F',
        'font' => "'Orbitron', 'Rajdhani', sans-serif",
        'body_font' => "'Rajdhani', sans-serif",
        'glow' => '0 0 20px rgba(168,85,247,0.4)',
        'gradient' => 'linear-gradient(135deg, #A855F7, #06B6D4)',
        'nav_gradient' => 'linear-gradient(90deg, #050510, #0D0D1E)',
        'hero_gradient' => 'linear-gradient(135deg, rgba(168,85,247,0.15) 0%, rgba(6,182,212,0.1) 100%)',
        'badge' => '#A855F7',
    ],
    2 => [
        'name' => 'BladeArena',
        'primary' => '#EF4444',
        'secondary' => '#F97316',
        'bg' => '#0A0000',
        'bg2' => '#150505',
        'bg3' => '#1C0808',
        'text' => '#F5F5F5',
        'muted' => '#9CA3AF',
        'border' => '#3F1010',
        'font' => "'Bebas Neue', 'Impact', sans-serif",
        'body_font' => "'Inter', sans-serif",
        'glow' => '0 0 20px rgba(239,68,68,0.4)',
        'gradient' => 'linear-gradient(135deg, #EF4444, #F97316)',
        'nav_gradient' => 'linear-gradient(90deg, #0A0000, #150505)',
        'hero_gradient' => 'linear-gradient(135deg, rgba(239,68,68,0.15) 0%, rgba(249,115,22,0.1) 100%)',
        'badge' => '#EF4444',
    ],
    3 => [
        'name' => 'MythQuest',
        'primary' => '#F59E0B',
        'secondary' => '#10B981',
        'bg' => '#060D06',
        'bg2' => '#0D1A0D',
        'bg3' => '#162416',
        'text' => '#F0E8D0',
        'muted' => '#A0906A',
        'border' => '#2A3D2A',
        'font' => "'Cinzel', 'Palatino', serif",
        'body_font' => "'Lora', serif",
        'glow' => '0 0 20px rgba(245,158,11,0.4)',
        'gradient' => 'linear-gradient(135deg, #F59E0B, #10B981)',
        'nav_gradient' => 'linear-gradient(90deg, #060D06, #0D1A0D)',
        'hero_gradient' => 'linear-gradient(135deg, rgba(245,158,11,0.15) 0%, rgba(16,185,129,0.1) 100%)',
        'badge' => '#F59E0B',
    ],
    4 => [
        'name' => 'PixelVault',
        'primary' => '#10B981',
        'secondary' => '#F59E0B',
        'bg' => '#0A0A14',
        'bg2' => '#111122',
        'bg3' => '#1A1A30',
        'text' => '#ECFDF5',
        'muted' => '#6B7280',
        'border' => '#1F2937',
        'font' => "'Press Start 2P', monospace",
        'body_font' => "'VT323', monospace",
        'glow' => '0 0 15px rgba(16,185,129,0.5)',
        'gradient' => 'linear-gradient(135deg, #10B981, #F59E0B)',
        'nav_gradient' => 'linear-gradient(90deg, #0A0A14, #111122)',
        'hero_gradient' => 'linear-gradient(135deg, rgba(16,185,129,0.15) 0%, rgba(245,158,11,0.1) 100%)',
        'badge' => '#10B981',
    ],
];

// ─────────────────────────────────────────────────────────────
// Output Starts
// ─────────────────────────────────────────────────────────────
$isTemplate = ($page === 'template' && $templateId > 0 && $templateData);
$theme = $isTemplate ? ($themes[$templateId] ?? $themes[1]) : null;

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $isTemplate ? e($templateData['name']) . ' — Gaming Template' : SITE_NAME . ' — Build Your Gaming Empire' ?></title>
<meta name="description" content="<?= SITE_NAME ?> — Professional gaming website templates for every style. Launch your gaming community in minutes.">

<!-- Fonts -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;600;700;900&family=Rajdhani:wght@300;400;500;600;700&family=Bebas+Neue&family=Cinzel:wght@400;600;700&family=Lora:ital,wght@0,400;0,600;1,400&family=Press+Start+2P&family=VT323&family=Inter:wght@300;400;500;600;700&family=Outfit:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">

<!-- Icons -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

<style>
/* ══════════════════════════════════════════════════════
   GLOBAL RESET & BASE
══════════════════════════════════════════════════════ */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root {
  --main-bg: #070714;
  --main-bg2: #0F0F24;
  --main-bg3: #161630;
  --main-primary: #7C3AED;
  --main-accent: #06B6D4;
  --main-gold: #F59E0B;
  --main-text: #E2E8F0;
  --main-muted: #64748B;
  --main-border: #1E1E40;
  --main-card: #0F0F24;
  --font-head: 'Outfit', sans-serif;
  --font-body: 'Inter', sans-serif;
  --radius: 12px;
  --radius-lg: 20px;
  --shadow: 0 4px 24px rgba(0,0,0,0.5);
  --shadow-lg: 0 8px 48px rgba(0,0,0,0.7);
  --transition: 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}
html { scroll-behavior: smooth; }
body {
  font-family: var(--font-body);
  background: var(--main-bg);
  color: var(--main-text);
  min-height: 100vh;
  line-height: 1.6;
  overflow-x: hidden;
}
a { color: inherit; text-decoration: none; }
img { max-width: 100%; display: block; }
ul { list-style: none; }
input, textarea, select, button { font-family: inherit; }

/* ══════════════════════════════════════════════════════
   SCROLLBAR
══════════════════════════════════════════════════════ */
::-webkit-scrollbar { width: 6px; height: 6px; }
::-webkit-scrollbar-track { background: var(--main-bg); }
::-webkit-scrollbar-thumb { background: var(--main-primary); border-radius: 3px; }

/* ══════════════════════════════════════════════════════
   MAIN SITE — NAVBAR
══════════════════════════════════════════════════════ */
.main-nav {
  position: fixed; top: 0; left: 0; right: 0; z-index: 1000;
  background: rgba(7,7,20,0.9);
  backdrop-filter: blur(20px);
  -webkit-backdrop-filter: blur(20px);
  border-bottom: 1px solid var(--main-border);
  height: 70px;
  display: flex; align-items: center;
}
.main-nav .nav-inner {
  max-width: 1400px; width: 100%; margin: 0 auto;
  padding: 0 24px;
  display: flex; align-items: center; justify-content: space-between; gap: 24px;
}
.main-logo {
  font-family: var(--font-head); font-size: 1.6rem; font-weight: 800;
  background: linear-gradient(135deg, #7C3AED, #06B6D4);
  -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;
  letter-spacing: -0.5px;
}
.main-logo span { color: #F59E0B; -webkit-text-fill-color: #F59E0B; }
.nav-links { display: flex; align-items: center; gap: 8px; }
.nav-links a {
  padding: 8px 16px; border-radius: 8px;
  font-weight: 500; font-size: 0.9rem; color: var(--main-muted);
  transition: all var(--transition);
}
.nav-links a:hover, .nav-links a.active { color: var(--main-text); background: var(--main-bg3); }
.nav-actions { display: flex; align-items: center; gap: 12px; }
.btn { display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px; border-radius: var(--radius); font-weight: 600; font-size: 0.9rem; cursor: pointer; border: none; transition: all var(--transition); text-align: center; }
.btn-primary { background: linear-gradient(135deg, #7C3AED, #5B21B6); color: #fff; }
.btn-primary:hover { transform: translateY(-1px); box-shadow: 0 8px 24px rgba(124,58,237,0.5); }
.btn-outline { background: transparent; border: 1px solid var(--main-border); color: var(--main-text); }
.btn-outline:hover { background: var(--main-bg3); border-color: var(--main-primary); }
.btn-sm { padding: 7px 14px; font-size: 0.8rem; }
.btn-danger { background: #EF4444; color: #fff; }
.btn-danger:hover { background: #DC2626; }
.btn-success { background: #10B981; color: #fff; }
.btn-success:hover { background: #059669; }
.nav-user { display: flex; align-items: center; gap: 10px; cursor: pointer; position: relative; }
.nav-avatar {
  width: 38px; height: 38px; border-radius: 50%;
  background: linear-gradient(135deg, #7C3AED, #06B6D4);
  display: flex; align-items: center; justify-content: center;
  font-weight: 700; font-size: 0.9rem; color: #fff;
  border: 2px solid var(--main-primary);
}
.nav-avatar img { width: 100%; height: 100%; border-radius: 50%; object-fit: cover; }
.dropdown {
  position: absolute; top: calc(100% + 12px); right: 0;
  background: var(--main-bg2); border: 1px solid var(--main-border);
  border-radius: var(--radius); min-width: 200px;
  box-shadow: var(--shadow-lg); display: none; overflow: hidden;
}
.dropdown.open { display: block; animation: dropIn 0.2s ease; }
@keyframes dropIn { from { opacity:0; transform: translateY(-8px); } to { opacity:1; transform: none; } }
.dropdown a, .dropdown button {
  display: flex; align-items: center; gap: 10px;
  padding: 12px 16px; width: 100%;
  font-size: 0.9rem; color: var(--main-muted);
  background: none; border: none; cursor: pointer;
  transition: all var(--transition); text-align: left;
}
.dropdown a:hover, .dropdown button:hover { background: var(--main-bg3); color: var(--main-text); }
.dropdown-divider { height: 1px; background: var(--main-border); margin: 4px 0; }
.hamburger { display: none; flex-direction: column; gap: 5px; cursor: pointer; padding: 8px; }
.hamburger span { display: block; width: 24px; height: 2px; background: var(--main-text); border-radius: 2px; transition: all var(--transition); }

/* ══════════════════════════════════════════════════════
   HERO SECTION
══════════════════════════════════════════════════════ */
.hero {
  min-height: 100vh;
  display: flex; align-items: center;
  position: relative; overflow: hidden;
  padding: 120px 24px 80px;
}
.hero-bg {
  position: absolute; inset: 0; z-index: 0;
  background:
    radial-gradient(ellipse 80% 50% at 70% 40%, rgba(124,58,237,0.15) 0%, transparent 70%),
    radial-gradient(ellipse 60% 40% at 20% 70%, rgba(6,182,212,0.1) 0%, transparent 60%),
    var(--main-bg);
}
.hero-grid {
  position: absolute; inset: 0;
  background-image: linear-gradient(rgba(124,58,237,0.05) 1px, transparent 1px), linear-gradient(90deg, rgba(124,58,237,0.05) 1px, transparent 1px);
  background-size: 60px 60px;
}
.hero-inner {
  max-width: 1400px; width: 100%; margin: 0 auto;
  display: grid; grid-template-columns: 1fr 1fr; gap: 80px; align-items: center;
  position: relative; z-index: 1;
}
.hero-badge {
  display: inline-flex; align-items: center; gap: 8px;
  background: rgba(124,58,237,0.15); border: 1px solid rgba(124,58,237,0.3);
  padding: 6px 16px; border-radius: 100px; font-size: 0.8rem; font-weight: 600;
  color: #A78BFA; margin-bottom: 24px;
}
.hero-badge i { animation: pulse 2s ease-in-out infinite; }
@keyframes pulse { 0%,100%{opacity:1} 50%{opacity:0.5} }
.hero-title {
  font-family: var(--font-head); font-size: clamp(2.5rem, 5vw, 4.5rem);
  font-weight: 900; line-height: 1.05; letter-spacing: -2px;
  margin-bottom: 24px;
}
.hero-title .gradient-text {
  background: linear-gradient(135deg, #A78BFA, #67E8F9);
  -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;
}
.hero-sub {
  font-size: 1.15rem; color: var(--main-muted); max-width: 480px;
  line-height: 1.7; margin-bottom: 40px;
}
.hero-actions { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; }
.btn-hero {
  padding: 14px 32px; font-size: 1rem; border-radius: 12px;
  background: linear-gradient(135deg, #7C3AED, #5B21B6);
  color: #fff; font-weight: 700; border: none; cursor: pointer;
  box-shadow: 0 8px 30px rgba(124,58,237,0.4);
  transition: all var(--transition);
}
.btn-hero:hover { transform: translateY(-2px); box-shadow: 0 12px 40px rgba(124,58,237,0.6); }
.btn-hero-outline {
  padding: 13px 32px; font-size: 1rem; border-radius: 12px;
  background: transparent; border: 2px solid var(--main-border);
  color: var(--main-text); font-weight: 600; cursor: pointer;
  transition: all var(--transition);
}
.btn-hero-outline:hover { border-color: var(--main-primary); background: rgba(124,58,237,0.08); }
.hero-stats {
  display: flex; gap: 32px; margin-top: 48px;
  padding-top: 32px; border-top: 1px solid var(--main-border);
}
.hero-stat-num {
  font-family: var(--font-head); font-size: 2rem; font-weight: 800;
  background: linear-gradient(135deg, #A78BFA, #67E8F9);
  -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;
}
.hero-stat-label { font-size: 0.85rem; color: var(--main-muted); margin-top: 2px; }

/* Hero right side — template preview cards */
.hero-preview {
  display: grid; grid-template-columns: 1fr 1fr; gap: 12px;
  transform: perspective(1000px) rotateY(-10deg) rotateX(5deg);
  transition: transform 0.6s ease;
}
.hero-preview:hover { transform: perspective(1000px) rotateY(-3deg) rotateX(2deg); }
.preview-mini {
  background: var(--main-bg2); border: 1px solid var(--main-border);
  border-radius: 12px; overflow: hidden; cursor: pointer;
  transition: all var(--transition); position: relative;
}
.preview-mini:hover { transform: translateY(-4px); border-color: var(--main-primary); }
.preview-mini-header { height: 8px; }
.preview-mini-body { padding: 12px; }
.preview-mini-title { font-size: 0.75rem; font-weight: 700; margin-bottom: 4px; }
.preview-mini-sub { font-size: 0.65rem; color: var(--main-muted); }
.preview-mini-dots { display: flex; gap: 4px; margin-bottom: 8px; }
.preview-mini-dot { width: 6px; height: 6px; border-radius: 50%; }
.preview-mini-bars { display: flex; flex-direction: column; gap: 4px; margin-top: 6px; }
.preview-mini-bar { height: 4px; border-radius: 2px; opacity: 0.5; }
.preview-mini-tag {
  position: absolute; top: 8px; right: 8px;
  font-size: 0.6rem; font-weight: 700; padding: 2px 6px;
  border-radius: 4px; text-transform: uppercase; letter-spacing: 0.5px;
}

/* ══════════════════════════════════════════════════════
   SECTION STYLES
══════════════════════════════════════════════════════ */
.section { padding: 100px 24px; }
.section-inner { max-width: 1400px; margin: 0 auto; }
.section-header { text-align: center; margin-bottom: 60px; }
.section-tag {
  display: inline-block;
  font-size: 0.75rem; font-weight: 700; letter-spacing: 2px;
  text-transform: uppercase; color: var(--main-primary);
  margin-bottom: 12px;
}
.section-title {
  font-family: var(--font-head); font-size: clamp(1.8rem, 3.5vw, 3rem);
  font-weight: 800; letter-spacing: -1px; margin-bottom: 16px;
}
.section-sub { font-size: 1.05rem; color: var(--main-muted); max-width: 600px; margin: 0 auto; }

/* ══════════════════════════════════════════════════════
   TEMPLATE CARDS (Main Page)
══════════════════════════════════════════════════════ */
.templates-grid {
  display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
  gap: 28px;
}
.template-card {
  background: var(--main-bg2); border: 1px solid var(--main-border);
  border-radius: 20px; overflow: hidden; cursor: pointer;
  transition: all var(--transition); position: relative;
  group: true;
}
.template-card:hover {
  transform: translateY(-6px);
  box-shadow: 0 20px 60px rgba(0,0,0,0.5);
}
.template-card-preview {
  height: 200px; position: relative; overflow: hidden;
  display: flex; align-items: flex-end;
}
.template-card-screen {
  position: absolute; inset: 0;
  display: flex; flex-direction: column;
}
.tc-nav { height: 28px; display: flex; align-items: center; padding: 0 12px; gap: 8px; }
.tc-nav-dot { width: 6px; height: 6px; border-radius: 50%; }
.tc-nav-text { flex: 1; height: 3px; border-radius: 2px; opacity: 0.3; margin-left: 8px; }
.tc-hero { flex: 1; display: flex; align-items: center; padding: 12px; gap: 12px; }
.tc-hero-text { flex: 1; }
.tc-hero-h { height: 10px; border-radius: 3px; margin-bottom: 6px; width: 70%; }
.tc-hero-h2 { height: 6px; border-radius: 2px; opacity: 0.4; width: 50%; }
.tc-hero-btn { height: 18px; width: 50px; border-radius: 5px; opacity: 0.8; }
.tc-cards { display: grid; grid-template-columns: repeat(3,1fr); gap: 6px; padding: 0 12px 12px; }
.tc-card { height: 32px; border-radius: 4px; opacity: 0.5; }
.template-card-info { padding: 24px; }
.template-card-badge {
  display: inline-block; padding: 3px 10px; border-radius: 100px;
  font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1px;
  margin-bottom: 12px;
}
.template-card-name {
  font-family: var(--font-head); font-size: 1.3rem; font-weight: 800;
  margin-bottom: 8px; letter-spacing: -0.5px;
}
.template-card-desc { font-size: 0.9rem; color: var(--main-muted); line-height: 1.6; margin-bottom: 20px; }
.template-card-footer { display: flex; align-items: center; justify-content: space-between; }
.template-card-tags { display: flex; gap: 6px; flex-wrap: wrap; }
.template-tag {
  font-size: 0.7rem; padding: 3px 8px; border-radius: 4px;
  background: var(--main-bg3); border: 1px solid var(--main-border); color: var(--main-muted);
}
.btn-preview {
  padding: 8px 18px; border-radius: 8px; font-size: 0.85rem;
  font-weight: 600; cursor: pointer; border: none; transition: all var(--transition);
}

/* hover overlay on template card */
.template-card-overlay {
  position: absolute; inset: 0; background: rgba(0,0,0,0.7);
  display: flex; align-items: center; justify-content: center;
  opacity: 0; transition: opacity var(--transition);
  font-size: 1rem; font-weight: 700; color: #fff;
  border-radius: 20px 20px 0 0;
}
.template-card:hover .template-card-overlay { opacity: 1; }

/* ══════════════════════════════════════════════════════
   FEATURES SECTION
══════════════════════════════════════════════════════ */
.features-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 24px; }
.feature-card {
  background: var(--main-bg2); border: 1px solid var(--main-border);
  border-radius: var(--radius-lg); padding: 32px;
  transition: all var(--transition);
}
.feature-card:hover { border-color: var(--main-primary); transform: translateY(-3px); }
.feature-icon {
  width: 56px; height: 56px; border-radius: 14px;
  background: linear-gradient(135deg, rgba(124,58,237,0.2), rgba(6,182,212,0.2));
  border: 1px solid rgba(124,58,237,0.3);
  display: flex; align-items: center; justify-content: center;
  font-size: 1.4rem; margin-bottom: 20px;
  color: #A78BFA;
}
.feature-title { font-family: var(--font-head); font-size: 1.1rem; font-weight: 700; margin-bottom: 10px; }
.feature-desc { font-size: 0.9rem; color: var(--main-muted); line-height: 1.7; }

/* ══════════════════════════════════════════════════════
   AUTH PAGES
══════════════════════════════════════════════════════ */
.auth-page {
  min-height: 100vh; display: flex; align-items: center; justify-content: center;
  padding: 100px 24px 40px;
  background:
    radial-gradient(ellipse 60% 50% at 30% 40%, rgba(124,58,237,0.12) 0%, transparent 60%),
    radial-gradient(ellipse 40% 40% at 80% 60%, rgba(6,182,212,0.08) 0%, transparent 60%),
    var(--main-bg);
}
.auth-card {
  background: var(--main-bg2); border: 1px solid var(--main-border);
  border-radius: 24px; padding: 48px 40px; width: 100%; max-width: 480px;
  box-shadow: var(--shadow-lg);
}
.auth-logo { text-align: center; margin-bottom: 32px; }
.auth-logo .main-logo { font-size: 2rem; }
.auth-title { font-family: var(--font-head); font-size: 1.8rem; font-weight: 800; margin-bottom: 6px; text-align: center; }
.auth-sub { color: var(--main-muted); font-size: 0.9rem; text-align: center; margin-bottom: 32px; }
.form-group { margin-bottom: 20px; }
.form-label { display: block; font-size: 0.85rem; font-weight: 600; color: var(--main-muted); margin-bottom: 8px; }
.form-control {
  width: 100%; padding: 12px 16px; border-radius: 10px;
  background: var(--main-bg3); border: 1px solid var(--main-border);
  color: var(--main-text); font-size: 0.95rem;
  transition: all var(--transition);
}
.form-control:focus { outline: none; border-color: var(--main-primary); box-shadow: 0 0 0 3px rgba(124,58,237,0.15); }
.form-control::placeholder { color: var(--main-muted); }
.btn-block { width: 100%; justify-content: center; padding: 13px; font-size: 1rem; }
.auth-switch { text-align: center; margin-top: 24px; font-size: 0.9rem; color: var(--main-muted); }
.auth-switch a { color: var(--main-primary); font-weight: 600; }
.auth-switch a:hover { text-decoration: underline; }
.form-input-icon { position: relative; }
.form-input-icon i {
  position: absolute; left: 14px; top: 50%; transform: translateY(-50%);
  color: var(--main-muted); font-size: 0.9rem;
}
.form-input-icon .form-control { padding-left: 40px; }
.alert {
  padding: 12px 16px; border-radius: 10px; font-size: 0.9rem; margin-bottom: 16px;
  display: none;
}
.alert-error { background: rgba(239,68,68,0.1); border: 1px solid rgba(239,68,68,0.3); color: #FCA5A5; }
.alert-success { background: rgba(16,185,129,0.1); border: 1px solid rgba(16,185,129,0.3); color: #6EE7B7; }
.alert.show { display: block; }

/* ══════════════════════════════════════════════════════
   PROFILE PAGE
══════════════════════════════════════════════════════ */
.profile-page { padding: 100px 24px 60px; min-height: 100vh; }
.profile-inner { max-width: 1000px; margin: 0 auto; }
.profile-header {
  background: var(--main-bg2); border: 1px solid var(--main-border);
  border-radius: 20px; padding: 40px; margin-bottom: 28px;
  display: flex; gap: 32px; align-items: flex-start;
}
.profile-avatar-big {
  width: 100px; height: 100px; border-radius: 50%; flex-shrink: 0;
  background: linear-gradient(135deg, #7C3AED, #06B6D4);
  display: flex; align-items: center; justify-content: center;
  font-family: var(--font-head); font-size: 2.5rem; font-weight: 800; color: #fff;
  border: 3px solid var(--main-primary);
}
.profile-avatar-big img { width: 100%; height: 100%; border-radius: 50%; object-fit: cover; }
.profile-info h1 { font-family: var(--font-head); font-size: 1.8rem; font-weight: 800; margin-bottom: 4px; }
.profile-info .profile-handle { color: var(--main-muted); margin-bottom: 12px; }
.profile-info .profile-bio { color: var(--main-text); font-size: 0.95rem; }
.profile-badge {
  display: inline-flex; align-items: center; gap: 6px;
  background: rgba(124,58,237,0.15); border: 1px solid rgba(124,58,237,0.3);
  padding: 4px 12px; border-radius: 100px; font-size: 0.75rem; font-weight: 600;
  color: #A78BFA; margin-left: 12px;
}
.tabs { display: flex; gap: 4px; border-bottom: 1px solid var(--main-border); margin-bottom: 28px; }
.tab-btn {
  padding: 12px 20px; font-size: 0.9rem; font-weight: 600; cursor: pointer;
  border: none; background: none; color: var(--main-muted);
  border-bottom: 2px solid transparent; margin-bottom: -1px;
  transition: all var(--transition);
}
.tab-btn.active { color: var(--main-primary); border-bottom-color: var(--main-primary); }
.tab-btn:hover:not(.active) { color: var(--main-text); }
.tab-panel { display: none; }
.tab-panel.active { display: block; }
.settings-card {
  background: var(--main-bg2); border: 1px solid var(--main-border);
  border-radius: 16px; padding: 32px; margin-bottom: 20px;
}
.settings-card h3 { font-family: var(--font-head); font-size: 1.1rem; font-weight: 700; margin-bottom: 24px; display: flex; align-items: center; gap: 10px; }
.settings-card h3 i { color: var(--main-primary); }
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.form-grid-full { grid-column: 1/-1; }
select.form-control { appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' fill='%2364748B'%3E%3Cpath d='M0 0l6 8 6-8z'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 14px center; padding-right: 36px; }
.toggle-switch { display: flex; align-items: center; justify-content: space-between; padding: 16px 0; border-bottom: 1px solid var(--main-border); }
.toggle-switch:last-child { border-bottom: none; }
.toggle-label h4 { font-size: 0.9rem; font-weight: 600; margin-bottom: 2px; }
.toggle-label p { font-size: 0.8rem; color: var(--main-muted); }
.toggle-input { position: relative; width: 46px; height: 26px; }
.toggle-input input { opacity: 0; width: 0; height: 0; }
.toggle-slider {
  position: absolute; inset: 0; cursor: pointer; background: var(--main-bg3);
  border: 1px solid var(--main-border); border-radius: 100px;
  transition: all 0.3s;
}
.toggle-slider::before {
  content: ''; position: absolute; width: 18px; height: 18px;
  left: 3px; top: 50%; transform: translateY(-50%);
  background: var(--main-muted); border-radius: 50%; transition: all 0.3s;
}
.toggle-input input:checked + .toggle-slider { background: var(--main-primary); border-color: var(--main-primary); }
.toggle-input input:checked + .toggle-slider::before { transform: translateX(20px) translateY(-50%); background: #fff; }

/* ══════════════════════════════════════════════════════
   MODAL
══════════════════════════════════════════════════════ */
.modal-overlay {
  position: fixed; inset: 0; background: rgba(0,0,0,0.75);
  z-index: 2000; display: none; align-items: center; justify-content: center;
  padding: 20px; backdrop-filter: blur(4px);
}
.modal-overlay.open { display: flex; }
.modal {
  background: var(--main-bg2); border: 1px solid var(--main-border);
  border-radius: 20px; width: 100%; max-width: 520px;
  max-height: 90vh; overflow-y: auto;
  animation: modalIn 0.3s ease;
}
@keyframes modalIn { from { opacity:0; transform: scale(0.95) translateY(20px); } to { opacity:1; transform: none; } }
.modal-header {
  padding: 24px 28px; border-bottom: 1px solid var(--main-border);
  display: flex; align-items: center; justify-content: space-between;
}
.modal-title { font-family: var(--font-head); font-size: 1.2rem; font-weight: 700; }
.modal-close { width: 32px; height: 32px; border-radius: 8px; border: none; background: var(--main-bg3); color: var(--main-muted); cursor: pointer; font-size: 1rem; display: flex; align-items: center; justify-content: center; transition: all var(--transition); }
.modal-close:hover { background: #EF4444; color: #fff; }
.modal-body { padding: 28px; }
.modal-footer { padding: 20px 28px; border-top: 1px solid var(--main-border); display: flex; gap: 12px; justify-content: flex-end; }

/* ══════════════════════════════════════════════════════
   TOAST NOTIFICATIONS
══════════════════════════════════════════════════════ */
#toast-container {
  position: fixed; bottom: 24px; right: 24px; z-index: 9999;
  display: flex; flex-direction: column; gap: 10px; max-width: 360px;
}
.toast {
  padding: 14px 20px; border-radius: 12px; font-size: 0.9rem; font-weight: 500;
  display: flex; align-items: center; gap: 12px;
  animation: toastIn 0.3s ease; box-shadow: var(--shadow);
}
@keyframes toastIn { from { opacity:0; transform: translateX(20px); } to { opacity:1; transform:none; } }
.toast-success { background: #064E3B; border: 1px solid #10B981; color: #6EE7B7; }
.toast-error { background: #7F1D1D; border: 1px solid #EF4444; color: #FCA5A5; }
.toast-info { background: #1E1B4B; border: 1px solid #7C3AED; color: #C4B5FD; }

/* ══════════════════════════════════════════════════════
   FOOTER (Main Site)
══════════════════════════════════════════════════════ */
.main-footer {
  background: var(--main-bg2); border-top: 1px solid var(--main-border);
  padding: 60px 24px 32px;
}
.footer-inner { max-width: 1400px; margin: 0 auto; }
.footer-grid { display: grid; grid-template-columns: 2fr 1fr 1fr 1fr; gap: 48px; margin-bottom: 48px; }
.footer-brand p { color: var(--main-muted); font-size: 0.9rem; line-height: 1.7; margin-top: 16px; max-width: 300px; }
.footer-col h4 { font-family: var(--font-head); font-size: 0.85rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1.5px; color: var(--main-muted); margin-bottom: 16px; }
.footer-col a { display: block; color: var(--main-muted); font-size: 0.9rem; margin-bottom: 10px; transition: color var(--transition); }
.footer-col a:hover { color: var(--main-text); }
.footer-bottom { border-top: 1px solid var(--main-border); padding-top: 28px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px; }
.footer-bottom p { color: var(--main-muted); font-size: 0.85rem; }
.social-links { display: flex; gap: 12px; }
.social-btn {
  width: 38px; height: 38px; border-radius: 10px;
  background: var(--main-bg3); border: 1px solid var(--main-border);
  display: flex; align-items: center; justify-content: center;
  color: var(--main-muted); font-size: 0.95rem; transition: all var(--transition);
}
.social-btn:hover { background: var(--main-primary); color: #fff; border-color: var(--main-primary); }

/* ══════════════════════════════════════════════════════
   LOADING SPINNER
══════════════════════════════════════════════════════ */
.spinner {
  width: 20px; height: 20px; border-radius: 50%;
  border: 2px solid rgba(255,255,255,0.2);
  border-top-color: #fff; animation: spin 0.6s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }

/* ══════════════════════════════════════════════════════
   HOW IT WORKS SECTION
══════════════════════════════════════════════════════ */
.steps-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 32px; }
.step-card {
  background: var(--main-bg2); border: 1px solid var(--main-border);
  border-radius: 20px; padding: 36px 28px; position: relative; overflow: hidden;
  transition: all var(--transition);
}
.step-card::before {
  content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px;
  background: linear-gradient(90deg, #7C3AED, #06B6D4);
}
.step-card:hover { transform: translateY(-4px); box-shadow: var(--shadow); }
.step-num {
  font-family: var(--font-head); font-size: 4rem; font-weight: 900;
  color: rgba(124,58,237,0.1); line-height: 1; margin-bottom: 16px;
}
.step-title { font-family: var(--font-head); font-size: 1.1rem; font-weight: 700; margin-bottom: 12px; }
.step-desc { color: var(--main-muted); font-size: 0.9rem; line-height: 1.7; }

/* ══════════════════════════════════════════════════════
   CTA SECTION
══════════════════════════════════════════════════════ */
.cta-section {
  padding: 100px 24px;
  background: linear-gradient(135deg, rgba(124,58,237,0.1) 0%, rgba(6,182,212,0.05) 100%);
  border-top: 1px solid var(--main-border); border-bottom: 1px solid var(--main-border);
  text-align: center;
}
.cta-title { font-family: var(--font-head); font-size: clamp(2rem, 4vw, 3.5rem); font-weight: 900; letter-spacing: -1px; margin-bottom: 20px; }
.cta-sub { font-size: 1.1rem; color: var(--main-muted); max-width: 500px; margin: 0 auto 40px; }

/* ══════════════════════════════════════════════════════
   TEMPLATE SITE STYLES
══════════════════════════════════════════════════════ */
.t-body { font-family: var(--t-body-font, 'Inter', sans-serif); background: var(--t-bg); color: var(--t-text); }
.t-nav {
  position: fixed; top: 0; left: 0; right: 0; z-index: 100;
  height: 65px; display: flex; align-items: center;
  background: var(--t-nav-gradient); border-bottom: 1px solid var(--t-border);
  backdrop-filter: blur(20px);
}
.t-nav-inner {
  max-width: 1300px; width: 100%; margin: 0 auto;
  padding: 0 24px; display: flex; align-items: center;
  justify-content: space-between; gap: 20px;
}
.t-logo { font-family: var(--t-font); font-size: 1.4rem; font-weight: 700; color: var(--t-primary); letter-spacing: 1px; }
.t-nav-links { display: flex; gap: 4px; }
.t-nav-links a {
  padding: 7px 14px; border-radius: 8px; font-size: 0.88rem;
  font-weight: 500; color: var(--t-muted); transition: all 0.25s;
}
.t-nav-links a:hover, .t-nav-links a.active { color: var(--t-text); background: rgba(255,255,255,0.06); }
.t-btn {
  padding: 9px 20px; border-radius: 8px; font-weight: 700;
  font-size: 0.875rem; cursor: pointer; border: none; transition: all 0.25s;
}
.t-btn-primary {
  background: var(--t-gradient); color: #fff;
  box-shadow: var(--t-glow);
}
.t-btn-primary:hover { transform: translateY(-1px); filter: brightness(1.1); }
.t-btn-outline {
  background: transparent; border: 1px solid var(--t-border);
  color: var(--t-text);
}
.t-btn-outline:hover { border-color: var(--t-primary); background: rgba(255,255,255,0.04); }
/* Template hero */
.t-hero {
  min-height: 100vh; display: flex; align-items: center;
  padding: 100px 24px 60px; position: relative; overflow: hidden;
}
.t-hero-bg { position: absolute; inset: 0; background: var(--t-hero-gradient); z-index: 0; }
.t-hero-inner { max-width: 1300px; margin: 0 auto; position: relative; z-index: 1; width: 100%; }
.t-hero-title { font-family: var(--t-font); font-size: clamp(2.5rem, 6vw, 5.5rem); font-weight: 900; line-height: 1; margin-bottom: 20px; color: var(--t-text); letter-spacing: -2px; }
.t-hero-title .accent { color: var(--t-primary); }
.t-hero-sub { font-size: 1.1rem; color: var(--t-muted); max-width: 520px; line-height: 1.7; margin-bottom: 36px; }
.t-hero-actions { display: flex; gap: 14px; flex-wrap: wrap; }
.t-section { padding: 80px 24px; }
.t-section-inner { max-width: 1300px; margin: 0 auto; }
.t-section-header { margin-bottom: 48px; }
.t-section-tag { font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 2px; color: var(--t-primary); margin-bottom: 8px; }
.t-section-title { font-family: var(--t-font); font-size: clamp(1.6rem, 3vw, 2.5rem); font-weight: 800; color: var(--t-text); letter-spacing: -0.5px; margin-bottom: 12px; }
.t-section-sub { color: var(--t-muted); font-size: 0.95rem; max-width: 500px; }

/* Template game cards */
.t-games-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 20px; }
.t-game-card {
  background: var(--t-bg2); border: 1px solid var(--t-border);
  border-radius: 14px; overflow: hidden; transition: all 0.25s; cursor: pointer;
  position: relative;
}
.t-game-card:hover { transform: translateY(-4px); border-color: var(--t-primary); box-shadow: var(--t-glow); }
.t-game-img {
  height: 160px; position: relative; overflow: hidden;
  display: flex; align-items: center; justify-content: center;
}
.t-game-img-placeholder {
  width: 100%; height: 100%;
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  gap: 8px; font-size: 0.75rem; color: var(--t-muted);
}
.t-game-img-placeholder i { font-size: 2.5rem; opacity: 0.3; }
.t-game-featured {
  position: absolute; top: 10px; left: 10px;
  background: var(--t-gradient); color: #fff;
  font-size: 0.65rem; font-weight: 700; padding: 3px 8px;
  border-radius: 4px; text-transform: uppercase; letter-spacing: 0.5px;
}
.t-game-body { padding: 18px; }
.t-game-genre { font-size: 0.7rem; font-weight: 700; text-transform: uppercase; color: var(--t-primary); letter-spacing: 1px; margin-bottom: 6px; }
.t-game-title { font-family: var(--t-font); font-size: 1rem; font-weight: 700; margin-bottom: 8px; color: var(--t-text); }
.t-game-desc { font-size: 0.82rem; color: var(--t-muted); line-height: 1.5; margin-bottom: 14px; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.t-game-footer { display: flex; align-items: center; justify-content: space-between; }
.t-rating { display: flex; align-items: center; gap: 4px; font-size: 0.85rem; font-weight: 700; color: #F59E0B; }
.t-game-platform { font-size: 0.72rem; color: var(--t-muted); }

/* Template news cards */
.t-news-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 24px; }
.t-news-card {
  background: var(--t-bg2); border: 1px solid var(--t-border);
  border-radius: 14px; overflow: hidden; transition: all 0.25s; cursor: pointer;
}
.t-news-card:hover { transform: translateY(-4px); border-color: var(--t-primary); }
.t-news-img {
  height: 180px; background: var(--t-bg3);
  display: flex; align-items: center; justify-content: center; position: relative;
  overflow: hidden;
}
.t-news-img i { font-size: 3rem; opacity: 0.15; color: var(--t-primary); }
.t-news-img-gradient {
  position: absolute; inset: 0;
  background: linear-gradient(0deg, var(--t-bg2) 0%, transparent 60%);
}
.t-news-body { padding: 20px; }
.t-news-cat { font-size: 0.68rem; font-weight: 700; text-transform: uppercase; color: var(--t-primary); letter-spacing: 1px; margin-bottom: 8px; }
.t-news-title { font-family: var(--t-font); font-size: 1.05rem; font-weight: 700; color: var(--t-text); margin-bottom: 10px; line-height: 1.3; }
.t-news-excerpt { font-size: 0.85rem; color: var(--t-muted); line-height: 1.6; margin-bottom: 16px; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.t-news-footer { display: flex; align-items: center; justify-content: space-between; font-size: 0.78rem; color: var(--t-muted); }
.t-news-author { display: flex; align-items: center; gap: 6px; }
.t-news-avatar {
  width: 24px; height: 24px; border-radius: 50%;
  background: var(--t-gradient); display: flex; align-items: center; justify-content: center;
  font-size: 0.6rem; font-weight: 700; color: #fff;
}

/* Setup cards */
.t-setups-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 24px; }
.t-setup-card {
  background: var(--t-bg2); border: 1px solid var(--t-border);
  border-radius: 14px; overflow: hidden; transition: all 0.25s;
}
.t-setup-card:hover { border-color: var(--t-primary); transform: translateY(-3px); }
.t-setup-header {
  padding: 20px; border-bottom: 1px solid var(--t-border);
  display: flex; align-items: center; gap: 14px;
}
.t-setup-avatar {
  width: 44px; height: 44px; border-radius: 10px;
  background: var(--t-gradient); display: flex; align-items: center; justify-content: center;
  font-family: var(--t-font); font-weight: 700; color: #fff; font-size: 1rem;
}
.t-setup-name { font-weight: 700; font-size: 1rem; color: var(--t-text); }
.t-setup-owner { font-size: 0.8rem; color: var(--t-muted); }
.t-setup-body { padding: 20px; }
.t-spec-row { display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 1px solid var(--t-border); font-size: 0.85rem; }
.t-spec-row:last-child { border-bottom: none; }
.t-spec-label { color: var(--t-muted); font-weight: 500; }
.t-spec-value { color: var(--t-text); font-weight: 600; text-align: right; max-width: 55%; }
.t-setup-footer { padding: 16px 20px; border-top: 1px solid var(--t-border); display: flex; align-items: center; justify-content: space-between; }
.t-likes { display: flex; align-items: center; gap: 6px; font-size: 0.85rem; color: var(--t-muted); cursor: pointer; transition: color 0.25s; }
.t-likes:hover { color: #EF4444; }
.t-likes i { font-size: 1rem; }
.t-cost { font-family: var(--t-font); font-size: 1rem; font-weight: 700; color: var(--t-primary); }

/* Tournament cards */
.t-tournament-list { display: flex; flex-direction: column; gap: 16px; }
.t-tournament-card {
  background: var(--t-bg2); border: 1px solid var(--t-border);
  border-radius: 14px; padding: 24px;
  display: grid; grid-template-columns: 1fr auto;
  gap: 20px; align-items: center; transition: all 0.25s;
}
.t-tournament-card:hover { border-color: var(--t-primary); }
.t-tournament-name { font-family: var(--t-font); font-size: 1.1rem; font-weight: 700; color: var(--t-text); margin-bottom: 6px; }
.t-tournament-meta { display: flex; gap: 16px; flex-wrap: wrap; }
.t-tournament-meta-item { display: flex; align-items: center; gap: 6px; font-size: 0.82rem; color: var(--t-muted); }
.t-tournament-meta-item i { color: var(--t-primary); }
.t-status { padding: 4px 10px; border-radius: 100px; font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
.t-status-upcoming { background: rgba(245,158,11,0.15); color: #F59E0B; border: 1px solid rgba(245,158,11,0.3); }
.t-status-ongoing { background: rgba(16,185,129,0.15); color: #10B981; border: 1px solid rgba(16,185,129,0.3); }
.t-status-completed { background: rgba(100,116,139,0.15); color: #94A3B8; border: 1px solid rgba(100,116,139,0.3); }
.t-prize { font-family: var(--t-font); font-size: 1.3rem; font-weight: 700; color: var(--t-primary); margin-bottom: 4px; }

/* Template sub-nav (breadcrumb) */
.t-subnav {
  background: var(--t-bg2); border-bottom: 1px solid var(--t-border);
  padding: 0 24px;
}
.t-subnav-inner {
  max-width: 1300px; margin: 0 auto;
  display: flex; align-items: center; gap: 4px;
  overflow-x: auto;
}
.t-subnav-link {
  padding: 14px 16px; font-size: 0.875rem; font-weight: 600;
  color: var(--t-muted); white-space: nowrap; border-bottom: 2px solid transparent;
  transition: all 0.25s; cursor: pointer;
}
.t-subnav-link.active { color: var(--t-primary); border-bottom-color: var(--t-primary); }
.t-subnav-link:hover:not(.active) { color: var(--t-text); }

/* Search & filter bar */
.t-search-bar {
  background: var(--t-bg2); border: 1px solid var(--t-border);
  border-radius: 12px; padding: 20px 24px;
  display: flex; gap: 16px; align-items: center; margin-bottom: 28px;
  flex-wrap: wrap;
}
.t-search-input-wrap { flex: 1; min-width: 200px; position: relative; }
.t-search-input-wrap i { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: var(--t-muted); }
.t-search-input {
  width: 100%; padding: 10px 14px 10px 38px;
  background: var(--t-bg3); border: 1px solid var(--t-border);
  border-radius: 8px; color: var(--t-text); font-size: 0.9rem;
  transition: all 0.25s;
}
.t-search-input:focus { outline: none; border-color: var(--t-primary); }
.t-filter-select {
  padding: 10px 14px; background: var(--t-bg3); border: 1px solid var(--t-border);
  border-radius: 8px; color: var(--t-text); font-size: 0.875rem; cursor: pointer;
}
.t-filter-select:focus { outline: none; border-color: var(--t-primary); }

/* Template article detail */
.t-article-hero { padding: 100px 24px 48px; background: var(--t-hero-gradient); border-bottom: 1px solid var(--t-border); }
.t-article-body { max-width: 800px; margin: 0 auto; padding: 48px 24px; }
.t-article-content { font-size: 1.05rem; line-height: 1.85; color: var(--t-text); }
.t-article-content p { margin-bottom: 20px; }
.t-comments { max-width: 800px; margin: 0 auto; padding: 0 24px 60px; border-top: 1px solid var(--t-border); padding-top: 40px; }
.t-comment { display: flex; gap: 14px; margin-bottom: 24px; padding-bottom: 24px; border-bottom: 1px solid var(--t-border); }
.t-comment-avatar {
  width: 40px; height: 40px; border-radius: 50%; flex-shrink: 0;
  background: var(--t-gradient); display: flex; align-items: center;
  justify-content: center; font-weight: 700; color: #fff; font-size: 0.85rem;
}
.t-comment-body { flex: 1; }
.t-comment-name { font-weight: 700; font-size: 0.9rem; margin-bottom: 4px; color: var(--t-text); }
.t-comment-text { font-size: 0.9rem; color: var(--t-muted); line-height: 1.6; }
.t-comment-date { font-size: 0.75rem; color: var(--t-muted); margin-top: 6px; }

/* Template contact section */
.t-contact-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; }
.t-contact-info h3 { font-family: var(--t-font); font-size: 1.5rem; font-weight: 700; margin-bottom: 16px; color: var(--t-text); }
.t-contact-info p { color: var(--t-muted); line-height: 1.7; margin-bottom: 24px; }
.t-contact-item { display: flex; align-items: center; gap: 12px; margin-bottom: 16px; font-size: 0.9rem; color: var(--t-muted); }
.t-contact-item i { width: 36px; height: 36px; border-radius: 8px; background: rgba(255,255,255,0.05); display: flex; align-items: center; justify-content: center; color: var(--t-primary); flex-shrink: 0; }
.t-contact-form { background: var(--t-bg2); border: 1px solid var(--t-border); border-radius: 16px; padding: 32px; }
.t-form-group { margin-bottom: 18px; }
.t-form-label { display: block; font-size: 0.82rem; font-weight: 600; color: var(--t-muted); margin-bottom: 6px; }
.t-form-control {
  width: 100%; padding: 11px 14px; border-radius: 8px;
  background: var(--t-bg3); border: 1px solid var(--t-border);
  color: var(--t-text); font-size: 0.9rem; transition: all 0.25s;
}
.t-form-control:focus { outline: none; border-color: var(--t-primary); }
.t-form-control::placeholder { color: var(--t-muted); }
textarea.t-form-control { resize: vertical; min-height: 120px; }

/* Admin panel */
.admin-bar {
  background: rgba(245,158,11,0.1); border-bottom: 1px solid rgba(245,158,11,0.2);
  padding: 10px 24px; display: flex; align-items: center; gap: 16px; flex-wrap: wrap;
}
.admin-bar span { font-size: 0.82rem; color: #F59E0B; font-weight: 600; display: flex; align-items: center; gap: 6px; }
.admin-bar .admin-actions { display: flex; gap: 8px; margin-left: auto; }

/* Back to main button */
.back-to-main {
  display: inline-flex; align-items: center; gap: 8px;
  padding: 8px 16px; border-radius: 8px;
  background: rgba(255,255,255,0.06); border: 1px solid var(--t-border);
  color: var(--t-muted); font-size: 0.82rem; font-weight: 600;
  transition: all 0.25s; cursor: pointer;
}
.back-to-main:hover { color: var(--t-text); background: rgba(255,255,255,0.1); }

/* Pixel-specific extras */
.pixel-grid {
  background-image: linear-gradient(rgba(255,255,255,0.03) 1px, transparent 1px),
                    linear-gradient(90deg, rgba(255,255,255,0.03) 1px, transparent 1px);
  background-size: 20px 20px;
}
.pixel-border { image-rendering: pixelated; border: 2px solid var(--t-primary); box-shadow: 4px 4px 0 var(--t-primary); }

/* Neon glow effects for CyberNeon */
.neon-text { text-shadow: 0 0 10px var(--t-primary), 0 0 30px var(--t-primary); }
.neon-border { box-shadow: 0 0 10px var(--t-primary), inset 0 0 10px rgba(168,85,247,0.05); }

/* Ornate border for MythQuest */
.ornate-border { border: 1px solid var(--t-primary); position: relative; }
.ornate-border::before, .ornate-border::after {
  content: '✦'; position: absolute; color: var(--t-primary); font-size: 0.7rem;
}
.ornate-border::before { top: -8px; left: 12px; }
.ornate-border::after { bottom: -8px; right: 12px; }

/* Progress bar */
.t-progress { background: var(--t-bg3); border-radius: 100px; height: 6px; overflow: hidden; }
.t-progress-bar { height: 100%; background: var(--t-gradient); border-radius: 100px; transition: width 0.8s ease; }

/* Stats strip */
.t-stats-strip {
  display: grid; grid-template-columns: repeat(4,1fr);
  gap: 1px; background: var(--t-border);
  border: 1px solid var(--t-border); border-radius: 14px; overflow: hidden; margin-bottom: 40px;
}
.t-stat-cell {
  background: var(--t-bg2); padding: 24px; text-align: center;
}
.t-stat-num { font-family: var(--t-font); font-size: 1.8rem; font-weight: 900; color: var(--t-primary); }
.t-stat-label { font-size: 0.78rem; color: var(--t-muted); margin-top: 4px; text-transform: uppercase; letter-spacing: 1px; }

/* Template footer */
.t-footer {
  background: var(--t-bg2); border-top: 1px solid var(--t-border);
  padding: 40px 24px 24px;
}
.t-footer-inner { max-width: 1300px; margin: 0 auto; }
.t-footer-top { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 20px; margin-bottom: 24px; }
.t-footer-links { display: flex; gap: 20px; flex-wrap: wrap; }
.t-footer-links a { font-size: 0.875rem; color: var(--t-muted); transition: color 0.25s; }
.t-footer-links a:hover { color: var(--t-text); }
.t-footer-bottom { border-top: 1px solid var(--t-border); padding-top: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; }
.t-footer-bottom p { font-size: 0.82rem; color: var(--t-muted); }

/* Responsive */
@media (max-width: 1024px) {
  .hero-inner { grid-template-columns: 1fr; }
  .hero-preview { transform: none; max-width: 500px; }
  .footer-grid { grid-template-columns: 1fr 1fr; }
  .steps-grid { grid-template-columns: 1fr 1fr; }
  .t-contact-grid { grid-template-columns: 1fr; }
  .t-tournament-card { grid-template-columns: 1fr; }
  .t-stats-strip { grid-template-columns: 1fr 1fr; }
  .form-grid { grid-template-columns: 1fr; }
}
@media (max-width: 768px) {
  .nav-links { display: none; }
  .hamburger { display: flex; }
  .nav-links.open { display: flex; flex-direction: column; position: fixed; top: 70px; left: 0; right: 0; background: var(--main-bg2); border-bottom: 1px solid var(--main-border); padding: 16px; z-index: 999; }
  .footer-grid { grid-template-columns: 1fr; }
  .steps-grid { grid-template-columns: 1fr; }
  .hero-stats { flex-wrap: wrap; gap: 20px; }
  .profile-header { flex-direction: column; }
  .t-nav-links { display: none; }
  .t-stats-strip { grid-template-columns: 1fr 1fr; }
  .t-search-bar { flex-direction: column; }
  .auth-card { padding: 32px 24px; }
}
@media (max-width: 480px) {
  .templates-grid { grid-template-columns: 1fr; }
  .t-games-grid { grid-template-columns: 1fr; }
  .t-news-grid { grid-template-columns: 1fr; }
  .t-setups-grid { grid-template-columns: 1fr; }
  .t-stats-strip { grid-template-columns: 1fr 1fr; }
}

/* Animation utilities */
.fade-in { animation: fadeIn 0.6s ease; }
@keyframes fadeIn { from { opacity:0; transform: translateY(16px); } to { opacity:1; transform:none; } }
.slide-in { animation: slideIn 0.4s ease; }
@keyframes slideIn { from { opacity:0; transform: translateX(-16px); } to { opacity:1; transform:none; } }

/* Utility classes */
.text-primary { color: var(--main-primary); }
.text-muted { color: var(--main-muted); }
.text-center { text-align: center; }
.font-head { font-family: var(--font-head); }
.fw-700 { font-weight: 700; }
.fw-800 { font-weight: 800; }
.mt-4 { margin-top: 16px; }
.mt-8 { margin-top: 32px; }
.mb-4 { margin-bottom: 16px; }
.mb-8 { margin-bottom: 32px; }
.flex { display: flex; }
.flex-center { display: flex; align-items: center; justify-content: center; }
.gap-2 { gap: 8px; }
.gap-4 { gap: 16px; }
.empty-state { text-align: center; padding: 60px 20px; color: var(--main-muted); }
.empty-state i { font-size: 3rem; opacity: 0.2; margin-bottom: 16px; display: block; }
.empty-state p { font-size: 1rem; }
.badge { display: inline-flex; align-items: center; gap: 4px; padding: 3px 8px; border-radius: 4px; font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
.badge-admin { background: rgba(245,158,11,0.15); color: #F59E0B; border: 1px solid rgba(245,158,11,0.3); }
.badge-user { background: rgba(124,58,237,0.15); color: #A78BFA; border: 1px solid rgba(124,58,237,0.3); }
</style>
</head>
<body>

<?php if ($isTemplate && $templateData): ?>
<?php
// ─────────────────────────────────────────────────────────────
// TEMPLATE SITE OUTPUT
// ─────────────────────────────────────────────────────────────
$t = $themes[$templateId];
$tId = $templateId;
$tName = $templateData['name'];
$tDesc = $templateData['description'];
// Fetch template data
$tGames = rows("SELECT * FROM games WHERE template_id=? ORDER BY is_featured DESC, rating DESC", [$tId], 'i');
$tFeaturedGames = array_filter($tGames, fn($g) => $g['is_featured']);
$tNews = rows("SELECT n.*, u.username FROM news_articles n LEFT JOIN users u ON u.id=n.author_id WHERE n.template_id=? AND n.is_published=1 ORDER BY n.published_at DESC", [$tId], 'i');
$tSetups = rows("SELECT * FROM gaming_setups WHERE template_id=? ORDER BY is_featured DESC, likes_count DESC", [$tId], 'i');
$tTournaments = rows("SELECT * FROM tournaments WHERE template_id=? ORDER BY FIELD(status,'ongoing','upcoming','completed'), start_date DESC", [$tId], 'i');

// CSS variables for this template
echo "<style>
:root {
  --t-primary: {$t['primary']};
  --t-secondary: {$t['secondary']};
  --t-bg: {$t['bg']};
  --t-bg2: {$t['bg2']};
  --t-bg3: {$t['bg3']};
  --t-text: {$t['text']};
  --t-muted: {$t['muted']};
  --t-border: {$t['border']};
  --t-font: {$t['font']};
  --t-body-font: {$t['body_font']};
  --t-glow: {$t['glow']};
  --t-gradient: {$t['gradient']};
  --t-nav-gradient: {$t['nav_gradient']};
  --t-hero-gradient: {$t['hero_gradient']};
}
body { background: {$t['bg']}; color: {$t['text']}; font-family: {$t['body_font']}; }
</style>";
?>

<!-- Template Navbar -->
<nav class="t-nav" style="top:0">
  <div class="t-nav-inner">
    <a href="<?= SITE_URL ?>" class="back-to-main"><i class="fa fa-arrow-left"></i> GameForge</a>
    <a href="?page=template&id=<?= $tId ?>" class="t-logo"><?= e($tName) ?></a>
    <div class="t-nav-links">
      <a href="?page=template&id=<?= $tId ?>&section=home" class="<?= $section==='home'?'active':'' ?>"><i class="fa fa-home"></i> Home</a>
      <a href="?page=template&id=<?= $tId ?>&section=games" class="<?= $section==='games'?'active':'' ?>"><i class="fa fa-gamepad"></i> Games</a>
      <a href="?page=template&id=<?= $tId ?>&section=news" class="<?= $section==='news'?'active':'' ?>"><i class="fa fa-newspaper"></i> News</a>
      <a href="?page=template&id=<?= $tId ?>&section=setup" class="<?= $section==='setup'?'active':'' ?>"><i class="fa fa-desktop"></i> Setups</a>
      <?php if (!empty($tTournaments)): ?>
      <a href="?page=template&id=<?= $tId ?>&section=tournaments" class="<?= $section==='tournaments'?'active':'' ?>"><i class="fa fa-trophy"></i> Tournaments</a>
      <?php endif; ?>
      <a href="?page=template&id=<?= $tId ?>&section=contact" class="<?= $section==='contact'?'active':'' ?>"><i class="fa fa-envelope"></i> Contact</a>
    </div>
    <div style="display:flex;gap:10px;align-items:center;">
      <?php if (isLoggedIn()): $user = currentUser(); ?>
        <span style="font-size:0.8rem;color:var(--t-muted);">Hi, <?= e($user['username'] ?? '') ?></span>
      <?php endif; ?>
      <a href="?page=template&id=<?= $tId ?>&section=contact" class="t-btn t-btn-primary">Join Now</a>
    </div>
  </div>
</nav>

<?php if (isAdmin()): ?>
<div class="admin-bar" style="margin-top:65px;">
  <span><i class="fa fa-shield-halved"></i> Admin Mode — <?= e($tName) ?></span>
  <div class="admin-actions">
    <button class="btn btn-sm btn-success" onclick="openModal('addGameModal')"><i class="fa fa-plus"></i> Add Game</button>
    <button class="btn btn-sm btn-primary" onclick="openModal('addSetupModal')"><i class="fa fa-desktop"></i> Add Setup</button>
  </div>
</div>
<?php else: ?>
<div style="height:65px;"></div>
<?php endif; ?>

<?php
// ─────────────────────────────────────────────────────────────
// Section: HOME
// ─────────────────────────────────────────────────────────────
if ($section === 'home'):
?>
<!-- Template Hero -->
<div class="t-hero <?= $tId===4 ? 'pixel-grid' : '' ?>">
  <div class="t-hero-bg"></div>
  <?php if ($tId === 1): ?>
  <!-- CyberNeon hero decorations -->
  <div style="position:absolute;top:20%;right:10%;width:300px;height:300px;border-radius:50%;background:radial-gradient(circle,rgba(168,85,247,0.15),transparent);filter:blur(40px);"></div>
  <div style="position:absolute;bottom:20%;left:5%;width:200px;height:200px;border-radius:50%;background:radial-gradient(circle,rgba(6,182,212,0.1),transparent);filter:blur(30px);"></div>
  <?php elseif ($tId === 2): ?>
  <div style="position:absolute;top:10%;right:10%;width:350px;height:350px;border-radius:50%;background:radial-gradient(circle,rgba(239,68,68,0.1),transparent);filter:blur(60px);"></div>
  <?php elseif ($tId === 3): ?>
  <div style="position:absolute;top:15%;right:15%;width:280px;height:280px;border-radius:50%;background:radial-gradient(circle,rgba(245,158,11,0.1),transparent);filter:blur(50px);"></div>
  <?php elseif ($tId === 4): ?>
  <div style="position:absolute;inset:0;opacity:0.03;background-image:url('data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%2220%22 height=%2220%22%3E%3Crect width=%2220%22 height=%2220%22 fill=%22none%22 stroke=%22%2310B981%22 stroke-width=%220.5%22/%3E%3C/svg%3E');"></div>
  <?php endif; ?>

  <div class="t-hero-inner">
    <div style="max-width:680px;">
      <div style="display:inline-flex;align-items:center;gap:8px;background:rgba(255,255,255,0.05);border:1px solid var(--t-border);padding:6px 14px;border-radius:100px;font-size:0.75rem;font-weight:700;color:var(--t-primary);text-transform:uppercase;letter-spacing:1.5px;margin-bottom:24px;">
        <?php if ($tId===1): ?><i class="fa fa-bolt"></i> Next-Gen Gaming Platform
        <?php elseif ($tId===2): ?><i class="fa fa-trophy"></i> Premier Esports Hub
        <?php elseif ($tId===3): ?><i class="fa fa-dragon"></i> Enter The Realm
        <?php else: ?><i class="fa fa-star"></i> Classic Gaming Vault
        <?php endif; ?>
      </div>
      <h1 class="t-hero-title">
        <?php if ($tId===1): ?>Explore The <span class="accent">Neon</span><br>Gaming Universe<?php
        elseif ($tId===2): ?>DOMINATE<br>THE <span class="accent">ARENA</span><?php
        elseif ($tId===3): ?>Your Epic<br><span class="accent">Quest</span> Begins<?php
        else: ?>Unlock The<br><span class="accent">Pixel</span> Vault<?php endif; ?>
      </h1>
      <p class="t-hero-sub">
        <?php if ($tId===1): ?>Dive into a cyberpunk gaming universe with thousands of titles, live streams, and a community of neon-lit warriors pushing the boundaries of digital reality.
        <?php elseif ($tId===2): ?>The ultimate competitive gaming platform. Join tournaments, track your rank, follow pro teams, and battle for glory in the world's most elite esports arena.
        <?php elseif ($tId===3): ?>Embark on legendary adventures across vast fantasy realms. Discover ancient lore, forge alliances, and carve your name into the annals of gaming history.
        <?php else: ?>The definitive archive of gaming greatness. From arcade classics to indie gems — explore, collect, and celebrate the art and history of video games.
        <?php endif; ?>
      </p>
      <div class="t-hero-actions">
        <a href="?page=template&id=<?= $tId ?>&section=games" class="t-btn t-btn-primary" style="font-size:1rem;padding:13px 28px;">
          <?= $tId===1 ? '<i class="fa fa-rocket"></i> Explore Games' : ($tId===2 ? '<i class="fa fa-sword"></i> Enter Arena' : ($tId===3 ? '<i class="fa fa-dragon"></i> Start Quest' : '<i class="fa fa-gamepad"></i> Open Vault')) ?>
        </a>
        <a href="?page=template&id=<?= $tId ?>&section=news" class="t-btn t-btn-outline" style="font-size:1rem;padding:12px 28px;">
          <i class="fa fa-newspaper"></i> Latest News
        </a>
      </div>
    </div>

    <!-- Hero game showcase (right side) -->
    <?php if (!empty($tFeaturedGames)): ?>
    <div style="display:none;" class="t-hero-featured"></div>
    <?php endif; ?>
  </div>
</div>

<!-- Stats Strip -->
<div class="t-section" style="padding: 0 24px; margin-top: 20px;">
  <div class="t-section-inner">
    <div class="t-stats-strip">
      <div class="t-stat-cell">
        <div class="t-stat-num"><?= count($tGames) ?>+</div>
        <div class="t-stat-label">Games Listed</div>
      </div>
      <div class="t-stat-cell">
        <div class="t-stat-num"><?= count($tNews) ?></div>
        <div class="t-stat-label">News Articles</div>
      </div>
      <div class="t-stat-cell">
        <div class="t-stat-num"><?= count($tSetups) ?></div>
        <div class="t-stat-label">Setups Shared</div>
      </div>
      <div class="t-stat-cell">
        <div class="t-stat-num"><?= count($tTournaments) ?></div>
        <div class="t-stat-label">Tournaments</div>
      </div>
    </div>
  </div>
</div>

<!-- Featured Games Section -->
<?php if (!empty($tFeaturedGames)): ?>
<div class="t-section">
  <div class="t-section-inner">
    <div class="t-section-header" style="display:flex;justify-content:space-between;align-items:flex-end;flex-wrap:wrap;gap:16px;">
      <div>
        <div class="t-section-tag">⭐ Top Picks</div>
        <h2 class="t-section-title">Featured Games</h2>
      </div>
      <a href="?page=template&id=<?= $tId ?>&section=games" class="t-btn t-btn-outline">View All Games →</a>
    </div>
    <div class="t-games-grid">
      <?php foreach (array_slice($tFeaturedGames, 0, 4) as $game): ?>
      <div class="t-game-card fade-in" onclick="showGameDetail(<?= $game['id'] ?>)">
        <div class="t-game-img" style="background:linear-gradient(135deg, var(--t-bg3), var(--t-bg2));">
          <div class="t-game-img-placeholder">
            <i class="fa fa-gamepad"></i>
            <span><?= e($game['genre']) ?></span>
          </div>
          <div class="t-game-featured">Featured</div>
        </div>
        <div class="t-game-body">
          <div class="t-game-genre"><?= e($game['genre']) ?></div>
          <div class="t-game-title"><?= e($game['title']) ?></div>
          <div class="t-game-desc"><?= e($game['description']) ?></div>
          <div class="t-game-footer">
            <div class="t-rating"><i class="fa fa-star"></i> <?= number_format($game['rating'],1) ?></div>
            <div class="t-game-platform"><?= e($game['platform']) ?></div>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Latest News -->
<?php if (!empty($tNews)): ?>
<div class="t-section" style="background:var(--t-bg2);border-top:1px solid var(--t-border);border-bottom:1px solid var(--t-border);">
  <div class="t-section-inner">
    <div class="t-section-header" style="display:flex;justify-content:space-between;align-items:flex-end;flex-wrap:wrap;gap:16px;">
      <div>
        <div class="t-section-tag">📰 Stay Updated</div>
        <h2 class="t-section-title">Latest News</h2>
      </div>
      <a href="?page=template&id=<?= $tId ?>&section=news" class="t-btn t-btn-outline">View All →</a>
    </div>
    <div class="t-news-grid">
      <?php foreach (array_slice($tNews, 0, 3) as $article): ?>
      <div class="t-news-card fade-in" onclick="window.location='?page=template&id=<?= $tId ?>&section=news&article=<?= $article['id'] ?>'">
        <div class="t-news-img">
          <i class="fa fa-newspaper"></i>
          <div class="t-news-img-gradient"></div>
          <div style="position:absolute;top:12px;left:12px;"><span class="t-status t-status-ongoing" style="font-size:0.65rem;"><?= e($article['category']) ?></span></div>
        </div>
        <div class="t-news-body">
          <div class="t-news-cat"><?= e($article['category']) ?></div>
          <div class="t-news-title"><?= e($article['title']) ?></div>
          <div class="t-news-excerpt"><?= e($article['excerpt']) ?></div>
          <div class="t-news-footer">
            <div class="t-news-author">
              <div class="t-news-avatar"><?= strtoupper(substr($article['username'] ?? 'G', 0, 1)) ?></div>
              <span><?= e($article['username'] ?? 'GameForge') ?></span>
            </div>
            <span><?= date('M j', strtotime($article['published_at'])) ?></span>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Top Setups -->
<?php if (!empty($tSetups)): ?>
<div class="t-section">
  <div class="t-section-inner">
    <div class="t-section-header" style="display:flex;justify-content:space-between;align-items:flex-end;flex-wrap:wrap;gap:16px;">
      <div>
        <div class="t-section-tag">🖥️ Community Rigs</div>
        <h2 class="t-section-title">Top Gaming Setups</h2>
      </div>
      <a href="?page=template&id=<?= $tId ?>&section=setup" class="t-btn t-btn-outline">View All Setups →</a>
    </div>
    <div class="t-setups-grid">
      <?php foreach (array_slice($tSetups, 0, 3) as $setup): ?>
      <div class="t-setup-card fade-in">
        <div class="t-setup-header">
          <div class="t-setup-avatar"><?= strtoupper(substr($setup['owner_name'] ?? 'U', 0, 1)) ?></div>
          <div>
            <div class="t-setup-name"><?= e($setup['setup_name']) ?></div>
            <div class="t-setup-owner">by <?= e($setup['owner_name']) ?></div>
          </div>
          <?php if ($setup['is_featured']): ?><span class="t-status t-status-ongoing" style="margin-left:auto;">⭐ Featured</span><?php endif; ?>
        </div>
        <div class="t-setup-body">
          <?php if ($setup['cpu']): ?><div class="t-spec-row"><span class="t-spec-label"><i class="fa fa-microchip"></i> CPU</span><span class="t-spec-value"><?= e($setup['cpu']) ?></span></div><?php endif; ?>
          <?php if ($setup['gpu']): ?><div class="t-spec-row"><span class="t-spec-label"><i class="fa fa-tv"></i> GPU</span><span class="t-spec-value"><?= e($setup['gpu']) ?></span></div><?php endif; ?>
          <?php if ($setup['ram']): ?><div class="t-spec-row"><span class="t-spec-label"><i class="fa fa-memory"></i> RAM</span><span class="t-spec-value"><?= e($setup['ram']) ?></span></div><?php endif; ?>
          <?php if ($setup['monitor']): ?><div class="t-spec-row"><span class="t-spec-label"><i class="fa fa-display"></i> Monitor</span><span class="t-spec-value"><?= e($setup['monitor']) ?></span></div><?php endif; ?>
        </div>
        <div class="t-setup-footer">
          <button class="t-likes" onclick="likeSetup(<?= $setup['id'] ?>, this)">
            <i class="fa fa-heart"></i>
            <span class="likes-count"><?= number_format($setup['likes_count']) ?></span> likes
          </button>
          <?php if ($setup['total_cost']): ?><div class="t-cost"><?= e($setup['total_cost']) ?></div><?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- CTA / Join Section -->
<div class="t-section" style="text-align:center;background:var(--t-hero-gradient);border-top:1px solid var(--t-border);">
  <div class="t-section-inner">
    <div style="font-size:0.75rem;font-weight:700;text-transform:uppercase;letter-spacing:2px;color:var(--t-primary);margin-bottom:12px;">Ready to Play?</div>
    <h2 style="font-family:var(--t-font);font-size:clamp(2rem,4vw,3rem);font-weight:900;margin-bottom:16px;color:var(--t-text);">
      <?= $tId===1 ? 'Join The Neon Network' : ($tId===2 ? 'ENTER THE ARENA' : ($tId===3 ? 'Begin Your Legend' : 'Open The Vault')) ?>
    </h2>
    <p style="color:var(--t-muted);font-size:1.05rem;max-width:480px;margin:0 auto 32px;">
      <?= $tId===1 ? 'Connect with thousands of gamers, track your progress, and build your digital empire in the neon-soaked future.' : ($tId===2 ? 'Sign up, assemble your squad, and compete in the most elite gaming tournaments on the planet.' : ($tId===3 ? 'Create your hero, explore vast realms, and forge an epic legacy in the world of MythQuest.' : 'Register for free and unlock the complete vault of gaming history, reviews, and community features.')) ?>
    </p>
    <div style="display:flex;gap:14px;justify-content:center;flex-wrap:wrap;">
      <?php if (!isLoggedIn()): ?>
      <a href="?page=register" class="t-btn t-btn-primary" style="font-size:1rem;padding:13px 28px;"><i class="fa fa-user-plus"></i> Create Account</a>
      <a href="?page=login" class="t-btn t-btn-outline" style="font-size:1rem;padding:12px 28px;"><i class="fa fa-sign-in-alt"></i> Sign In</a>
      <?php else: ?>
      <a href="?page=template&id=<?= $tId ?>&section=games" class="t-btn t-btn-primary" style="font-size:1rem;padding:13px 28px;"><i class="fa fa-gamepad"></i> Explore Games</a>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php
// ─────────────────────────────────────────────────────────────
// Section: GAMES
// ─────────────────────────────────────────────────────────────
elseif ($section === 'games'):
$genres = array_unique(array_column($tGames, 'genre'));
?>
<div class="t-section" style="min-height:80vh;">
  <div class="t-section-inner">
    <div class="t-section-header">
      <div class="t-section-tag"><i class="fa fa-gamepad"></i> Library</div>
      <h2 class="t-section-title">Games Catalog</h2>
      <p class="t-section-sub"><?= count($tGames) ?> games available — search, filter, and discover your next obsession.</p>
    </div>

    <!-- Search & Filter -->
    <div class="t-search-bar">
      <div class="t-search-input-wrap">
        <i class="fa fa-search"></i>
        <input type="text" class="t-search-input" id="gameSearch" placeholder="Search games by title, genre..." oninput="filterGames()">
      </div>
      <select class="t-filter-select" id="genreFilter" onchange="filterGames()">
        <option value="">All Genres</option>
        <?php foreach ($genres as $g): ?><option value="<?= e($g) ?>"><?= e($g) ?></option><?php endforeach; ?>
      </select>
      <select class="t-filter-select" id="sortFilter" onchange="filterGames()">
        <option value="featured">Featured First</option>
        <option value="rating">Highest Rated</option>
        <option value="newest">Newest</option>
        <option value="plays">Most Played</option>
      </select>
    </div>

    <!-- Games Grid -->
    <div class="t-games-grid" id="gamesGrid">
      <?php foreach ($tGames as $game): ?>
      <div class="t-game-card fade-in" data-title="<?= e(strtolower($game['title'])) ?>" data-genre="<?= e($game['genre']) ?>" data-featured="<?= $game['is_featured'] ?>" data-rating="<?= $game['rating'] ?>" data-plays="<?= $game['plays_count'] ?>" data-year="<?= $game['release_year'] ?>" onclick="showGameDetail(<?= $game['id'] ?>)">
        <div class="t-game-img" style="background:linear-gradient(135deg, var(--t-bg3), var(--t-bg2));">
          <div class="t-game-img-placeholder">
            <i class="fa fa-gamepad" style="color:var(--t-primary);opacity:0.4;font-size:3rem;"></i>
          </div>
          <?php if ($game['is_featured']): ?><div class="t-game-featured">Featured</div><?php endif; ?>
        </div>
        <div class="t-game-body">
          <div class="t-game-genre"><?= e($game['genre']) ?></div>
          <div class="t-game-title"><?= e($game['title']) ?></div>
          <div class="t-game-desc"><?= e($game['description']) ?></div>
          <div class="t-game-footer">
            <div class="t-rating"><i class="fa fa-star"></i> <?= number_format($game['rating'],1) ?></div>
            <div style="text-align:right;">
              <div class="t-game-platform" style="font-size:0.68rem;"><?= e($game['platform']) ?></div>
              <div style="font-size:0.7rem;color:var(--t-muted);margin-top:2px;"><?= number_format($game['plays_count']) ?> plays</div>
            </div>
          </div>
        </div>
        <?php if (isAdmin()): ?>
        <div style="padding:10px 18px 14px;border-top:1px solid var(--t-border);display:flex;gap:8px;" onclick="event.stopPropagation()">
          <button class="t-btn t-btn-outline" style="font-size:0.75rem;padding:5px 10px;" onclick="editGame(<?= $game['id'] ?>)"><i class="fa fa-edit"></i> Edit</button>
          <button class="t-btn" style="background:#EF4444;color:#fff;font-size:0.75rem;padding:5px 10px;" onclick="deleteGame(<?= $game['id'] ?>)"><i class="fa fa-trash"></i> Delete</button>
        </div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <div id="noGamesMsg" style="display:none;" class="empty-state"><i class="fa fa-search"></i><p>No games match your search.</p></div>
  </div>
</div>

<?php
// ─────────────────────────────────────────────────────────────
// Section: NEWS
// ─────────────────────────────────────────────────────────────
elseif ($section === 'news' && !$articleId):
?>
<div class="t-section" style="min-height:80vh;">
  <div class="t-section-inner">
    <div class="t-section-header">
      <div class="t-section-tag"><i class="fa fa-newspaper"></i> Blog</div>
      <h2 class="t-section-title">Gaming News & Updates</h2>
      <p class="t-section-sub">Stay up-to-date with the latest <?= e($tName) ?> news, patch notes, and community highlights.</p>
    </div>
    <?php if (empty($tNews)): ?>
    <div class="empty-state"><i class="fa fa-newspaper"></i><p>No articles published yet.</p></div>
    <?php else: ?>
    <div class="t-news-grid">
      <?php foreach ($tNews as $article): ?>
      <div class="t-news-card fade-in" onclick="window.location='?page=template&id=<?= $tId ?>&section=news&article=<?= $article['id'] ?>'">
        <div class="t-news-img">
          <i class="fa fa-newspaper" style="font-size:3rem;opacity:0.15;color:var(--t-primary);"></i>
          <div class="t-news-img-gradient"></div>
          <div style="position:absolute;top:12px;left:12px;"><span class="t-status t-status-ongoing" style="font-size:0.65rem;"><?= e($article['category']) ?></span></div>
          <div style="position:absolute;top:12px;right:12px;font-size:0.72rem;color:var(--t-muted);background:rgba(0,0,0,0.5);padding:3px 8px;border-radius:4px;"><i class="fa fa-eye"></i> <?= number_format($article['views']) ?></div>
        </div>
        <div class="t-news-body">
          <div class="t-news-cat"><?= e($article['category']) ?></div>
          <div class="t-news-title"><?= e($article['title']) ?></div>
          <div class="t-news-excerpt"><?= e($article['excerpt']) ?></div>
          <div class="t-news-footer">
            <div class="t-news-author">
              <div class="t-news-avatar"><?= strtoupper(substr($article['username'] ?? 'G', 0, 1)) ?></div>
              <span><?= e($article['username'] ?? 'GameForge') ?></span>
            </div>
            <span><?= date('M j, Y', strtotime($article['published_at'])) ?></span>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php
// Article detail
elseif ($section === 'news' && $articleId):
$article = row("SELECT n.*, u.username, u.full_name FROM news_articles n LEFT JOIN users u ON u.id=n.author_id WHERE n.id=? AND n.template_id=?", [$articleId, $tId], 'ii');
if ($article) {
  q("UPDATE news_articles SET views=views+1 WHERE id=?", [$articleId], 'i');
  $comments = rows("SELECT c.*, u.username FROM comments c JOIN users u ON u.id=c.user_id WHERE c.article_id=? AND c.is_approved=1 ORDER BY c.created_at DESC", [$articleId], 'i');
}
?>
<?php if ($article): ?>
<div class="t-article-hero" style="margin-top:0;padding-top:80px;">
  <div style="max-width:800px;margin:0 auto;">
    <a href="?page=template&id=<?= $tId ?>&section=news" style="font-size:0.85rem;color:var(--t-muted);display:inline-flex;align-items:center;gap:6px;margin-bottom:20px;"><i class="fa fa-arrow-left"></i> Back to News</a>
    <div style="font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--t-primary);margin-bottom:12px;"><?= e($article['category']) ?></div>
    <h1 style="font-family:var(--t-font);font-size:clamp(1.5rem,3vw,2.5rem);font-weight:800;color:var(--t-text);margin-bottom:16px;line-height:1.2;"><?= e($article['title']) ?></h1>
    <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;font-size:0.85rem;color:var(--t-muted);">
      <div style="display:flex;align-items:center;gap:8px;">
        <div class="t-news-avatar"><?= strtoupper(substr($article['username'] ?? 'G', 0, 1)) ?></div>
        <span><?= e($article['full_name'] ?: ($article['username'] ?? 'GameForge')) ?></span>
      </div>
      <span><i class="fa fa-calendar"></i> <?= date('F j, Y', strtotime($article['published_at'])) ?></span>
      <span><i class="fa fa-eye"></i> <?= number_format($article['views']) ?> views</span>
    </div>
  </div>
</div>
<div class="t-article-body">
  <p style="font-size:1.05rem;font-weight:600;color:var(--t-text);margin-bottom:24px;padding:20px;background:var(--t-bg2);border-left:3px solid var(--t-primary);border-radius:0 8px 8px 0;"><?= e($article['excerpt']) ?></p>
  <div class="t-article-content"><?= nl2br(e($article['content'])) ?></div>
</div>
<!-- Comments -->
<div class="t-comments">
  <h3 style="font-family:var(--t-font);font-size:1.2rem;font-weight:700;margin-bottom:24px;color:var(--t-text);">
    <i class="fa fa-comments" style="color:var(--t-primary);"></i> Comments (<?= count($comments) ?>)
  </h3>
  <?php if (isLoggedIn()): ?>
  <div style="margin-bottom:28px;" id="commentFormWrap">
    <div id="commentAlert" class="alert"></div>
    <textarea class="t-form-control" id="commentText" placeholder="Share your thoughts..." style="margin-bottom:10px;min-height:90px;"></textarea>
    <button class="t-btn t-btn-primary" onclick="submitComment(<?= $articleId ?>)"><i class="fa fa-paper-plane"></i> Post Comment</button>
  </div>
  <?php else: ?>
  <div style="background:var(--t-bg2);border:1px solid var(--t-border);border-radius:10px;padding:16px;margin-bottom:24px;font-size:0.9rem;color:var(--t-muted);">
    <a href="?page=login" style="color:var(--t-primary);font-weight:600;">Sign in</a> to leave a comment.
  </div>
  <?php endif; ?>
  <div id="commentsContainer">
    <?php if (empty($comments)): ?>
    <div class="empty-state" style="padding:30px 0;"><i class="fa fa-comments" style="font-size:2rem;opacity:0.15;"></i><p style="margin-top:10px;">No comments yet. Be the first!</p></div>
    <?php else: ?>
    <?php foreach ($comments as $c): ?>
    <div class="t-comment">
      <div class="t-comment-avatar"><?= strtoupper(substr($c['username'], 0, 1)) ?></div>
      <div class="t-comment-body">
        <div class="t-comment-name"><?= e($c['username']) ?></div>
        <div class="t-comment-text"><?= nl2br(e($c['content'])) ?></div>
        <div class="t-comment-date"><?= date('M j, Y H:i', strtotime($c['created_at'])) ?></div>
      </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>
<?php else: ?>
<div class="t-section"><div class="empty-state"><i class="fa fa-exclamation-circle"></i><p>Article not found.</p></div></div>
<?php endif; ?>

<?php
// ─────────────────────────────────────────────────────────────
// Section: SETUP
// ─────────────────────────────────────────────────────────────
elseif ($section === 'setup'):
?>
<div class="t-section" style="min-height:80vh;">
  <div class="t-section-inner">
    <div class="t-section-header" style="display:flex;justify-content:space-between;align-items:flex-end;flex-wrap:wrap;gap:16px;">
      <div>
        <div class="t-section-tag"><i class="fa fa-desktop"></i> Community</div>
        <h2 class="t-section-title">Gaming Setups</h2>
        <p class="t-section-sub">Browse community rigs, get inspired, and share your own battle station.</p>
      </div>
      <?php if (isLoggedIn()): ?>
      <button class="t-btn t-btn-primary" onclick="openModal('addSetupModal')"><i class="fa fa-plus"></i> Share My Setup</button>
      <?php else: ?>
      <a href="?page=login" class="t-btn t-btn-outline"><i class="fa fa-sign-in-alt"></i> Login to Share</a>
      <?php endif; ?>
    </div>
    <?php if (empty($tSetups)): ?>
    <div class="empty-state"><i class="fa fa-desktop"></i><p>No setups shared yet. Be the first!</p></div>
    <?php else: ?>
    <div class="t-setups-grid">
      <?php foreach ($tSetups as $s): ?>
      <div class="t-setup-card fade-in">
        <div class="t-setup-header">
          <div class="t-setup-avatar"><?= strtoupper(substr($s['owner_name'] ?? 'U', 0, 1)) ?></div>
          <div>
            <div class="t-setup-name"><?= e($s['setup_name']) ?></div>
            <div class="t-setup-owner">by <?= e($s['owner_name']) ?></div>
          </div>
          <?php if ($s['is_featured']): ?><span class="t-status t-status-ongoing" style="margin-left:auto;font-size:0.65rem;">⭐ Featured</span><?php endif; ?>
        </div>
        <div class="t-setup-body">
          <?php if ($s['cpu']): ?><div class="t-spec-row"><span class="t-spec-label"><i class="fa fa-microchip"></i> CPU</span><span class="t-spec-value"><?= e($s['cpu']) ?></span></div><?php endif; ?>
          <?php if ($s['gpu']): ?><div class="t-spec-row"><span class="t-spec-label"><i class="fa fa-tv"></i> GPU</span><span class="t-spec-value"><?= e($s['gpu']) ?></span></div><?php endif; ?>
          <?php if ($s['ram']): ?><div class="t-spec-row"><span class="t-spec-label"><i class="fa fa-memory"></i> RAM</span><span class="t-spec-value"><?= e($s['ram']) ?></span></div><?php endif; ?>
          <?php if ($s['storage']): ?><div class="t-spec-row"><span class="t-spec-label"><i class="fa fa-hard-drive"></i> Storage</span><span class="t-spec-value"><?= e($s['storage']) ?></span></div><?php endif; ?>
          <?php if ($s['monitor']): ?><div class="t-spec-row"><span class="t-spec-label"><i class="fa fa-display"></i> Monitor</span><span class="t-spec-value"><?= e($s['monitor']) ?></span></div><?php endif; ?>
          <?php if ($s['description']): ?><p style="font-size:0.82rem;color:var(--t-muted);margin-top:10px;line-height:1.5;"><?= e($s['description']) ?></p><?php endif; ?>
        </div>
        <div class="t-setup-footer">
          <button class="t-likes" onclick="likeSetup(<?= $s['id'] ?>, this)">
            <i class="fa fa-heart"></i> <span class="likes-count"><?= number_format($s['likes_count']) ?></span> likes
          </button>
          <div style="display:flex;gap:8px;align-items:center;">
            <?php if ($s['total_cost']): ?><div class="t-cost"><?= e($s['total_cost']) ?></div><?php endif; ?>
            <?php if (isAdmin() || (isLoggedIn() && $_SESSION['user_id'] == $s['user_id'])): ?>
            <button class="t-btn" style="background:#EF4444;color:#fff;font-size:0.72rem;padding:5px 10px;" onclick="deleteSetup(<?= $s['id'] ?>)"><i class="fa fa-trash"></i></button>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php
// ─────────────────────────────────────────────────────────────
// Section: TOURNAMENTS
// ─────────────────────────────────────────────────────────────
elseif ($section === 'tournaments'):
?>
<div class="t-section" style="min-height:80vh;">
  <div class="t-section-inner">
    <div class="t-section-header">
      <div class="t-section-tag"><i class="fa fa-trophy"></i> Compete</div>
      <h2 class="t-section-title">Tournaments</h2>
      <p class="t-section-sub">Join competitive tournaments, battle for prize pools, and prove your skills on the global stage.</p>
    </div>
    <?php if (empty($tTournaments)): ?>
    <div class="empty-state"><i class="fa fa-trophy"></i><p>No tournaments scheduled yet.</p></div>
    <?php else: ?>
    <div class="t-tournament-list">
      <?php foreach ($tTournaments as $tourney): ?>
      <div class="t-tournament-card fade-in">
        <div>
          <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">
            <div class="t-tournament-name"><?= e($tourney['name']) ?></div>
            <span class="t-status t-status-<?= $tourney['status'] ?>"><?= ucfirst($tourney['status']) ?></span>
          </div>
          <div class="t-tournament-meta">
            <span class="t-tournament-meta-item"><i class="fa fa-gamepad"></i> <?= e($tourney['game_title']) ?></span>
            <span class="t-tournament-meta-item"><i class="fa fa-users"></i> <?= $tourney['registered_teams'] ?>/<?= $tourney['max_teams'] ?> Teams</span>
            <?php if ($tourney['start_date']): ?><span class="t-tournament-meta-item"><i class="fa fa-calendar"></i> <?= date('M j, Y', strtotime($tourney['start_date'])) ?></span><?php endif; ?>
          </div>
          <?php if ($tourney['description']): ?><p style="font-size:0.85rem;color:var(--t-muted);margin-top:10px;max-width:600px;line-height:1.6;"><?= e($tourney['description']) ?></p><?php endif; ?>
          <div class="t-progress" style="margin-top:12px;max-width:400px;">
            <div class="t-progress-bar" style="width:<?= ($tourney['max_teams'] > 0 ? round(($tourney['registered_teams']/$tourney['max_teams'])*100) : 0) ?>%"></div>
          </div>
          <div style="font-size:0.75rem;color:var(--t-muted);margin-top:4px;"><?= $tourney['registered_teams'] ?> of <?= $tourney['max_teams'] ?> spots filled</div>
        </div>
        <div style="text-align:center;min-width:140px;">
          <div class="t-prize"><?= e($tourney['prize_pool']) ?></div>
          <div style="font-size:0.75rem;color:var(--t-muted);margin-bottom:14px;">Prize Pool</div>
          <?php if ($tourney['status'] === 'upcoming'): ?>
          <button class="t-btn t-btn-primary" style="width:100%;" onclick="<?= isLoggedIn() ? 'showToast(\'Registration coming soon!\', \'info\')' : 'window.location=\'?page=login\'' ?>"><i class="fa fa-sign-in-alt"></i> Register</button>
          <?php elseif ($tourney['status'] === 'ongoing'): ?>
          <button class="t-btn t-btn-outline" style="width:100%;" onclick="showToast('Tournament is live!', 'info')"><i class="fa fa-play"></i> Watch Live</button>
          <?php else: ?>
          <span class="t-status t-status-completed" style="display:inline-block;width:100%;text-align:center;padding:9px;">Concluded</span>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php
// ─────────────────────────────────────────────────────────────
// Section: CONTACT
// ─────────────────────────────────────────────────────────────
elseif ($section === 'contact'):
?>
<div class="t-section" style="min-height:80vh;">
  <div class="t-section-inner">
    <div class="t-section-header">
      <div class="t-section-tag"><i class="fa fa-envelope"></i> Get In Touch</div>
      <h2 class="t-section-title">Contact Us</h2>
      <p class="t-section-sub">Have questions or want to partner with <?= e($tName) ?>? We'd love to hear from you.</p>
    </div>
    <div class="t-contact-grid">
      <div class="t-contact-info">
        <h3>Let's Connect</h3>
        <p>Whether you have questions about our games, want to collaborate on a tournament, or just want to say hello — we're always happy to hear from the community.</p>
        <div class="t-contact-item"><i class="fa fa-envelope"></i> <span>hello@<?= strtolower(e($tName)) ?>.gg</span></div>
        <div class="t-contact-item"><i class="fab fa-discord"></i> <span>discord.gg/<?= strtolower(e($tName)) ?></span></div>
        <div class="t-contact-item"><i class="fab fa-twitter"></i> <span>@<?= strtolower(e($tName)) ?>gg</span></div>
        <div class="t-contact-item"><i class="fa fa-clock"></i> <span>Response within 24 hours</span></div>
        <div style="margin-top:32px;display:flex;gap:12px;">
          <a class="t-btn t-btn-primary" href="#"><i class="fab fa-discord"></i> Join Discord</a>
          <a class="t-btn t-btn-outline" href="#"><i class="fab fa-twitter"></i> Follow Us</a>
        </div>
      </div>
      <div class="t-contact-form">
        <div id="contactAlert" class="alert"></div>
        <div class="t-form-group">
          <label class="t-form-label">Your Name</label>
          <?php $contactUser = isLoggedIn() ? currentUser() : null; ?>
          <input type="text" class="t-form-control" id="cName" placeholder="Enter your name" value="<?= e($contactUser['full_name'] ?? $contactUser['username'] ?? '') ?>">
        </div>
        <div class="t-form-group">
          <label class="t-form-label">Email Address</label>
          <input type="email" class="t-form-control" id="cEmail" placeholder="your@email.com" value="<?= e($contactUser['email'] ?? '') ?>">
        </div>
        <div class="t-form-group">
          <label class="t-form-label">Subject</label>
          <input type="text" class="t-form-control" id="cSubject" placeholder="What's this about?">
        </div>
        <div class="t-form-group">
          <label class="t-form-label">Message</label>
          <textarea class="t-form-control" id="cMessage" placeholder="Tell us more..."></textarea>
        </div>
        <button class="t-btn t-btn-primary" style="width:100%;" onclick="submitContact()">
          <i class="fa fa-paper-plane"></i> Send Message
        </button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Template Footer -->
<footer class="t-footer">
  <div class="t-footer-inner">
    <div class="t-footer-top">
      <div class="t-logo" style="font-size:1.2rem;"><?= e($tName) ?></div>
      <div class="t-footer-links">
        <a href="?page=template&id=<?= $tId ?>&section=home">Home</a>
        <a href="?page=template&id=<?= $tId ?>&section=games">Games</a>
        <a href="?page=template&id=<?= $tId ?>&section=news">News</a>
        <a href="?page=template&id=<?= $tId ?>&section=setup">Setups</a>
        <a href="?page=template&id=<?= $tId ?>&section=contact">Contact</a>
      </div>
      <div style="display:flex;gap:10px;">
        <a href="#" style="width:36px;height:36px;border-radius:8px;background:rgba(255,255,255,0.05);border:1px solid var(--t-border);display:flex;align-items:center;justify-content:center;color:var(--t-muted);font-size:0.9rem;transition:all 0.25s;" onmouseover="this.style.color='var(--t-primary)'" onmouseout="this.style.color='var(--t-muted)'"><i class="fab fa-discord"></i></a>
        <a href="#" style="width:36px;height:36px;border-radius:8px;background:rgba(255,255,255,0.05);border:1px solid var(--t-border);display:flex;align-items:center;justify-content:center;color:var(--t-muted);font-size:0.9rem;transition:all 0.25s;" onmouseover="this.style.color='var(--t-primary)'" onmouseout="this.style.color='var(--t-muted)'"><i class="fab fa-twitter"></i></a>
        <a href="#" style="width:36px;height:36px;border-radius:8px;background:rgba(255,255,255,0.05);border:1px solid var(--t-border);display:flex;align-items:center;justify-content:center;color:var(--t-muted);font-size:0.9rem;transition:all 0.25s;" onmouseover="this.style.color='var(--t-primary)'" onmouseout="this.style.color='var(--t-muted)'"><i class="fab fa-twitch"></i></a>
        <a href="#" style="width:36px;height:36px;border-radius:8px;background:rgba(255,255,255,0.05);border:1px solid var(--t-border);display:flex;align-items:center;justify-content:center;color:var(--t-muted);font-size:0.9rem;transition:all 0.25s;" onmouseover="this.style.color='var(--t-primary)'" onmouseout="this.style.color='var(--t-muted)'"><i class="fab fa-youtube"></i></a>
      </div>
    </div>
    <div class="t-footer-bottom">
      <p>&copy; <?= date('Y') ?> <?= e($tName) ?>. Powered by <a href="<?= SITE_URL ?>" style="color:var(--t-primary);">GameForge</a>.</p>
      <div style="display:flex;gap:16px;font-size:0.82rem;">
        <a href="#" style="color:var(--t-muted);">Privacy Policy</a>
        <a href="#" style="color:var(--t-muted);">Terms of Service</a>
        <a href="<?= SITE_URL ?>" style="color:var(--t-primary);font-weight:600;">← Back to GameForge</a>
      </div>
    </div>
  </div>
</footer>

<!-- ── TEMPLATE MODALS ───────────────────────────────────────── -->
<!-- Game Detail Modal -->
<div class="modal-overlay" id="gameDetailModal">
  <div class="modal" style="max-width:600px;">
    <div class="modal-header">
      <span class="modal-title" id="gameModalTitle">Game Details</span>
      <button class="modal-close" onclick="closeModal('gameDetailModal')"><i class="fa fa-times"></i></button>
    </div>
    <div class="modal-body" id="gameModalBody"><div class="flex-center" style="padding:40px;"><div class="spinner" style="width:30px;height:30px;border-color:rgba(255,255,255,0.2);border-top-color:var(--t-primary);"></div></div></div>
  </div>
</div>

<!-- Add Game Modal (Admin) -->
<?php if (isAdmin()): ?>
<div class="modal-overlay" id="addGameModal">
  <div class="modal">
    <div class="modal-header">
      <span class="modal-title">Add New Game</span>
      <button class="modal-close" onclick="closeModal('addGameModal')"><i class="fa fa-times"></i></button>
    </div>
    <div class="modal-body">
      <div id="addGameAlert" class="alert"></div>
      <input type="hidden" id="agTemplateId" value="<?= $tId ?>">
      <input type="hidden" id="agId" value="">
      <div class="t-form-group"><label class="t-form-label">Game Title *</label><input type="text" class="t-form-control" id="agTitle" placeholder="e.g. Cyberstrike 2077"></div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <div class="t-form-group"><label class="t-form-label">Genre</label><input type="text" class="t-form-control" id="agGenre" placeholder="e.g. Action RPG"></div>
        <div class="t-form-group"><label class="t-form-label">Rating (0-10)</label><input type="number" class="t-form-control" id="agRating" min="0" max="10" step="0.1" placeholder="9.4"></div>
      </div>
      <div class="t-form-group"><label class="t-form-label">Platform</label><input type="text" class="t-form-control" id="agPlatform" placeholder="e.g. PC, PS5, Xbox"></div>
      <div class="t-form-group"><label class="t-form-label">Description</label><textarea class="t-form-control" id="agDesc" placeholder="Game description..."></textarea></div>
      <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:0.9rem;color:var(--t-muted);"><input type="checkbox" id="agFeatured"> Mark as Featured</label>
    </div>
    <div class="modal-footer">
      <button class="t-btn t-btn-outline" onclick="closeModal('addGameModal')">Cancel</button>
      <button class="t-btn t-btn-primary" id="saveGameBtn" onclick="saveGame()"><i class="fa fa-save"></i> Save Game</button>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Add Setup Modal -->
<div class="modal-overlay" id="addSetupModal">
  <div class="modal">
    <div class="modal-header">
      <span class="modal-title">Share Your Gaming Setup</span>
      <button class="modal-close" onclick="closeModal('addSetupModal')"><i class="fa fa-times"></i></button>
    </div>
    <div class="modal-body">
      <div id="addSetupAlert" class="alert"></div>
      <input type="hidden" id="asTemplateId" value="<?= $tId ?>">
      <div class="t-form-group"><label class="t-form-label">Setup Name *</label><input type="text" class="t-form-control" id="asName" placeholder="e.g. The Ultimate Battle Station"></div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <div class="t-form-group"><label class="t-form-label">CPU</label><input type="text" class="t-form-control" id="asCpu" placeholder="e.g. Intel i9-14900K"></div>
        <div class="t-form-group"><label class="t-form-label">GPU</label><input type="text" class="t-form-control" id="asGpu" placeholder="e.g. RTX 4090"></div>
        <div class="t-form-group"><label class="t-form-label">RAM</label><input type="text" class="t-form-control" id="asRam" placeholder="e.g. 64GB DDR5"></div>
        <div class="t-form-group"><label class="t-form-label">Storage</label><input type="text" class="t-form-control" id="asStorage" placeholder="e.g. 4TB NVMe"></div>
      </div>
      <div class="t-form-group"><label class="t-form-label">Monitor</label><input type="text" class="t-form-control" id="asMonitor" placeholder="e.g. LG OLED 34 144Hz"></div>
      <div class="t-form-group"><label class="t-form-label">Total Cost</label><input type="text" class="t-form-control" id="asCost" placeholder="e.g. $4,500"></div>
      <div class="t-form-group"><label class="t-form-label">Description</label><textarea class="t-form-control" id="asDesc" placeholder="Tell us about your setup..."></textarea></div>
    </div>
    <div class="modal-footer">
      <button class="t-btn t-btn-outline" onclick="closeModal('addSetupModal')">Cancel</button>
      <button class="t-btn t-btn-primary" onclick="submitSetup()"><i class="fa fa-paper-plane"></i> Share Setup</button>
    </div>
  </div>
</div>

<?php else: ?>
<?php
// ─────────────────────────────────────────────────────────────
// MAIN SITE PAGES
// ─────────────────────────────────────────────────────────────
?>

<!-- Main Site Navbar -->
<nav class="main-nav">
  <div class="nav-inner">
    <a href="<?= SITE_URL ?>" class="main-logo">Game<span>Forge</span></a>
    <div class="nav-links" id="navLinks">
      <a href="<?= SITE_URL ?>" class="<?= $page==='home'?'active':'' ?>">Home</a>
      <a href="?page=templates">Templates</a>
      <a href="?page=features">Features</a>
      <a href="?page=home#howItWorks">How It Works</a>
    </div>
    <div class="nav-actions">
      <?php if (isLoggedIn()): $user = currentUser(); ?>
        <div class="nav-user" onclick="toggleDropdown(event)">
          <div class="nav-avatar">
            <?php if (!empty($user['avatar'])): ?>
            <img src="<?= e($user['avatar']) ?>" alt="">
            <?php else: ?>
            <?= strtoupper(substr($user['username'] ?? '', 0, 1)) ?>
            <?php endif; ?>
          </div>
          <span style="font-size:0.9rem;font-weight:600;"><?= e($user['username'] ?? '') ?></span>
          <i class="fa fa-chevron-down" style="font-size:0.7rem;color:var(--main-muted);"></i>
          <div class="dropdown" id="userDropdown">
            <a href="?page=profile"><i class="fa fa-user"></i> Profile</a>
            <a href="?page=settings"><i class="fa fa-gear"></i> Settings</a>
            <?php if (isAdmin()): ?>
            <div class="dropdown-divider"></div>
            <a href="?page=admin"><i class="fa fa-shield-halved" style="color:#F59E0B;"></i> Admin Panel</a>
            <?php endif; ?>
            <div class="dropdown-divider"></div>
            <button onclick="logoutUser()"><i class="fa fa-sign-out-alt"></i> Logout</button>
          </div>
        </div>
      <?php else: ?>
        <a href="?page=login" class="btn btn-outline btn-sm">Sign In</a>
        <a href="?page=register" class="btn btn-primary btn-sm">Get Started</a>
      <?php endif; ?>
    </div>
    <div class="hamburger" onclick="toggleNav()" id="hamburger">
      <span></span><span></span><span></span>
    </div>
  </div>
</nav>

<?php
// ─────────────────────────────────────────────────────────────
// Page: HOME
// ─────────────────────────────────────────────────────────────
if ($page === 'home' || $page === 'templates'):
?>

<!-- Hero Section -->
<?php if ($page === 'home'): ?>
<section class="hero">
  <div class="hero-bg"></div>
  <div class="hero-grid"></div>
  <div class="hero-inner">
    <div>
      <div class="hero-badge"><i class="fa fa-bolt"></i> Professional Gaming Templates</div>
      <h1 class="hero-title">
        Build Your<br>
        <span class="gradient-text">Gaming Empire</span><br>
        Today
      </h1>
      <p class="hero-sub">Choose from stunning professional templates, customize every pixel, and launch your gaming website in minutes — no coding required.</p>
      <div class="hero-actions">
        <a href="?page=home#templates" class="btn-hero"><i class="fa fa-rocket"></i> Explore Templates</a>
        <a href="?page=register" class="btn-hero-outline"><i class="fa fa-user-plus"></i> Start Free</a>
      </div>
      <div class="hero-stats">
        <div><div class="hero-stat-num">4+</div><div class="hero-stat-label">Premium Templates</div></div>
        <div><div class="hero-stat-num">100%</div><div class="hero-stat-label">Fully Functional</div></div>
        <div><div class="hero-stat-num">∞</div><div class="hero-stat-label">Customizable</div></div>
      </div>
    </div>
    <div>
      <div class="hero-preview" id="heroPreview">
        <?php foreach ($templates as $i => $tpl): $tid = $tpl['id']; $th = $themes[$tid] ?? $themes[1]; ?>
        <div class="preview-mini" onclick="window.location='?page=template&id=<?= $tid ?>'" title="<?= e($tpl['name']) ?>">
          <div class="preview-mini-header" style="background:<?= $th['gradient'] ?>;"></div>
          <div class="preview-mini-body">
            <div class="preview-mini-dots">
              <div class="preview-mini-dot" style="background:#EF4444;"></div>
              <div class="preview-mini-dot" style="background:#F59E0B;"></div>
              <div class="preview-mini-dot" style="background:#10B981;"></div>
            </div>
            <div class="preview-mini-title" style="color:<?= $th['primary'] ?>;"><?= e($tpl['name']) ?></div>
            <div class="preview-mini-sub">Gaming Template</div>
            <div class="preview-mini-bars">
              <div class="preview-mini-bar" style="background:<?= $th['primary'] ?>;width:80%;"></div>
              <div class="preview-mini-bar" style="background:<?= $th['primary'] ?>;width:60%;"></div>
              <div class="preview-mini-bar" style="background:<?= $th['primary'] ?>;width:40%;"></div>
            </div>
          </div>
          <div class="preview-mini-tag" style="background:<?= $th['primary'] ?>;color:#fff;"><?= e($tpl['preview_tag']) ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- Templates Section -->
<section class="section" id="templates" style="<?= $page==='templates'?'padding-top:120px;':'' ?>">
  <div class="section-inner">
    <div class="section-header">
      <div class="section-tag">✦ Choose Your Style</div>
      <h2 class="section-title">Gaming Website Templates</h2>
      <p class="section-sub">Four professionally designed templates, each with its own unique aesthetic, fully working pages, games library, news, setups, and more.</p>
    </div>
    <div class="templates-grid">
      <?php foreach ($templates as $tpl):
        $tid = $tpl['id']; $th = $themes[$tid] ?? $themes[1];
        $gCount = row("SELECT COUNT(*) as c FROM games WHERE template_id=?", [$tid], 'i')['c'] ?? 0;
        $nCount = row("SELECT COUNT(*) as c FROM news_articles WHERE template_id=?", [$tid], 'i')['c'] ?? 0;
      ?>
      <div class="template-card" onclick="window.location='?page=template&id=<?= $tid ?>'" style="border-color:<?= $th['border'] ?>;">
        <!-- Preview screen simulation -->
        <div class="template-card-preview">
          <div class="template-card-screen" style="background:<?= $th['bg'] ?>;">
            <div class="tc-nav" style="background:<?= $th['bg2'] ?>;">
              <div class="tc-nav-dot" style="background:<?= $th['primary'] ?>;"></div>
              <div class="tc-nav-dot" style="background:<?= $th['secondary'] ?>;width:4px;height:4px;"></div>
              <div class="tc-nav-text" style="background:<?= $th['border'] ?>;"></div>
            </div>
            <div class="tc-hero" style="background:<?= $th['hero_gradient'] ?>;">
              <div class="tc-hero-text">
                <div class="tc-hero-h" style="background:<?= $th['primary'] ?>;"></div>
                <div class="tc-hero-h2" style="background:<?= $th['muted'] ?>;"></div>
              </div>
              <div class="tc-hero-btn" style="background:<?= $th['gradient'] ?>;"></div>
            </div>
            <div class="tc-cards">
              <div class="tc-card" style="background:<?= $th['bg2'] ?>;border:1px solid <?= $th['border'] ?>;"></div>
              <div class="tc-card" style="background:<?= $th['bg2'] ?>;border:1px solid <?= $th['border'] ?>;"></div>
              <div class="tc-card" style="background:<?= $th['bg2'] ?>;border:1px solid <?= $th['border'] ?>;"></div>
            </div>
          </div>
          <div class="template-card-overlay"><i class="fa fa-eye" style="margin-right:8px;"></i> Preview Template</div>
        </div>
        <div class="template-card-info">
          <div class="template-card-badge" style="background:<?= $th['primary'] ?>22;color:<?= $th['primary'] ?>;border:1px solid <?= $th['primary'] ?>44;">
            <?= e($tpl['preview_tag']) ?>
          </div>
          <div class="template-card-name"><?= e($tpl['name']) ?></div>
          <div class="template-card-desc"><?= e($tpl['description']) ?></div>
          <div class="template-card-footer">
            <div class="template-card-tags">
              <span class="template-tag"><i class="fa fa-gamepad" style="font-size:0.6rem;"></i> <?= $gCount ?> Games</span>
              <span class="template-tag"><i class="fa fa-newspaper" style="font-size:0.6rem;"></i> <?= $nCount ?> Articles</span>
            </div>
            <button class="btn-preview" style="background:<?= $th['gradient'] ?>;color:#fff;" onclick="event.stopPropagation();window.location='?page=template&id=<?= $tid ?>'">
              View <i class="fa fa-arrow-right"></i>
            </button>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<?php if ($page === 'home'): ?>
<!-- Features Section -->
<section class="section" style="background:var(--main-bg2);border-top:1px solid var(--main-border);border-bottom:1px solid var(--main-border);">
  <div class="section-inner">
    <div class="section-header">
      <div class="section-tag">⚡ Everything Included</div>
      <h2 class="section-title">Platform Features</h2>
      <p class="section-sub">Every template ships with a complete feature set — no extra plugins or subscriptions needed.</p>
    </div>
    <div class="features-grid">
      <div class="feature-card"><div class="feature-icon"><i class="fa fa-gamepad"></i></div><div class="feature-title">Games Catalog</div><div class="feature-desc">Full games library with search, genre filters, ratings, and detailed game pages. Admin CRUD for managing the catalog.</div></div>
      <div class="feature-card"><div class="feature-icon"><i class="fa fa-newspaper"></i></div><div class="feature-title">News & Articles</div><div class="feature-desc">Complete blogging system with categories, author profiles, views tracking, and full-article detail pages with comments.</div></div>
      <div class="feature-card"><div class="feature-icon"><i class="fa fa-desktop"></i></div><div class="feature-title">Gaming Setups</div><div class="feature-desc">Community gaming rig showcase. Users can submit their own setups, complete with specs, cost, and like system.</div></div>
      <div class="feature-card"><div class="feature-icon"><i class="fa fa-trophy"></i></div><div class="feature-title">Tournaments</div><div class="feature-desc">Tournament management with registration tracking, prize pools, status tracking, and team capacity management.</div></div>
      <div class="feature-card"><div class="feature-icon"><i class="fa fa-user-shield"></i></div><div class="feature-title">Auth & Profiles</div><div class="feature-desc">Complete authentication system with login, registration, session management, profile editing, and avatar support.</div></div>
      <div class="feature-card"><div class="feature-icon"><i class="fa fa-comments"></i></div><div class="feature-title">Comments System</div><div class="feature-desc">Full commenting system on news articles. Users can post, view, and interact with the community in real time.</div></div>
      <div class="feature-card"><div class="feature-icon"><i class="fa fa-gear"></i></div><div class="feature-title">Settings Panel</div><div class="feature-desc">User settings dashboard with notification preferences, privacy controls, password management, and profile updates.</div></div>
      <div class="feature-card"><div class="feature-icon"><i class="fa fa-mobile-screen"></i></div><div class="feature-title">Fully Responsive</div><div class="feature-desc">Pixel-perfect on mobile, tablet, and desktop. Built with a mobile-first approach and tested across all breakpoints.</div></div>
    </div>
  </div>
</section>

<!-- How It Works -->
<section class="section" id="howItWorks">
  <div class="section-inner">
    <div class="section-header">
      <div class="section-tag">📋 Simple Process</div>
      <h2 class="section-title">How It Works</h2>
      <p class="section-sub">From zero to live gaming website in three simple steps.</p>
    </div>
    <div class="steps-grid">
      <div class="step-card">
        <div class="step-num">01</div>
        <div class="step-title">Choose Your Template</div>
        <div class="step-desc">Browse our curated collection of gaming templates — from cyberpunk neon to classic retro. Click any template to preview it live with real data and all features working.</div>
      </div>
      <div class="step-card">
        <div class="step-num">02</div>
        <div class="step-title">Deploy & Configure</div>
        <div class="step-desc">Download the source files, set up your MySQL database using the included SQL schema, and you're live. All features work out of the box — games, news, setups, auth, and more.</div>
      </div>
      <div class="step-card">
        <div class="step-num">03</div>
        <div class="step-title">Grow Your Community</div>
        <div class="step-desc">Your gaming website is live. Add games, publish news, manage tournaments, and watch your community grow. The admin panel gives you full control without touching code.</div>
      </div>
    </div>
  </div>
</section>

<!-- CTA Section -->
<section class="cta-section">
  <div class="section-inner">
    <h2 class="cta-title">Ready to Launch Your<br><span style="background:linear-gradient(135deg,#A78BFA,#67E8F9);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;">Gaming Website?</span></h2>
    <p class="cta-sub">Join thousands of gaming communities already built on GameForge templates. Start free today.</p>
    <div style="display:flex;gap:16px;justify-content:center;flex-wrap:wrap;">
      <a href="?page=register" class="btn-hero"><i class="fa fa-rocket"></i> Get Started Free</a>
      <a href="?page=home#templates" class="btn-hero-outline"><i class="fa fa-eye"></i> Browse Templates</a>
    </div>
  </div>
</section>

<?php endif; ?>

<?php
// ─────────────────────────────────────────────────────────────
// Page: LOGIN
// ─────────────────────────────────────────────────────────────
elseif ($page === 'login'):
  if (isLoggedIn()) redirect(SITE_URL . '?page=profile');
?>
<div class="auth-page">
  <div class="auth-card">
    <div class="auth-logo"><a href="<?= SITE_URL ?>" class="main-logo">Game<span>Forge</span></a></div>
    <div class="auth-title">Welcome Back</div>
    <div class="auth-sub">Sign in to your GameForge account</div>
    <div id="loginAlert" class="alert alert-error"></div>
    <div class="form-group">
      <label class="form-label">Email Address</label>
      <div class="form-input-icon"><i class="fa fa-envelope"></i>
        <input type="email" id="loginEmail" class="form-control" placeholder="your@email.com" onkeydown="if(event.key==='Enter')doLogin()">
      </div>
    </div>
    <div class="form-group">
      <label class="form-label">Password</label>
      <div class="form-input-icon"><i class="fa fa-lock"></i>
        <input type="password" id="loginPassword" class="form-control" placeholder="••••••••" onkeydown="if(event.key==='Enter')doLogin()">
      </div>
    </div>
    <button class="btn btn-primary btn-block mt-4" id="loginBtn" onclick="doLogin()">
      <i class="fa fa-sign-in-alt"></i> Sign In
    </button>
    <div class="auth-switch">Don't have an account? <a href="?page=register">Create one free</a></div>
    <div style="margin-top:20px;padding:16px;background:var(--main-bg3);border-radius:10px;border:1px solid var(--main-border);">
      <div style="font-size:0.8rem;color:var(--main-muted);font-weight:600;margin-bottom:8px;"><i class="fa fa-info-circle"></i> Demo Accounts</div>
      <div style="font-size:0.8rem;color:var(--main-muted);">Email: <span style="color:var(--main-text);">admin@gamingbuilder.com</span></div>
      <div style="font-size:0.8rem;color:var(--main-muted);">Password: <span style="color:var(--main-text);">password</span></div>
    </div>
  </div>
</div>

<?php
// ─────────────────────────────────────────────────────────────
// Page: REGISTER
// ─────────────────────────────────────────────────────────────
elseif ($page === 'register'):
  if (isLoggedIn()) redirect(SITE_URL . '?page=profile');
?>
<div class="auth-page">
  <div class="auth-card">
    <div class="auth-logo"><a href="<?= SITE_URL ?>" class="main-logo">Game<span>Forge</span></a></div>
    <div class="auth-title">Create Account</div>
    <div class="auth-sub">Start building your gaming community for free</div>
    <div id="registerAlert" class="alert"></div>
    <div class="form-group">
      <label class="form-label">Full Name</label>
      <div class="form-input-icon"><i class="fa fa-id-card"></i>
        <input type="text" id="regFullname" class="form-control" placeholder="Your full name">
      </div>
    </div>
    <div class="form-group">
      <label class="form-label">Username</label>
      <div class="form-input-icon"><i class="fa fa-at"></i>
        <input type="text" id="regUsername" class="form-control" placeholder="Choose a username">
      </div>
    </div>
    <div class="form-group">
      <label class="form-label">Email Address</label>
      <div class="form-input-icon"><i class="fa fa-envelope"></i>
        <input type="email" id="regEmail" class="form-control" placeholder="your@email.com">
      </div>
    </div>
    <div class="form-group">
      <label class="form-label">Password</label>
      <div class="form-input-icon"><i class="fa fa-lock"></i>
        <input type="password" id="regPassword" class="form-control" placeholder="Min. 6 characters">
      </div>
    </div>
    <button class="btn btn-primary btn-block mt-4" id="registerBtn" onclick="doRegister()">
      <i class="fa fa-user-plus"></i> Create Account
    </button>
    <div class="auth-switch">Already have an account? <a href="?page=login">Sign in</a></div>
  </div>
</div>

<?php
// ─────────────────────────────────────────────────────────────
// Page: PROFILE
// ─────────────────────────────────────────────────────────────
elseif ($page === 'profile'):
  if (!isLoggedIn()) redirect(SITE_URL . '?page=login');
  $user = currentUser();
  $settings = row("SELECT * FROM user_settings WHERE user_id=?", [$user['id']], 'i') ?? [];
  $userSetups = rows("SELECT gs.*, t.name as template_name FROM gaming_setups gs JOIN templates t ON t.id=gs.template_id WHERE gs.user_id=? ORDER BY gs.created_at DESC", [$user['id']], 'i');
?>
<div class="profile-page">
  <div class="profile-inner">
    <!-- Profile Header -->
    <div class="profile-header">
      <div class="profile-avatar-big">
        <?php if (!empty($user['avatar'])): ?>
        <img src="<?= e($user['avatar']) ?>" alt="<?= e($user['username']) ?>">
        <?php else: ?>
        <?= strtoupper(substr($user['username'], 0, 1)) ?>
        <?php endif; ?>
      </div>
      <div style="flex:1;">
        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:4px;">
          <h1 style="font-family:var(--font-head);font-size:1.8rem;font-weight:800;"><?= e($user['full_name'] ?: $user['username']) ?></h1>
          <?php if (isAdmin()): ?><span class="badge badge-admin"><i class="fa fa-shield-halved"></i> Admin</span><?php else: ?><span class="badge badge-user"><i class="fa fa-user"></i> Member</span><?php endif; ?>
        </div>
        <div class="profile-info" style="color:var(--main-muted);margin-bottom:8px;">@<?= e($user['username']) ?> · <?= e($user['email']) ?></div>
        <?php if ($user['bio']): ?><p style="font-size:0.95rem;color:var(--main-text);max-width:500px;"><?= e($user['bio']) ?></p><?php endif; ?>
        <div style="font-size:0.82rem;color:var(--main-muted);margin-top:8px;"><i class="fa fa-calendar"></i> Joined <?= date('F Y', strtotime($user['created_at'])) ?></div>
      </div>
    </div>

    <!-- Tabs -->
    <div class="tabs">
      <button class="tab-btn active" onclick="switchTab('profileEdit', this)"><i class="fa fa-user"></i> Edit Profile</button>
      <button class="tab-btn" onclick="switchTab('security', this)"><i class="fa fa-lock"></i> Security</button>
      <button class="tab-btn" onclick="switchTab('mySetups', this)"><i class="fa fa-desktop"></i> My Setups (<?= count($userSetups) ?>)</button>
    </div>

    <!-- Profile Edit Tab -->
    <div class="tab-panel active" id="profileEditTab">
      <div class="settings-card">
        <h3><i class="fa fa-user"></i> Personal Information</h3>
        <div id="profileAlert" class="alert" style="margin-bottom:16px;"></div>
        <div class="form-grid">
          <div class="form-group">
            <label class="form-label">Full Name</label>
            <input type="text" id="pfFullName" class="form-control" value="<?= e($user['full_name']) ?>" placeholder="Your full name">
          </div>
          <div class="form-group">
            <label class="form-label">Username (read-only)</label>
            <input type="text" class="form-control" value="<?= e($user['username']) ?>" disabled style="opacity:0.6;">
          </div>
          <div class="form-group form-grid-full">
            <label class="form-label">Bio</label>
            <textarea class="form-control" id="pfBio" placeholder="Tell the community about yourself..."><?= e($user['bio']) ?></textarea>
          </div>
          <div class="form-group form-grid-full">
            <label class="form-label">Avatar URL</label>
            <input type="url" id="pfAvatar" class="form-control" value="<?= e($user['avatar']) ?>" placeholder="https://example.com/avatar.jpg">
          </div>
        </div>
        <button class="btn btn-primary" onclick="saveProfile()"><i class="fa fa-save"></i> Save Changes</button>
      </div>
    </div>

    <!-- Security Tab -->
    <div class="tab-panel" id="securityTab">
      <div class="settings-card">
        <h3><i class="fa fa-lock"></i> Change Password</h3>
        <div id="passwordAlert" class="alert" style="margin-bottom:16px;"></div>
        <div class="form-group"><label class="form-label">Current Password</label><input type="password" id="curPw" class="form-control" placeholder="Enter current password"></div>
        <div class="form-group"><label class="form-label">New Password</label><input type="password" id="newPw" class="form-control" placeholder="Min. 6 characters"></div>
        <div class="form-group"><label class="form-label">Confirm New Password</label><input type="password" id="confPw" class="form-control" placeholder="Repeat new password"></div>
        <button class="btn btn-primary" onclick="changePassword()"><i class="fa fa-key"></i> Update Password</button>
      </div>
    </div>

    <!-- My Setups Tab -->
    <div class="tab-panel" id="mySetupsTab">
      <?php if (empty($userSetups)): ?>
      <div class="empty-state"><i class="fa fa-desktop"></i><p>You haven't shared any gaming setups yet. Explore a template and share your rig!</p></div>
      <?php else: ?>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:20px;">
        <?php foreach ($userSetups as $s): ?>
        <div class="settings-card" style="position:relative;">
          <div style="font-weight:700;margin-bottom:4px;"><?= e($s['setup_name']) ?></div>
          <div style="font-size:0.82rem;color:var(--main-muted);margin-bottom:12px;">in <?= e($s['template_name']) ?></div>
          <?php if ($s['cpu']): ?><div style="font-size:0.85rem;color:var(--main-muted);margin-bottom:4px;"><strong>CPU:</strong> <?= e($s['cpu']) ?></div><?php endif; ?>
          <?php if ($s['gpu']): ?><div style="font-size:0.85rem;color:var(--main-muted);margin-bottom:4px;"><strong>GPU:</strong> <?= e($s['gpu']) ?></div><?php endif; ?>
          <div style="display:flex;justify-content:space-between;align-items:center;margin-top:14px;">
            <span style="font-size:0.82rem;color:var(--main-muted);"><i class="fa fa-heart" style="color:#EF4444;"></i> <?= number_format($s['likes_count']) ?> likes</span>
            <button class="btn btn-danger btn-sm" onclick="deleteMySetup(<?= $s['id'] ?>)"><i class="fa fa-trash"></i> Delete</button>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php
// ─────────────────────────────────────────────────────────────
// Page: SETTINGS
// ─────────────────────────────────────────────────────────────
elseif ($page === 'settings'):
  if (!isLoggedIn()) redirect(SITE_URL . '?page=login');
  $user = currentUser();
  $settings = row("SELECT * FROM user_settings WHERE user_id=?", [$user['id']], 'i') ?? [];
?>
<div class="profile-page">
  <div class="profile-inner">
    <div style="margin-bottom:28px;">
      <div style="font-size:0.75rem;font-weight:700;text-transform:uppercase;letter-spacing:2px;color:var(--main-muted);margin-bottom:6px;">Account</div>
      <h1 class="section-title" style="font-size:1.8rem;margin-bottom:0;">Settings</h1>
    </div>
    <div id="settingsAlert" class="alert" style="margin-bottom:16px;"></div>

    <div class="settings-card">
      <h3><i class="fa fa-bell"></i> Notifications</h3>
      <div class="toggle-switch">
        <div class="toggle-label"><h4>Email Notifications</h4><p>Receive emails about comments, replies, and activity.</p></div>
        <label class="toggle-input"><input type="checkbox" id="emailNotif" <?= !empty($settings['email_notifications']) ? 'checked' : '' ?>><span class="toggle-slider"></span></label>
      </div>
      <div class="toggle-switch">
        <div class="toggle-label"><h4>Newsletter</h4><p>Weekly digest of the best gaming news and updates.</p></div>
        <label class="toggle-input"><input type="checkbox" id="newsletter" <?= !empty($settings['newsletter']) ? 'checked' : '' ?>><span class="toggle-slider"></span></label>
      </div>
    </div>

    <div class="settings-card">
      <h3><i class="fa fa-palette"></i> Appearance & Language</h3>
      <div class="toggle-switch">
        <div class="toggle-label"><h4>Dark Mode</h4><p>Use dark theme across the platform (recommended).</p></div>
        <label class="toggle-input"><input type="checkbox" id="darkMode" <?= !empty($settings['dark_mode']) ? 'checked' : '' ?>><span class="toggle-slider"></span></label>
      </div>
      <div class="form-group" style="margin-top:16px;">
        <label class="form-label">Language</label>
        <select class="form-control" id="language" style="max-width:280px;">
          <option value="en" <?= ($settings['language']??'en')==='en'?'selected':'' ?>>English</option>
          <option value="es" <?= ($settings['language']??'')==='es'?'selected':'' ?>>Español</option>
          <option value="fr" <?= ($settings['language']??'')==='fr'?'selected':'' ?>>Français</option>
          <option value="de" <?= ($settings['language']??'')==='de'?'selected':'' ?>>Deutsch</option>
          <option value="pt" <?= ($settings['language']??'')==='pt'?'selected':'' ?>>Português</option>
        </select>
      </div>
    </div>

    <div class="settings-card">
      <h3><i class="fa fa-shield-halved"></i> Privacy</h3>
      <div class="form-group">
        <label class="form-label">Profile Visibility</label>
        <select class="form-control" id="privacy" style="max-width:280px;">
          <option value="public" <?= ($settings['privacy']??'public')==='public'?'selected':'' ?>>Public — Anyone can view</option>
          <option value="friends" <?= ($settings['privacy']??'')==='friends'?'selected':'' ?>>Friends Only</option>
          <option value="private" <?= ($settings['privacy']??'')==='private'?'selected':'' ?>>Private — Only me</option>
        </select>
      </div>
    </div>

    <div style="display:flex;gap:12px;">
      <button class="btn btn-primary" onclick="saveSettings()"><i class="fa fa-save"></i> Save Settings</button>
      <a href="?page=profile" class="btn btn-outline">← Back to Profile</a>
    </div>
  </div>
</div>

<?php
// Page: FEATURES
elseif ($page === 'features'):
?>
<div class="features-page">
  <div class="features-inner">
    <h1 style="font-family:var(--font-head);font-size:2.2rem;margin-bottom:12px;">Platform Features</h1>
    <p style="color:var(--main-muted);max-width:800px;margin-bottom:24px;">GameForge provides a complete set of tools for building and launching your gaming community quickly.</p>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:18px;">
      <div class="feature-card">
        <div class="feature-icon"><i class="fa fa-user-shield"></i></div>
        <div class="feature-title">Auth & Profiles</div>
        <div class="feature-desc">Login, registration, session management, profile editing and avatar support.</div>
      </div>
      <div class="feature-card">
        <div class="feature-icon"><i class="fa fa-th-list"></i></div>
        <div class="feature-title">Templates</div>
        <div class="feature-desc">Multiple professional templates you can customize and launch quickly.</div>
      </div>
      <div class="feature-card">
        <div class="feature-icon"><i class="fa fa-gamepad"></i></div>
        <div class="feature-title">Game Database</div>
        <div class="feature-desc">Add and manage games, ratings, filters and featured content.</div>
      </div>
      <div class="feature-card">
        <div class="feature-icon"><i class="fa fa-comments"></i></div>
        <div class="feature-title">Community</div>
        <div class="feature-desc">User comments, likes, setups sharing and social engagement features.</div>
      </div>
    </div>
    <div style="margin-top:28px;"><a href="?page=register" class="btn btn-primary">Get Started</a></div>
  </div>
</div>

<?php
// 404
else:
?>
<div class="auth-page">
  <div style="text-align:center;">
    <div style="font-size:6rem;font-weight:900;font-family:var(--font-head);background:linear-gradient(135deg,#7C3AED,#06B6D4);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;line-height:1;">404</div>
    <h2 style="font-family:var(--font-head);font-size:1.5rem;font-weight:700;margin:16px 0 10px;">Page Not Found</h2>
    <p style="color:var(--main-muted);margin-bottom:28px;">The page you're looking for doesn't exist or has been moved.</p>
    <a href="<?= SITE_URL ?>" class="btn btn-primary"><i class="fa fa-home"></i> Go Home</a>
  </div>
</div>
<?php endif; ?>

<!-- Main Site Footer -->
<?php if (!in_array($page, ['login','register'])): ?>
<footer class="main-footer">
  <div class="footer-inner">
    <div class="footer-grid">
      <div class="footer-brand">
        <div class="main-logo" style="font-size:1.5rem;">Game<span style="-webkit-text-fill-color:#F59E0B;color:#F59E0B;">Forge</span></div>
        <p>The premier platform for building professional gaming websites. Choose a template, customize it, and launch your gaming community in minutes.</p>
      </div>
      <div class="footer-col">
        <h4>Templates</h4>
        <?php foreach ($templates as $tpl): ?>
        <a href="?page=template&id=<?= $tpl['id'] ?>"><?= e($tpl['name']) ?></a>
        <?php endforeach; ?>
      </div>
      <div class="footer-col">
        <h4>Platform</h4>
        <a href="?page=home#features">Features</a>
        <a href="?page=home#howItWorks">How It Works</a>
        <a href="?page=register">Sign Up Free</a>
      </div>
      <div class="footer-col">
        <h4>Account</h4>
        <?php if (isLoggedIn()): ?>
        <a href="?page=profile">My Profile</a>
        <a href="?page=settings">Settings</a>
        <a href="?logout=1">Logout</a>
        <?php else: ?>
        <a href="?page=login">Sign In</a>
        <a href="?page=register">Create Account</a>
        <?php endif; ?>
      </div>
    </div>
    <div class="footer-bottom">
      <p>&copy; <?= date('Y') ?> GameForge. All rights reserved.</p>
      <div class="social-links">
        <a href="#" class="social-btn"><i class="fab fa-discord"></i></a>
        <a href="#" class="social-btn"><i class="fab fa-twitter"></i></a>
        <a href="#" class="social-btn"><i class="fab fa-twitch"></i></a>
        <a href="#" class="social-btn"><i class="fab fa-youtube"></i></a>
      </div>
    </div>
  </div>
</footer>
<?php endif; ?>

<?php endif; // end main site vs template ?>

<!-- ── GLOBAL MODALS ─────────────────────────────────────────── -->
<!-- Game Detail (for template pages — already defined above) -->

<!-- ── TOAST CONTAINER ──────────────────────────────────────── -->
<div id="toast-container"></div>

<!-- ══════════════════════════════════════════════════════════
     JAVASCRIPT
══════════════════════════════════════════════════════════ -->
<script>
const SITE_URL = '<?= SITE_URL ?>';
const IS_TEMPLATE = <?= $isTemplate ? 'true' : 'false' ?>;
const TEMPLATE_ID = <?= $templateId ?: 0 ?>;
const IS_LOGGED_IN = <?= isLoggedIn() ? 'true' : 'false' ?>;
const IS_ADMIN = <?= isAdmin() ? 'true' : 'false' ?>;

// ── Toast ─────────────────────────────────────────────────────
function showToast(msg, type = 'info') {
  const tc = document.getElementById('toast-container');
  const t = document.createElement('div');
  t.className = `toast toast-${type}`;
  const icon = type==='success'?'fa-check-circle':type==='error'?'fa-exclamation-circle':'fa-info-circle';
  t.innerHTML = `<i class="fa ${icon}"></i> ${msg}`;
  tc.appendChild(t);
  setTimeout(() => { t.style.opacity='0'; t.style.transform='translateX(20px)'; t.style.transition='all 0.3s'; setTimeout(()=>t.remove(),300); }, 3500);
}

// ── Modal ─────────────────────────────────────────────────────
function openModal(id) { document.getElementById(id).classList.add('open'); document.body.style.overflow='hidden'; }
function closeModal(id) { document.getElementById(id).classList.remove('open'); document.body.style.overflow=''; }
document.addEventListener('click', e => {
  if (e.target.classList.contains('modal-overlay')) closeModal(e.target.id);
});

// ── Alert helpers ─────────────────────────────────────────────
function showAlert(id, msg, type='error') {
  const el = document.getElementById(id);
  if (!el) return;
  el.className = `alert alert-${type} show`;
  el.textContent = msg;
  setTimeout(() => el.classList.remove('show'), 4000);
}

// ── AJAX helper ───────────────────────────────────────────────
async function ajax(data) {
  const form = new FormData();
  Object.entries(data).forEach(([k,v]) => form.append(k,v));
  const primaryUrl = SITE_URL + '?ajax=1';
  console.log('AJAX ->', primaryUrl, data);
  let r = await fetch(primaryUrl, { method:'POST', body: form });
  if (!r.ok && r.status === 404) {
    // Try a relative fallback in case SITE_URL is wrong (subdirectory mismatch).
    const fallbackUrl = './index.php?ajax=1';
    console.warn('Primary AJAX returned 404, trying fallback:', fallbackUrl);
    r = await fetch(fallbackUrl, { method:'POST', body: form });
  }
  if (!r.ok) {
    const text = await r.text();
    console.error('AJAX HTTP error', r.status, text);
    return { success: false, message: `Server error (${r.status}). Check console for details.` };
  }
  const t = await r.text();
  try { return JSON.parse(t); }
  catch (e) { console.error('AJAX parse error', e, t); return { success: false, message: 'Invalid server response. See console.' }; }
}

// ── Auth ──────────────────────────────────────────────────────
async function doLogin() {
  const btn = document.getElementById('loginBtn');
  const email = document.getElementById('loginEmail').value.trim();
  const password = document.getElementById('loginPassword').value;
  if (!email || !password) { showAlert('loginAlert','Please fill in all fields.'); return; }
  btn.innerHTML = '<div class="spinner"></div> Signing in...'; btn.disabled = true;
  const r = await ajax({ action:'login', email, password });
  btn.innerHTML = '<i class="fa fa-sign-in-alt"></i> Sign In'; btn.disabled = false;
  if (r.success) { showToast(r.message,'success'); setTimeout(() => window.location = SITE_URL + '?page=profile', 800); }
  else showAlert('loginAlert', r.message);
}

async function doRegister() {
  const btn = document.getElementById('registerBtn');
  const full_name = document.getElementById('regFullname').value.trim();
  const username = document.getElementById('regUsername').value.trim();
  const email = document.getElementById('regEmail').value.trim();
  const password = document.getElementById('regPassword').value;
  if (!username || !email || !password) { showAlert('registerAlert','Please fill in all required fields.'); return; }
  btn.innerHTML = '<div class="spinner"></div> Creating account...'; btn.disabled = true;
  const r = await ajax({ action:'register', username, email, password, full_name });
  btn.innerHTML = '<i class="fa fa-user-plus"></i> Create Account'; btn.disabled = false;
  if (r.success) { showToast(r.message,'success'); setTimeout(() => window.location = SITE_URL + '?page=profile', 800); }
  else { showAlert('registerAlert', r.message); document.getElementById('registerAlert').classList.add('alert-error'); }
}

async function logoutUser() {
  await ajax({ action:'logout' });
  window.location = SITE_URL;
}

// ── Profile & Settings ────────────────────────────────────────
async function saveProfile() {
  const full_name = document.getElementById('pfFullName')?.value.trim() || '';
  const bio = document.getElementById('pfBio')?.value.trim() || '';
  const avatar = document.getElementById('pfAvatar')?.value.trim() || '';
  const r = await ajax({ action:'update_profile', full_name, bio, avatar });
  showAlert('profileAlert', r.message, r.success ? 'success' : 'error');
  if (r.success) showToast('Profile updated!', 'success');
}

async function changePassword() {
  const current_password = document.getElementById('curPw').value;
  const new_password = document.getElementById('newPw').value;
  const conf = document.getElementById('confPw').value;
  if (new_password !== conf) { showAlert('passwordAlert','Passwords do not match.'); return; }
  const r = await ajax({ action:'change_password', current_password, new_password });
  showAlert('passwordAlert', r.message, r.success ? 'success' : 'error');
  if (r.success) { document.getElementById('curPw').value=''; document.getElementById('newPw').value=''; document.getElementById('confPw').value=''; }
}

async function saveSettings() {
  const data = { action:'update_settings', language: document.getElementById('language')?.value || 'en', privacy: document.getElementById('privacy')?.value || 'public' };
  if (document.getElementById('emailNotif')?.checked) data.email_notifications = '1';
  if (document.getElementById('newsletter')?.checked) data.newsletter = '1';
  if (document.getElementById('darkMode')?.checked) data.dark_mode = '1';
  const r = await ajax(data);
  showAlert('settingsAlert', r.message, r.success ? 'success' : 'error');
  if (r.success) showToast('Settings saved!','success');
}

// ── Tab switching ─────────────────────────────────────────────
function switchTab(tabName, btn) {
  document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  document.getElementById(tabName + 'Tab').classList.add('active');
  btn.classList.add('active');
}

// ── Nav dropdown ─────────────────────────────────────────────
function toggleDropdown(e) {
  e.stopPropagation();
  document.getElementById('userDropdown')?.classList.toggle('open');
}
document.addEventListener('click', () => document.getElementById('userDropdown')?.classList.remove('open'));

function toggleNav() {
  document.getElementById('navLinks').classList.toggle('open');
}

// ── Games (template pages) ────────────────────────────────────
function filterGames() {
  const q = document.getElementById('gameSearch')?.value.toLowerCase() || '';
  const genre = document.getElementById('genreFilter')?.value.toLowerCase() || '';
  const sort = document.getElementById('sortFilter')?.value || 'featured';
  const grid = document.getElementById('gamesGrid');
  if (!grid) return;
  let cards = [...grid.querySelectorAll('.t-game-card')];

  cards.forEach(c => {
    const title = c.dataset.title || '';
    const cGenre = c.dataset.genre?.toLowerCase() || '';
    const matchQ = !q || title.includes(q) || cGenre.includes(q);
    const matchG = !genre || cGenre === genre;
    c.style.display = matchQ && matchG ? '' : 'none';
  });

  // Sort visible
  const visible = cards.filter(c => c.style.display !== 'none');
  visible.sort((a,b) => {
    if (sort==='rating') return parseFloat(b.dataset.rating||0) - parseFloat(a.dataset.rating||0);
    if (sort==='plays') return parseInt(b.dataset.plays||0) - parseInt(a.dataset.plays||0);
    if (sort==='newest') return parseInt(b.dataset.year||0) - parseInt(a.dataset.year||0);
    return parseInt(b.dataset.featured||0) - parseInt(a.dataset.featured||0);
  });
  visible.forEach(c => grid.appendChild(c));

  const noMsg = document.getElementById('noGamesMsg');
  if (noMsg) noMsg.style.display = visible.length === 0 ? 'block' : 'none';
}

async function showGameDetail(id) {
  openModal('gameDetailModal');
  const r = await fetch(SITE_URL + `?ajax=1&action=get_game&id=${id}`);
  const data = await r.json();
  const game = data.game;
  if (!game) { document.getElementById('gameModalBody').innerHTML = '<p style="color:var(--main-muted);text-align:center;padding:30px;">Game not found.</p>'; return; }
  document.getElementById('gameModalTitle').textContent = game.title;
  document.getElementById('gameModalBody').innerHTML = `
    <div style="display:flex;align-items:center;justify-content:center;height:140px;background:var(--t-bg3,var(--main-bg3));border-radius:10px;margin-bottom:20px;">
      <i class="fa fa-gamepad" style="font-size:4rem;opacity:0.2;color:var(--t-primary,var(--main-primary));"></i>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;">
      <span style="background:var(--t-bg2,var(--main-bg2));border:1px solid var(--t-border,var(--main-border));padding:4px 10px;border-radius:6px;font-size:0.78rem;font-weight:600;color:var(--t-primary,var(--main-primary));">${game.genre}</span>
      <span style="background:var(--t-bg2,var(--main-bg2));border:1px solid var(--t-border,var(--main-border));padding:4px 10px;border-radius:6px;font-size:0.78rem;color:var(--t-muted,var(--main-muted));">${game.platform}</span>
      ${game.release_year ? `<span style="background:var(--t-bg2,var(--main-bg2));border:1px solid var(--t-border,var(--main-border));padding:4px 10px;border-radius:6px;font-size:0.78rem;color:var(--t-muted,var(--main-muted));">${game.release_year}</span>` : ''}
    </div>
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:16px;">
      <span style="font-size:1.5rem;font-weight:800;color:#F59E0B;font-family:var(--t-font,var(--font-head));">★ ${parseFloat(game.rating).toFixed(1)}</span>
      <span style="font-size:0.82rem;color:var(--t-muted,var(--main-muted));">${parseInt(game.plays_count).toLocaleString()} plays</span>
    </div>
    <p style="font-size:0.95rem;color:var(--t-muted,var(--main-muted));line-height:1.7;">${game.description}</p>
    ${IS_ADMIN ? `<div style="margin-top:20px;display:flex;gap:8px;"><button class="t-btn t-btn-outline" onclick="closeModal('gameDetailModal');editGame(${game.id})">Edit</button><button class="t-btn" style="background:#EF4444;color:#fff;" onclick="closeModal('gameDetailModal');deleteGame(${game.id})">Delete</button></div>` : ''}
  `;
}

// ── Game CRUD (admin) ─────────────────────────────────────────
async function saveGame() {
  const id = document.getElementById('agId')?.value;
  const template_id = document.getElementById('agTemplateId')?.value;
  const title = document.getElementById('agTitle')?.value.trim();
  const genre = document.getElementById('agGenre')?.value.trim();
  const rating = document.getElementById('agRating')?.value;
  const platform = document.getElementById('agPlatform')?.value.trim();
  const description = document.getElementById('agDesc')?.value.trim();
  const is_featured = document.getElementById('agFeatured')?.checked ? '1' : '';
  if (!title) { showAlert('addGameAlert','Title is required.'); return; }
  const action = id ? 'edit_game' : 'add_game';
  const data = { action, template_id, title, genre, rating, platform, description };
  if (is_featured) data.is_featured = '1';
  if (id) data.id = id;
  const btn = document.getElementById('saveGameBtn');
  btn.innerHTML = '<div class="spinner"></div>'; btn.disabled = true;
  const r = await ajax(data);
  btn.innerHTML = '<i class="fa fa-save"></i> Save Game'; btn.disabled = false;
  if (r.success) { showToast(r.message,'success'); closeModal('addGameModal'); setTimeout(() => location.reload(), 800); }
  else showAlert('addGameAlert', r.message);
}

async function editGame(id) {
  const r = await fetch(SITE_URL + `?ajax=1&action=get_game&id=${id}`);
  const data = await r.json();
  const g = data.game;
  if (!g) return;
  document.getElementById('agId').value = g.id;
  document.getElementById('agTitle').value = g.title;
  document.getElementById('agGenre').value = g.genre;
  document.getElementById('agRating').value = g.rating;
  document.getElementById('agPlatform').value = g.platform;
  document.getElementById('agDesc').value = g.description;
  document.getElementById('agFeatured').checked = !!parseInt(g.is_featured);
  openModal('addGameModal');
}

async function deleteGame(id) {
  if (!confirm('Delete this game? This cannot be undone.')) return;
  const r = await ajax({ action:'delete_game', id });
  if (r.success) { showToast('Game deleted.','success'); setTimeout(() => location.reload(), 800); }
  else showToast(r.message,'error');
}

// ── Setup CRUD ────────────────────────────────────────────────
async function likeSetup(id, el) {
  const r = await ajax({ action:'like_setup', id });
  if (r.success) { el.querySelector('.likes-count').textContent = r.likes.toLocaleString(); el.querySelector('i').style.color='#EF4444'; }
}

async function submitSetup() {
  const data = {
    action: 'add_setup',
    template_id: document.getElementById('asTemplateId')?.value,
    setup_name: document.getElementById('asName')?.value.trim(),
    cpu: document.getElementById('asCpu')?.value.trim(),
    gpu: document.getElementById('asGpu')?.value.trim(),
    ram: document.getElementById('asRam')?.value.trim(),
    storage: document.getElementById('asStorage')?.value.trim(),
    monitor: document.getElementById('asMonitor')?.value.trim(),
    total_cost: document.getElementById('asCost')?.value.trim(),
    description: document.getElementById('asDesc')?.value.trim(),
  };
  if (!data.setup_name) { showAlert('addSetupAlert','Setup name required.'); return; }
  const r = await ajax(data);
  if (r.success) { showToast('Setup shared!','success'); closeModal('addSetupModal'); setTimeout(() => location.reload(), 800); }
  else showAlert('addSetupAlert', r.message);
}

async function deleteSetup(id) {
  if (!confirm('Delete this setup?')) return;
  const r = await ajax({ action:'delete_setup', id });
  if (r.success) { showToast('Setup deleted.','success'); setTimeout(() => location.reload(), 800); }
  else showToast(r.message,'error');
}

async function deleteMySetup(id) {
  if (!confirm('Delete this setup?')) return;
  const r = await ajax({ action:'delete_setup', id });
  if (r.success) { showToast('Setup deleted.','success'); setTimeout(() => location.reload(), 800); }
  else showToast(r.message,'error');
}

// ── Comment ───────────────────────────────────────────────────
async function submitComment(articleId) {
  const content = document.getElementById('commentText')?.value.trim();
  if (!content) { showAlert('commentAlert','Comment cannot be empty.','error'); document.getElementById('commentAlert').classList.add('show'); return; }
  const r = await ajax({ action:'add_comment', article_id: articleId, content });
  if (r.success) {
    showToast('Comment posted!','success');
    document.getElementById('commentText').value = '';
    const container = document.getElementById('commentsContainer');
    const noMsg = container.querySelector('.empty-state');
    if (noMsg) noMsg.remove();
    const div = document.createElement('div');
    div.className = 't-comment fade-in';
    div.innerHTML = `<div class="t-comment-avatar">${r.username.charAt(0).toUpperCase()}</div><div class="t-comment-body"><div class="t-comment-name">${r.username}</div><div class="t-comment-text">${content.replace(/\n/g,'<br>')}</div><div class="t-comment-date">Just now</div></div>`;
    container.insertBefore(div, container.firstChild);
  } else { showAlert('commentAlert', r.message,'error'); document.getElementById('commentAlert').classList.add('show'); }
}

// ── Contact ───────────────────────────────────────────────────
async function submitContact() {
  const name = document.getElementById('cName')?.value.trim();
  const email = document.getElementById('cEmail')?.value.trim();
  const subject = document.getElementById('cSubject')?.value.trim();
  const message = document.getElementById('cMessage')?.value.trim();
  if (!name || !email || !message) { showAlert('contactAlert','Please fill in all required fields.','error'); document.getElementById('contactAlert').classList.add('show'); return; }
  const r = await ajax({ action:'contact', name, email, subject, message });
  showAlert('contactAlert', r.message, r.success ? 'success' : 'error');
  document.getElementById('contactAlert').classList.add('show');
  if (r.success) { document.getElementById('cMessage').value=''; document.getElementById('cSubject').value=''; }
}

// ── Smooth scroll for hero link ───────────────────────────────
document.querySelectorAll('a[href*="#"]').forEach(a => {
  a.addEventListener('click', e => {
    const href = a.getAttribute('href');
    if (href.includes('#') && href.startsWith('?page=home#')) {
      // Let browser handle redirect + hash
      return;
    }
    if (href.startsWith('#')) {
      e.preventDefault();
      const el = document.querySelector(href);
      if (el) el.scrollIntoView({ behavior:'smooth', block:'start' });
    }
  });
});

// ── Intersection observer for fade-in animations ──────────────
const observer = new IntersectionObserver(entries => {
  entries.forEach(en => {
    if (en.isIntersecting) { en.target.style.opacity='1'; en.target.style.transform='none'; }
  });
}, { threshold: 0.1 });

document.querySelectorAll('.fade-in').forEach(el => {
  el.style.opacity='0'; el.style.transform='translateY(16px)'; el.style.transition='opacity 0.5s ease, transform 0.5s ease';
  observer.observe(el);
});
document.querySelectorAll('.template-card, .feature-card, .step-card').forEach((el, i) => {
  el.style.opacity='0'; el.style.transform='translateY(20px)';
  el.style.transition=`opacity 0.5s ease ${i*0.06}s, transform 0.5s ease ${i*0.06}s`;
  observer.observe(el);
});

// ── Progress bars animate on load ────────────────────────────
window.addEventListener('load', () => {
  document.querySelectorAll('.t-progress-bar').forEach(bar => {
    const w = bar.style.width;
    bar.style.width = '0';
    setTimeout(() => { bar.style.width = w; }, 200);
  });
});
</script>
</body>
</html>