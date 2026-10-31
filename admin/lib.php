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

function safe_external_url(string $url): string {
    if ($url === '') return '';
    $parts = parse_url($url);
    if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
        || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
        || preg_match('/[\x00-\x20]/', $url) || strlen($url) > 500) {
        throw new RuntimeException('Ссылка должна начинаться с https:// и вести на сайт события.');
    }
    return $url;
}

function featured_events_html(array $data): string {
    $fields = $data['fields'];
    $cards = [];
    foreach ($data['featured_events'] ?? [] as $event) {
        if (empty($event['visible'])) continue;
        $image = empty($event['image']) ? '<div class="event-image empty" aria-hidden="true"></div>'
            : '<div class="event-image"><img src="' . h(safe_image_path((string) $event['image'])) . '" alt="' . h((string) $event['title']) . '" loading="lazy"></div>';
        $meta = trim((string) $event['date'] . (empty($event['category']) ? '' : ' · ' . $event['category']), " ·");
        $place = empty($event['place']) ? '' : '<div class="event-place">' . h((string) $event['place']) . '</div>';
        $description = empty($event['description']) ? '' : '<p>' . h((string) $event['description']) . '</p>';
        $url = safe_external_url((string) ($event['url'] ?? ''));
        $href = h($url === '' ? safe_external_url((string) $data['links']['channel']) : $url);
        $cards[] = '<a class="event-card" href="' . $href . '" target="_blank" rel="noopener noreferrer">' . $image . '<div class="event-body"><span class="event-meta">' . h($meta) . '</span><h3>' . h((string) $event['title']) . '</h3>' . $place . $description . '<span class="event-arrow" aria-hidden="true">↗</span></div></a>';
    }
    if (!$cards) return '';
    return '<section class="section upcoming reveal" id="upcoming" aria-labelledby="upcoming-title"><div class="container">'
        . '<div class="section-head"><div><span class="eyebrow">Афиша</span><h2 id="upcoming-title">' . h((string) $fields['upcoming_title']) . '</h2></div></div>'
        . '<div class="upcoming-grid">' . implode("\n        ", $cards) . '</div></div></section>';
}

function hero_event_html(array $data): string {
    foreach ($data['featured_events'] ?? [] as $event) {
        if (empty($event['visible'])) continue;
        $url = safe_external_url((string) ($event['url'] ?? ''));
        $href = $url === '' ? '#upcoming' : h($url);
        $external = $url === '' ? '' : ' target="_blank" rel="noopener noreferrer"';
        return '<div class="hero-event"><div class="container"><a href="' . $href . '"' . $external . '><span class="label">Ближайшее событие</span><strong>' . h((string) $event['title']) . '</strong><span class="event-date">' . h((string) $event['date']) . '</span><span class="event-arrow" aria-hidden="true">↗</span></a></div></div>';
    }
    return '';
}

function custom_blocks_html(array $data): string {
    $html = [];
    foreach ($data['custom_blocks'] ?? [] as $block) {
        if (empty($block['visible'])) continue;
        $image = empty($block['image']) ? '' : '<div><img src="' . h(safe_image_path((string) $block['image'])) . '" alt="' . h((string) $block['title']) . '" loading="lazy"></div>';
        $url = safe_external_url((string) ($block['url'] ?? ''));
        $button = ($url !== '' && !empty($block['button']))
            ? '<a class="button outline" href="' . h($url) . '" target="_blank" rel="noopener noreferrer">' . h((string) $block['button']) . ' <span class="arrow" aria-hidden="true">↗</span></a>' : '';
        $html[] = '<section class="section custom-block"><div class="container custom-block-grid' . ($image === '' ? ' no-image' : '') . '"><div>'
            . '<span class="eyebrow">' . h((string) $block['eyebrow']) . '</span><h2>' . h((string) $block['title']) . '</h2>'
            . '<p>' . h((string) $block['body']) . '</p>' . $button . '</div>' . $image . '</div></section>';
    }
    return implode("\n\n    ", $html);
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
        $replacements['{{' . $key . '}}'] = h($key === 'site_url' ? rtrim(safe_external_url((string) $value), '/') : (string) $value);
    }
    foreach ($data['images'] as $key => $image) {
        $replacements['{{image_' . $key . '_path}}'] = h(safe_image_path((string) $image['path']));
        $replacements['{{image_' . $key . '_alt}}'] = h((string) $image['alt']);
    }
    foreach ($data['links'] as $key => $url) {
        $replacements['{{url_' . $key . '}}'] = h(safe_external_url((string) $url));
    }
    foreach ($data['prices'] as $key => $rows) {
        $replacements['{{PRICE_' . strtoupper((string) $key) . '}}'] = price_rows($rows);
    }
    $replacements['{{FEATURED_EVENTS}}'] = featured_events_html($data);
    $replacements['{{HERO_EVENT}}'] = hero_event_html($data);
    $replacements['{{EVENTS_NAV}}'] = featured_events_html($data) === '' ? '' : '<a href="#upcoming">События</a>';
    $replacements['{{CUSTOM_BLOCKS}}'] = custom_blocks_html($data);
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
            if ($key === 'site_url') {
                $data['fields'][$key] = rtrim(safe_external_url($data['fields'][$key]), '/');
                if ($data['fields'][$key] === '') throw new RuntimeException('Укажите адрес сайта с https://.');
            }
        }
    }
    return $data;
}

function update_links(array $data, array $schema, mixed $posted): array {
    if (!is_array($posted)) throw new RuntimeException('Не найдены ссылки.');
    foreach ($schema['links'] as $key => $_label) {
        $url = safe_external_url(plain_text($posted[$key] ?? '', 500));
        if ($url === '') throw new RuntimeException('Заполните все контактные ссылки.');
        $data['links'][$key] = $url;
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

function update_featured_events(array $data, mixed $posted): array {
    if (!is_array($posted) || count($posted) > 31) throw new RuntimeException('Некорректный список событий.');
    $existing = array_column($data['featured_events'] ?? [], null, 'id');
    $events = [];
    $newEvents = [];
    foreach ($posted as $item) {
        if (!is_array($item) || !empty($item['delete'])) continue;
        $title = plain_text($item['title'] ?? '', 300);
        $category = plain_text($item['category'] ?? '', 100);
        $date = plain_text($item['date'] ?? '', 100);
        $place = plain_text($item['place'] ?? '', 200);
        $description = plain_text($item['description'] ?? '', 1200);
        $url = safe_external_url(plain_text($item['url'] ?? '', 500));
        if ($title === '' && $category === '' && $date === '' && $place === '' && $description === '' && $url === '') continue;
        if ($title === '') throw new RuntimeException('У события должно быть название.');
        $id = (string) ($item['id'] ?? '');
        if ($id !== '' && !isset($existing[$id])) throw new RuntimeException('Событие не найдено. Обновите страницу.');
        $isNew = $id === '';
        if ($isNew) $id = bin2hex(random_bytes(8));
        $record = compact('id', 'title', 'category', 'date', 'place', 'description', 'url')
            + ['image' => !empty($item['remove_image']) ? '' : (string) ($existing[$id]['image'] ?? ''), 'visible' => !empty($item['visible'])];
        if ($isNew) $newEvents[] = $record;
        else $events[] = $record;
    }
    $events = array_merge($newEvents, $events);
    if (count($events) > 30) throw new RuntimeException('Можно добавить не более 30 событий.');
    $data['featured_events'] = $events;
    return $data;
}

function update_custom_blocks(array $data, mixed $posted): array {
    if (!is_array($posted) || count($posted) > 21) throw new RuntimeException('Некорректный список блоков.');
    $existing = array_column($data['custom_blocks'] ?? [], null, 'id');
    $blocks = [];
    foreach ($posted as $item) {
        if (!is_array($item) || !empty($item['delete'])) continue;
        $eyebrow = plain_text($item['eyebrow'] ?? '', 100);
        $title = plain_text($item['title'] ?? '', 300);
        $body = plain_text($item['body'] ?? '', 2000);
        $button = plain_text($item['button'] ?? '', 100);
        $url = safe_external_url(plain_text($item['url'] ?? '', 500));
        if ($eyebrow === '' && $title === '' && $body === '' && $button === '' && $url === '') continue;
        if ($title === '' || $body === '') throw new RuntimeException('Дополнительному блоку нужны заголовок и текст.');
        if (($button === '') !== ($url === '')) throw new RuntimeException('Для кнопки заполните и подпись, и ссылку.');
        $id = (string) ($item['id'] ?? '');
        if ($id !== '' && !isset($existing[$id])) throw new RuntimeException('Блок не найден. Обновите страницу.');
        if ($id === '') $id = bin2hex(random_bytes(8));
        $blocks[] = compact('id', 'eyebrow', 'title', 'body', 'button', 'url')
            + ['image' => !empty($item['remove_image']) ? '' : (string) ($existing[$id]['image'] ?? ''), 'visible' => !empty($item['visible'])];
    }
    if (count($blocks) > 20) throw new RuntimeException('Можно добавить не более 20 блоков.');
    $data['custom_blocks'] = $blocks;
    return $data;
}

function upload_image(array $data, array $schema): array {
    $slot = $_POST['slot'] ?? '';
    if (!is_string($slot)) throw new RuntimeException('Выберите фотографию для замены.');
    $fixed = isset($schema['images'][$slot]);
    $eventId = str_starts_with($slot, 'event:') ? substr($slot, 6) : '';
    $blockId = str_starts_with($slot, 'block:') ? substr($slot, 6) : '';
    if (!$fixed && $eventId === '' && $blockId === '') throw new RuntimeException('Выберите фотографию для замены.');
    if ($eventId !== '' && !in_array($eventId, array_column($data['featured_events'] ?? [], 'id'), true)) throw new RuntimeException('Событие не найдено.');
    if ($blockId !== '' && !in_array($blockId, array_column($data['custom_blocks'] ?? [], 'id'), true)) throw new RuntimeException('Блок не найден.');
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
    $alt = $fixed ? plain_text($_POST['alt'] ?? '', 300) : '';
    if ($fixed && $alt === '') throw new RuntimeException('Добавьте описание фотографии.');
    $directory = SITE_ROOT . '/assets/uploads';
    if (!is_dir($directory) && !mkdir($directory, 0755, true)) throw new RuntimeException('Не удалось создать папку фотографий.');
    $filename = 'photo-' . bin2hex(random_bytes(12)) . '.' . $extensions[$mime];
    $destination = $directory . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $destination)) throw new RuntimeException('Не удалось сохранить фотографию.');
    chmod($destination, 0644);
    $path = 'assets/uploads/' . $filename;
    if ($fixed) {
        $data['images'][$slot]['path'] = $path;
        $data['images'][$slot]['alt'] = $alt;
    } elseif ($eventId !== '') {
        foreach ($data['featured_events'] as &$event) {
            if ($event['id'] === $eventId) { $event['image'] = $path; break; }
        }
        unset($event);
    } else {
        foreach ($data['custom_blocks'] as &$block) {
            if ($block['id'] === $blockId) { $block['image'] = $path; break; }
        }
        unset($block);
    }
    publish_data($data);
    return $data;
}
