<?php
/**
 * Couriva - Core Utility & Security Helper Functions
 */

// Couriva stores and compares timestamps in UTC. Explicitly pin the PHP
// runtime so date(), strtotime(), and database DATETIME values agree even when
// the host's local timezone differs from MySQL's session timezone.
date_default_timezone_set('UTC');

if (!defined('WEBMAIL_INIT')) {
    define('WEBMAIL_INIT', true);
}

// Public product identity. Internal class names and the database name remain
// `webmail` for upgrade compatibility; everything user-facing is Couriva.
if (!defined('APP_NAME')) {
    define('APP_NAME', getenv('APP_NAME') !== false ? (string)getenv('APP_NAME') : 'Couriva');
}
if (!defined('APP_DOMAIN')) {
    define('APP_DOMAIN', getenv('MAIL_DOMAIN') !== false ? (string)getenv('MAIL_DOMAIN') : 'couriva.local');
}

function app_name(): string
{
    return APP_NAME;
}

function app_domain(): string
{
    return APP_DOMAIN;
}

/**
 * Build a Couriva address from a local username.
 */
function couriva_address(string $username): string
{
    $username = strtolower(trim($username));
    $username = ltrim($username, '@');
    $username = preg_replace('/@.*$/', '', $username) ?? $username;
    return $username . '@' . app_domain();
}

/**
 * True when an address belongs to this Couriva installation.
 */
function is_couriva_address(string $email): bool
{
    $email = strtolower(trim($email));
    return str_ends_with($email, '@' . strtolower(app_domain()));
}


// Session initialization with modern security flags
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.gc_maxlifetime', (string)session_absolute_timeout());

    // Enable secure cookies if running over HTTPS (directly or via a trusted proxy)
    if (is_https_request()) {
        ini_set('session.cookie_secure', '1');
    }

    session_start();
}

// Security headers must be emitted before any application output.
if (PHP_SAPI !== 'cli') {
    send_security_headers();

    // Optional HTTPS enforcement for deployments with a terminating proxy.
    if (getenv('WEBMAIL_FORCE_HTTPS') === 'true' && !is_https_request()) {
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        header('Location: https://' . $host . $uri, true, 302);
        exit;
    }
}

/** Build a page query without dropping active mailbox filters. */
function mail_pagination_query(int $page, array $criteria, string $folder = ''): string
{
    $query = ['page' => max(1, $page)];
    if ($folder !== '' && $folder !== 'inbox') {
        $query['folder'] = $folder;
    }
    if ($folder === 'label' && !empty($criteria['label_id'])) {
        $query['label'] = (int)$criteria['label_id'];
    }
    if ($folder === 'custom' && !empty($criteria['folder_id'])) {
        $query['id'] = (int)$criteria['folder_id'];
    }
    foreach (['q', 'date_from', 'date_to', 'has_attachment', 'is_unread', 'is_starred'] as $key) {
        if (isset($criteria[$key]) && is_string($criteria[$key]) && $criteria[$key] !== '') {
            $query[$key] = $criteria[$key];
        }
    }
    return http_build_query($query);
}

/**
 * Active mailbox timezone for date presentation.
 */
function user_timezone(): string
{
    $tz = (string)($_SESSION['user_timezone'] ?? 'UTC');
    return in_array($tz, timezone_identifiers_list(), true) ? $tz : 'UTC';
}

/**
 * Format a stored UTC datetime in the signed-in user's timezone.
 */
function format_user_datetime(?string $utcDateTime, string $format = 'M d, Y g:i A'): string
{
    if (empty($utcDateTime)) {
        return '—';
    }

    try {
        $date = new DateTimeImmutable($utcDateTime, new DateTimeZone('UTC'));
        return $date->setTimezone(new DateTimeZone(user_timezone()))->format($format);
    } catch (Throwable $e) {
        return '—';
    }
}

/**
 * Accept only in-app relative notification links. Prevents stored links from
 * becoming javascript: URLs or off-site redirects.
 */
function safe_notification_link(?string $link): string
{
    $link = trim((string)$link);
    if ($link === '' || preg_match('#^(javascript|data|vbscript):#i', $link)) {
        return app_base_url('public/notifications.php');
    }
    if (str_starts_with($link, 'public/') || str_starts_with($link, 'admin/')) {
        return app_base_url($link);
    }
    return app_base_url('public/notifications.php');
}

/**
 * Escape HTML output for XSS protection
 */
function e(?string $string): string
{
    return htmlspecialchars($string ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Generate or retrieve CSRF token for the active session
 */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Output hidden CSRF form input field
 */
function csrf_field(): string
{
    $token = csrf_token();
    return '<input type="hidden" name="csrf_token" value="' . e($token) . '">';
}

/**
 * Verify CSRF token from POST or request header
 */
function verify_csrf_token(?string $token = null): bool
{
    if ($token === null) {
        $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    }
    
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Set flash message
 */
function set_flash(string $type, string $message): void
{
    $_SESSION['flash'][$type][] = $message;
}

/**
 * Get and clear flash messages
 */
function get_flashes(): array
{
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flashes;
}

/**
 * Determine whether the current request reached the application over HTTPS.
 * Forwarded headers are only trusted when explicitly enabled for a known proxy.
 */
function is_https_request(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
        return true;
    }

    if ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443) {
        return true;
    }

    if (getenv('TRUST_PROXY_HEADERS') === 'true') {
        $forwardedProto = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
        if ($forwardedProto === 'https') {
            return true;
        }
        if (strtolower((string)($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')) === 'on') {
            return true;
        }
    }

    return false;
}

/**
 * Idle session timeout in seconds (default: 30 minutes).
 */
function session_idle_timeout(): int
{
    $value = getenv('SESSION_IDLE_TIMEOUT');
    $timeout = $value !== false ? (int)$value : 1800;
    return max(0, $timeout);
}

/**
 * Absolute session lifetime in seconds (default: 8 hours).
 */
function session_absolute_timeout(): int
{
    $value = getenv('SESSION_ABSOLUTE_TIMEOUT');
    $timeout = $value !== false ? (int)$value : 28800;
    return max(0, $timeout);
}

/**
 * Emit defense-in-depth security headers for every dynamic response.
 */
function send_security_headers(): void
{
    if (headers_sent()) {
        return;
    }

    // Do not advertise the PHP version in production responses.
    header_remove('X-Powered-By');

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    $nonce = base64_encode(random_bytes(18));
    $GLOBALS['webmail_csp_nonce'] = $nonce;

    $csp = "default-src 'self'; "
        . "base-uri 'self'; "
        . "object-src 'none'; "
        . "frame-ancestors 'self'; "
        . "form-action 'self'; "
        . "script-src 'self' 'nonce-{$nonce}' https://cdn.jsdelivr.net; "
        . "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com; "
        . "font-src 'self' data: https://cdn.jsdelivr.net https://fonts.gstatic.com; "
        . "img-src 'self' data: blob: https:; "
        . "connect-src 'self'; "
        . "frame-src 'self';";
    if (is_https_request()) {
        $csp .= " upgrade-insecure-requests;";
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
    header('Content-Security-Policy: ' . $csp);
}

/**
 * Clear authenticated session state while keeping a usable session for
 * flash messages and CSRF tokens. Used for timeout, revocation and DB failure.
 */
function invalidate_session(): void
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
        session_regenerate_id(true);
    }
}

/**
 * Check if user is logged in.
 *
 * Fails closed: any database error, missing/deleted user, disabled account,
 * role/status change, session-epoch mismatch, or expired idle/absolute
 * timeout invalidates the session and returns false.
 */
function is_authenticated(): bool
{
    if (empty($_SESSION['user_id']) || empty($_SESSION['user_email'])) {
        return false;
    }

    $now = time();
    $lastActivity = (int)($_SESSION['last_activity'] ?? 0);
    $authTime = (int)($_SESSION['auth_time'] ?? 0);
    $idleTimeout = session_idle_timeout();
    $absoluteTimeout = session_absolute_timeout();

    if (
        $lastActivity <= 0 || $authTime <= 0 ||
        ($idleTimeout > 0 && ($now - $lastActivity) > $idleTimeout) ||
        ($absoluteTimeout > 0 && ($now - $authTime) > $absoluteTimeout)
    ) {
        invalidate_session();
        return false;
    }

    try {
        global $pdo;
        if (!($pdo instanceof PDO)) {
            require_once __DIR__ . '/database.php';
        }
        if (!($pdo instanceof PDO)) {
            invalidate_session();
            return false;
        }

        $stmt = $pdo->prepare("
            SELECT id, email, first_name, last_name, role, status, session_epoch,
                   must_change_password, timezone, theme, avatar
            FROM users
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([(int)$_SESSION['user_id']]);
        $user = $stmt->fetch();

        if (
            !$user ||
            $user['status'] !== 'active' ||
            (int)($user['session_epoch'] ?? 0) !== (int)($_SESSION['session_epoch'] ?? 0) ||
            !hash_equals(strtolower((string)$user['email']), strtolower((string)$_SESSION['user_email']))
        ) {
            invalidate_session();
            return false;
        }

        // Keep the session copy authoritative and reflect role/status changes.
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['user_first_name'] = $user['first_name'];
        $_SESSION['user_last_name'] = $user['last_name'];
        $_SESSION['user_full_name'] = trim($user['first_name'] . ' ' . $user['last_name']);
        $_SESSION['user_timezone'] = $user['timezone'] ?: 'UTC';
        $_SESSION['user_theme'] = $user['theme'] ?? 'light';
        $_SESSION['user_avatar'] = $user['avatar'] ?? null;
        $_SESSION['must_change_password'] = (int)($user['must_change_password'] ?? 0);
        $_SESSION['session_epoch'] = (int)$user['session_epoch'];
        $_SESSION['last_activity'] = $now;

        return true;
    } catch (Throwable $e) {
        error_log('Authentication check failed closed: ' . $e->getMessage());
        invalidate_session();
        return false;
    }
}

/**
 * Check if logged-in user has admin role
 */
function is_admin(): bool
{
    return is_authenticated() && ($_SESSION['user_role'] ?? '') === 'admin';
}

/**
 * Validate a role value coming from an administrative form.
 */
function is_valid_user_role($role): bool
{
    return is_string($role) && in_array($role, ['user', 'admin'], true);
}

/**
 * Validate an account status value coming from an administrative form.
 */
function is_valid_user_status($status): bool
{
    return is_string($status) && in_array($status, ['active', 'disabled', 'pending', 'rejected', 'suspended'], true);
}

/**
 * Human-readable account status label.
 */
function user_status_label(string $status): string
{
    return match ($status) {
        'active' => 'Active',
        'pending' => 'Pending approval',
        'rejected' => 'Rejected',
        'suspended' => 'Suspended',
        'disabled' => 'Disabled',
        default => ucfirst($status),
    };
}

/**
 * Log system audit activity into system_logs table
 */
function log_activity(?int $userId, string $action, string $status = 'SUCCESS', ?string $details = null): bool
{
    try {
        require_once __DIR__ . '/database.php';
        global $pdo;
        
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? 'CLI/Unknown', 0, 255);

        $stmt = $pdo->prepare("
            INSERT INTO system_logs (user_id, action, ip_address, user_agent, status, details, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        return $stmt->execute([$userId, $action, $ip, $ua, $status, $details]);
    } catch (Throwable $e) {
        error_log("Failed to write system log: " . $e->getMessage());
        return false;
    }
}

/**
 * Create an in-app notification. Failures never break the originating action.
 */
function create_notification(int $userId, string $type, string $title, string $message = '', ?string $link = null): bool
{
    if ($userId <= 0) {
        return false;
    }

    try {
        require_once __DIR__ . '/database.php';
        global $pdo;
        if (!($pdo instanceof PDO)) {
            return false;
        }

        $stmt = $pdo->prepare("
            INSERT INTO notifications (user_id, type, title, message, link, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        return $stmt->execute([
            $userId,
            substr($type, 0, 32),
            mb_substr($title, 0, 255),
            mb_substr($message, 0, 500),
            $link !== null ? mb_substr($link, 0, 255) : null,
        ]);
    } catch (Throwable $e) {
        error_log('Notification creation failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Notify every administrator.
 */
function notify_admins(string $type, string $title, string $message = '', ?string $link = null): void
{
    try {
        require_once __DIR__ . '/database.php';
        global $pdo;
        if (!($pdo instanceof PDO)) {
            return;
        }
        $adminIds = $pdo->query("SELECT id FROM users WHERE role = 'admin' AND status = 'active'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($adminIds as $adminId) {
            create_notification((int)$adminId, $type, $title, $message, $link);
        }
    } catch (Throwable $e) {
        error_log('Admin notification failed: ' . $e->getMessage());
    }
}

/**
 * Unread notification count for the header bell.
 */
function unread_notification_count(int $userId): int
{
    if ($userId <= 0) {
        return 0;
    }

    try {
        require_once __DIR__ . '/database.php';
        global $pdo;
        if (!($pdo instanceof PDO)) {
            return 0;
        }
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL");
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}


/**
 * Format bytes into human-readable representation
 */
function format_bytes(int $bytes, int $precision = 2): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= (1 << (10 * $pow));
    return round($bytes, $precision) . ' ' . $units[$pow];
}

/**
 * Resolve application base path dynamically
 */
function app_base_url(string $path = ''): string
{
    $scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
    // If running inside public, public/api, or admin, climb up to the base.
    $base = preg_replace('/(\/public\/api|\/public|\/admin)$/', '', $scriptDir);
    return rtrim($base, '/') . '/' . ltrim($path, '/');
}

/**
 * Restrict an email CSS declaration block to a small, non-positioning allowlist.
 */
function sanitize_email_css(?string $css): string
{
    $css = trim((string)$css);
    if ($css === '' || strlen($css) > 2000) {
        return '';
    }

    if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $css)) {
        return '';
    }

    $lower = strtolower($css);
    foreach (['expression', 'behavior', '-moz-binding', '@import', 'url(', 'javascript:', 'vbscript:', 'data:', 'var(', 'env(', '\\'] as $needle) {
        if (str_contains($lower, $needle)) {
            return '';
        }
    }

    $allowedProperties = [
        'color', 'background', 'background-color', 'font', 'font-family', 'font-size',
        'font-weight', 'font-style', 'font-variant', 'text-decoration', 'text-align',
        'text-transform', 'line-height', 'letter-spacing', 'word-spacing', 'white-space',
        'vertical-align', 'margin', 'margin-top', 'margin-right', 'margin-bottom',
        'margin-left', 'padding', 'padding-top', 'padding-right', 'padding-bottom',
        'padding-left', 'border', 'border-top', 'border-right', 'border-bottom',
        'border-left', 'border-color', 'border-style', 'border-width', 'border-collapse',
        'border-spacing', 'border-radius', 'width', 'min-width', 'max-width', 'height',
        'min-height', 'max-height', 'display', 'list-style-type', 'list-style-position',
        'table-layout', 'caption-side', 'empty-cells', 'overflow', 'overflow-wrap',
        'word-break', 'float', 'clear',
    ];

    $safe = [];
    foreach (explode(';', $css) as $declaration) {
        if (!str_contains($declaration, ':')) {
            continue;
        }
        [$property, $value] = array_map('trim', explode(':', $declaration, 2));
        $property = strtolower($property);
        if (!preg_match('/^[a-z-]+$/', $property) || !in_array($property, $allowedProperties, true)) {
            continue;
        }
        if ($value === '' || strlen($value) > 300) {
            continue;
        }
        if (preg_match('/[<>{}@\\\\]/', $value)) {
            continue;
        }
        if (preg_match('/(expression|javascript|vbscript|url\s*\(|behavior|-moz-binding|var\s*\(|env\s*\()/i', $value)) {
            continue;
        }
        $value = preg_replace('/\s*!important\s*$/i', '', $value);
        $safe[] = $property . ': ' . $value;
    }

    return implode('; ', $safe);
}

/**
 * Validate an email URL and return it unchanged when it uses an allowed scheme,
 * or null when the URL is unsafe.
 */
function sanitize_email_url(string $url, bool $isImage = false): ?string
{
    $url = trim($url);
    if ($url === '') {
        return null;
    }

    $compact = preg_replace('/[\x00-\x20\x7F]+/', '', $url);
    $lower = strtolower((string)$compact);

    if (
        str_starts_with($lower, 'javascript:') ||
        str_starts_with($lower, 'vbscript:') ||
        str_starts_with($lower, 'file:') ||
        str_starts_with($lower, 'data:')
    ) {
        if ($isImage && preg_match('/^data:image\/(png|jpe?g|gif|webp);base64,[a-z0-9+\/=\s]+$/i', (string)$compact)) {
            return $url;
        }
        return null;
    }

    if ($isImage) {
        if (preg_match('/^(https?:\/\/|\/(?!\/)|\.{1,2}\/)/i', $url)) {
            return $url;
        }
        return null;
    }

    if (preg_match('/^(https?:\/\/|mailto:|tel:|#|\/(?!\/)|\.{1,2}\/|\?)/i', $url)) {
        return $url;
    }

    return null;
}

/**
 * Sanitize email HTML content against XSS while preserving legitimate formatting.
 *
 * Uses a tag/attribute allowlist, restricted CSS, safe URL schemes, and remote
 * image blocking (opt-in via $allowRemoteImages).
 */
function sanitize_email_html(?string $html, bool $allowRemoteImages = false): string
{
    if ($html === null || trim($html) === '') {
        return '';
    }

    // Plain text (or text containing only literal "<" characters) is escaped
    // and line breaks preserved. This avoids treating "1 < 2" as markup.
    if (!preg_match('/<[a-z][a-z0-9]*(\s[^>]*)?>/i', $html)) {
        $escaped = htmlspecialchars($html, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $escaped = preg_replace(
            '/(https?:\/\/[^\s<]+)/i',
            '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>',
            $escaped
        );
        return nl2br($escaped);
    }

    $dom = new DOMDocument('1.0', 'UTF-8');
    $previous = libxml_use_internal_errors(true);
    $encodedHtml = '<?xml encoding="UTF-8"><div>' . $html . '</div>';
    $loaded = $dom->loadHTML($encodedHtml, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    if (!$loaded) {
        return nl2br(htmlspecialchars($html, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }

    $root = null;
    foreach ($dom->getElementsByTagName('div') as $candidate) {
        $root = $candidate;
        break;
    }
    if (!$root) {
        return nl2br(htmlspecialchars($html, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }

    $allowedTags = [
        'a', 'b', 'strong', 'i', 'em', 'u', 's', 'strike', 'del', 'ins', 'p', 'br',
        'hr', 'div', 'span', 'blockquote', 'pre', 'code', 'ul', 'ol', 'li', 'h1',
        'h2', 'h3', 'h4', 'h5', 'h6', 'table', 'thead', 'tbody', 'tfoot', 'tr',
        'th', 'td', 'img', 'figure', 'figcaption', 'sub', 'sup', 'small', 'mark',
        'dl', 'dt', 'dd', 'center', 'font', 'address', 'article', 'section',
        'header', 'footer', 'main', 'nav', 'aside',
    ];

    $disallowedTags = [
        'script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed',
        'applet', 'base', 'form', 'input', 'textarea', 'select', 'option',
        'button', 'link', 'meta', 'canvas', 'svg', 'math', 'audio', 'video',
        'source', 'track', 'marquee', 'template', 'noscript', 'portal', 'html',
        'head', 'body', 'title',
    ];

    $allowedAttributes = [
        'title', 'dir', 'lang', 'style', 'href', 'target', 'rel', 'src', 'alt',
        'width', 'height', 'colspan', 'rowspan', 'start', 'type', 'border',
        'cellpadding', 'cellspacing', 'align', 'valign', 'cite', 'summary',
        'color', 'face', 'size',
    ];

    $walk = function (DOMNode $node) use (&$walk, $allowedTags, $disallowedTags, $allowedAttributes, $allowRemoteImages): void {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMComment || $child instanceof DOMProcessingInstruction) {
                $node->removeChild($child);
                continue;
            }
            if (!$child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->tagName);
            if (in_array($tag, $disallowedTags, true) || !in_array($tag, $allowedTags, true)) {
                $node->removeChild($child);
                continue;
            }

            foreach (iterator_to_array($child->attributes) as $attribute) {
                $name = strtolower($attribute->name);
                $value = $attribute->value;

                if (!in_array($name, $allowedAttributes, true) || str_starts_with($name, 'on')) {
                    $child->removeAttribute($attribute->name);
                    continue;
                }

                if ($name === 'style') {
                    $safeCss = sanitize_email_css($value);
                    if ($safeCss === '') {
                        $child->removeAttribute('style');
                    } else {
                        $child->setAttribute('style', $safeCss);
                    }
                    continue;
                }

                if ($name === 'href') {
                    $safeUrl = sanitize_email_url($value, false);
                    if ($safeUrl === null) {
                        $child->removeAttribute('href');
                    }
                    continue;
                }

                if ($name === 'src') {
                    $safeUrl = sanitize_email_url($value, true);
                    if ($safeUrl === null) {
                        $child->removeAttribute('src');
                        continue;
                    }
                    $compactSrc = preg_replace('/[\x00-\x20\x7F]+/', '', $safeUrl);
                    if (!$allowRemoteImages && preg_match('/^https?:\/\//i', (string)$compactSrc)) {
                        $child->removeAttribute('src');
                        $child->setAttribute('data-blocked-src', $safeUrl);
                        $child->setAttribute('alt', trim($child->getAttribute('alt') . ' [Remote image blocked]'));
                        continue;
                    }
                }
            }

            if ($tag === 'a') {
                if ($child->getAttribute('href') !== '') {
                    $child->setAttribute('target', '_blank');
                    $child->setAttribute('rel', 'noopener noreferrer');
                }
            }

            $walk($child);
        }
    };

    $walk($root);

    $sanitized = '';
    foreach ($root->childNodes as $child) {
        $sanitized .= $dom->saveHTML($child);
    }

    return $sanitized;
}

/**
 * Return Bootstrap icon CSS classes for an attachment based on mime or filename extension
 */
function get_attachment_icon_class(?string $mime, ?string $filename): string
{
    $ext = strtolower(pathinfo($filename ?? '', PATHINFO_EXTENSION));
    $mime = strtolower($mime ?? '');

    if ($ext === 'pdf' || str_contains($mime, 'pdf')) {
        return 'bi bi-file-earmark-pdf-fill text-danger';
    }
    if (in_array($ext, ['doc', 'docx'], true) || str_contains($mime, 'word')) {
        return 'bi bi-file-earmark-word-fill text-primary';
    }
    if (in_array($ext, ['xls', 'xlsx', 'csv'], true) || str_contains($mime, 'spreadsheet') || str_contains($mime, 'excel') || str_contains($mime, 'csv')) {
        return 'bi bi-file-earmark-excel-fill text-success';
    }
    if (in_array($ext, ['ppt', 'pptx'], true) || str_contains($mime, 'presentation') || str_contains($mime, 'powerpoint')) {
        return 'bi bi-file-earmark-ppt-fill text-warning';
    }
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'], true) || str_starts_with($mime, 'image/')) {
        return 'bi bi-file-earmark-image-fill text-info';
    }
    if (in_array($ext, ['zip', 'rar', 'tar', 'gz', '7z'], true) || str_contains($mime, 'zip') || str_contains($mime, 'compressed')) {
        return 'bi bi-file-earmark-zip-fill text-secondary';
    }
    if (in_array($ext, ['txt', 'rtf', 'log'], true) || str_starts_with($mime, 'text/')) {
        return 'bi bi-file-earmark-text-fill text-muted';
    }
    if (in_array($ext, ['mp3', 'wav', 'ogg', 'mp4', 'mov', 'avi', 'mkv'], true) || str_starts_with($mime, 'video/') || str_starts_with($mime, 'audio/')) {
        return 'bi bi-file-earmark-play-fill text-primary';
    }

    return 'bi bi-file-earmark-arrow-down text-primary';
}

/**
 * Derive a 256-bit key for symmetric encryption from app config or environment.
 * Fails closed when no secret key is configured.
 */
function app_secret_key(): string
{
    $raw = (string)(getenv('APP_SECRET_KEY') ?: (defined('APP_SECRET_KEY') ? APP_SECRET_KEY : ''));
    if (trim($raw) === '') {
        throw new RuntimeException('Application secret key is not configured.');
    }
    return hash('sha256', $raw, true);
}

/**
 * Encrypt plaintext using AES-256-GCM.
 * Fails closed by throwing RuntimeException if key is missing or OpenSSL fails.
 */
function encrypt_data(string $plaintext): string
{
    $key = app_secret_key();
    if ($plaintext === '') {
        return '';
    }
    $iv = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    if ($ciphertext === false || strlen($tag) !== 16) {
        throw new RuntimeException('Data encryption failed.');
    }
    return base64_encode($iv . $tag . $ciphertext);
}

/**
 * Decrypt AES-256-GCM payload produced by encrypt_data().
 * Fails closed by throwing RuntimeException on missing key, malformed payload, or tag mismatch.
 */
function decrypt_data(string $encoded): string
{
    $key = app_secret_key();
    if ($encoded === '') {
        return '';
    }
    $raw = base64_decode($encoded, true);
    if ($raw === false || strlen($raw) < 28) {
        throw new RuntimeException('Malformed encrypted payload.');
    }
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $ciphertext = substr($raw, 28);
    $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($plaintext === false) {
        throw new RuntimeException('Data decryption failed or authentication tag mismatch.');
    }
    return $plaintext;
}
