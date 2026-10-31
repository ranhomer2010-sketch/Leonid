<?php
declare(strict_types=1);

const SITE_ROOT = __DIR__ . '/..';
const DATA_FILE = SITE_ROOT . '/content/site.json';
const SCHEMA_FILE = __DIR__ . '/schema.json';
const TEMPLATE_FILE = SITE_ROOT . '/site.template.html';
const OUTPUT_FILE = SITE_ROOT . '/index.html';
const PRIVATE_DIR = __DIR__ . '/private';
const AUTH_FILE = PRIVATE_DIR . '/auth.php';

function admin_headers(): void {
    header('Content-Type: text/html; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header("Cache-Control: no-store, private");
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data:; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
}

function start_admin_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('leonid_editor');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/admin',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_start();
}

function h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function load_json(string $file): array {
    $raw = @file_get_contents($file);
    $value = is_string($raw) ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : null;
    if (!is_array($value)) throw new RuntimeException('Не удалось прочитать данные сайта.');
    return $value;
}

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return (string) $_SESSION['csrf'];
}

function check_csrf(): void {
    $provided = $_POST['csrf'] ?? '';
    if (!is_string($provided) || !hash_equals(csrf_token(), $provided)) {
        throw new RuntimeException('Сессия формы истекла. Обновите страницу.');
    }
}

function logged_in(): bool {
    if (empty($_SESSION['logged_in'])) return false;
    if (time() - (int) ($_SESSION['last_activity'] ?? 0) > 1800) {
        $_SESSION = [];
        return false;
    }
    $_SESSION['last_activity'] = time();
    return true;
}

function read_auth(): array {
    if (!is_file(AUTH_FILE)) throw new RuntimeException('Админка ещё не настроена. Установите защищённый файл доступа.');
    $auth = require AUTH_FILE;
    if (!is_array($auth) || !isset($auth['salt'], $auth['hash'], $auth['iterations'])) {
        throw new RuntimeException('Неверная настройка доступа.');
    }
    return $auth;
}

function verify_password(string $password, array $auth): bool {
    $calculated = hash_pbkdf2('sha256', $password, (string) $auth['salt'], (int) $auth['iterations'], 64);
    return hash_equals((string) $auth['hash'], $calculated);
}

function make_auth(string $password): array {
    $salt = bin2hex(random_bytes(24));
    return ['salt' => $salt, 'hash' => hash_pbkdf2('sha256', $password, $salt, 250000, 64), 'iterations' => 250000];
}

function write_auth(array $auth): void {
    $php = "<?php\nreturn " . var_export($auth, true) . ";\n";
    $temp = AUTH_FILE . '.' . bin2hex(random_bytes(5)) . '.tmp';
    if (file_put_contents($temp, $php, LOCK_EX) === false) throw new RuntimeException('Не удалось сохранить пароль.');
    chmod($temp, 0600);
    if (!rename($temp, AUTH_FILE)) throw new RuntimeException('Не удалось обновить пароль.');
}

function rate_file(): string {
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    return PRIVATE_DIR . '/attempt-' . hash('sha256', $ip) . '.json';
}

function login_limited(bool $recordFailure = false, bool $clear = false): bool {
    $file = rate_file();
    $handle = fopen($file, 'c+');
    if ($handle === false || !flock($handle, LOCK_EX)) throw new RuntimeException('Не удалось проверить доступ.');
    $raw = stream_get_contents($handle);
    $attempts = json_decode(is_string($raw) ? $raw : '[]', true);
    if (!is_array($attempts)) $attempts = [];
    $attempts = array_values(array_filter($attempts, static fn($t): bool => is_int($t) && $t > time() - 900));
    if ($clear) $attempts = [];
    elseif ($recordFailure) $attempts[] = time();
    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, json_encode($attempts));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
    return count($attempts) >= 5;
}

function plain_text(mixed $value, int $maxBytes = 2000): string {
    if (!is_string($value)) throw new RuntimeException('Некорректное значение поля.');
    $value = trim(str_replace(["\r\n", "\r"], "\n", $value));
    if (strlen($value) > $maxBytes || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value)) {
        throw new RuntimeException('Текст слишком длинный или содержит недопустимые символы.');
    }
    return $value;
}

function safe_image_path(string $path): string {
    if (!preg_match('~^assets/(?:uploads/)?[A-Za-z0-9._-]+\.(?:jpe?g|png|webp)$~i', $path)) {
        throw new RuntimeException('Некорректный путь фотографии.');
    }
    return $path;
}

function price_rows(array $rows): string {
    $html = [];
    foreach ($rows as $row) {
        $name = h((string) ($row['name'] ?? ''));
        $detail = h((string) ($row['detail'] ?? ''));
        $duration = h((string) ($row['duration'] ?? ''));
        $price = h((string) ($row['price'] ?? ''));
        $detailHtml = $detail !== '' ? ' <small>' . $detail . '</small>' : '';
        $html[] = '<div class="price-row"><div class="price-name">' . $name . $detailHtml . '</div><div class="price-time">' . $duration . '</div><strong class="price-value">' . $price . '</strong></div>';
    }
    return implode("\n            ", $html);
}

function render_site(array $data): string {
    $template = @file_get_contents(TEMPLATE_FILE);
    if (!is_string($template)) throw new RuntimeException('Не найден шаблон сайта.');
    $replacements = [];
    foreach ($data['fields'] as $key => $value) {
        $replacements['{{' . $key . '}}'] = h((string) $value);
    }
    foreach ($data['images'] as $key => $image) {
        $replacements['{{image_' . $key . '_path}}'] = h(safe_image_path((string) $image['path']));
        $replacements['{{image_' . $key . '_alt}}'] = h((string) $image['alt']);
    }
    foreach ($data['prices'] as $key => $rows) {
        $replacements['{{PRICE_' . strtoupper((string) $key) . '}}'] = price_rows($rows);
    }
    $result = strtr($template, $replacements);
    if (preg_match('/\{\{[A-Za-z0-9_]+\}\}/', $result)) {
        throw new RuntimeException('В шаблоне остались незаполненные поля.');
    }
    return $result;
}

function atomic_file(string $path, string $contents): void {
    $temp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
    if (file_put_contents($temp, $contents, LOCK_EX) === false) {
        throw new RuntimeException('Не удалось сохранить изменения. Проверьте права на файлы.');
    }
    chmod($temp, 0644);
    if (!rename($temp, $path)) {
        @unlink($temp);
        throw new RuntimeException('Не удалось опубликовать изменения.');
    }
}

function publish_data(array $data): void {
    $lock = fopen(PRIVATE_DIR . '/publish.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) throw new RuntimeException('Не удалось заблокировать сохранение.');
    try {
        $html = render_site($data);
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
        $backupDir = PRIVATE_DIR . '/backups';
        if (!is_dir($backupDir)) mkdir($backupDir, 0700, true);
        $backup = $backupDir . '/' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.json';
        if (is_file(DATA_FILE)) copy(DATA_FILE, $backup);
        $files = glob($backupDir . '/*.json') ?: [];
        rsort($files);
        foreach (array_slice($files, 20) as $old) @unlink($old);
        atomic_file(DATA_FILE, $json);
        atomic_file(OUTPUT_FILE, $html);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function update_fields(array $data, array $schema, mixed $posted): array {
    if (!is_array($posted)) throw new RuntimeException('Не найдены данные формы.');
    foreach ($schema['groups'] as $group) {
        foreach ($group as $field) {
            $key = $field['key'];
            $data['fields'][$key] = plain_text($posted[$key] ?? null, $field['type'] === 'textarea' ? 3000 : 500);
        }
    }
    return $data;
}

function update_prices(array $data, array $schema, mixed $posted): array {
    if (!is_array($posted)) throw new RuntimeException('Не найдены цены.');
    foreach ($schema['price_groups'] as $key => $_label) {
        $group = $posted[$key] ?? null;
        if (!is_array($group) || count($group) > 31) throw new RuntimeException('Некорректный список услуг.');
        $rows = [];
        foreach ($group as $row) {
            if (!is_array($row) || !empty($row['delete'])) continue;
            $name = plain_text($row['name'] ?? null, 300);
            $detail = plain_text($row['detail'] ?? '', 400);
            $duration = plain_text($row['duration'] ?? '', 80);
            $price = plain_text($row['price'] ?? '', 80);
            if ($name === '' && $detail === '' && $duration === '' && $price === '') continue;
            if ($name === '' || $duration === '' || $price === '') {
                throw new RuntimeException('У каждой услуги должны быть название, время и цена.');
            }
            $rows[] = compact('name', 'detail', 'duration', 'price');
        }
        if (count($rows) > 30) throw new RuntimeException('Слишком много услуг в одном разделе.');
        $data['prices'][$key] = $rows;
    }
    return $data;
}

function upload_image(array $data, array $schema): array {
    $slot = $_POST['slot'] ?? '';
    if (!is_string($slot) || !isset($schema['images'][$slot])) throw new RuntimeException('Выберите фотографию для замены.');
    $file = $_FILES['image'] ?? null;
    if (!is_array($file) || $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('Не удалось загрузить фотографию.');
    }
    if ((int) $file['size'] < 1 || (int) $file['size'] > 5 * 1024 * 1024) {
        throw new RuntimeException('Максимальный размер фотографии — 5 МБ.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($extensions[$mime])) throw new RuntimeException('Используйте JPG, PNG или WebP.');
    $info = @getimagesize($file['tmp_name']);
    if ($info === false || $info[0] < 100 || $info[1] < 100 || $info[0] > 6000 || $info[1] > 6000) {
        throw new RuntimeException('Размеры изображения не подходят.');
    }
    $alt = plain_text($_POST['alt'] ?? '', 300);
    if ($alt === '') throw new RuntimeException('Добавьте описание фотографии.');
    $directory = SITE_ROOT . '/assets/uploads';
    if (!is_dir($directory) && !mkdir($directory, 0755, true)) throw new RuntimeException('Не удалось создать папку фотографий.');
    $filename = 'photo-' . bin2hex(random_bytes(12)) . '.' . $extensions[$mime];
    $destination = $directory . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $destination)) throw new RuntimeException('Не удалось сохранить фотографию.');
    chmod($destination, 0644);
    $data['images'][$slot]['path'] = 'assets/uploads/' . $filename;
    $data['images'][$slot]['alt'] = $alt;
    publish_data($data);
    return $data;
}
