<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

admin_headers();
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
if (!$https) {
    http_response_code(403);
    exit('Админка доступна только через HTTPS.');
}
start_admin_session();

$error = '';
$configured = is_file(AUTH_FILE);
$schema = load_json(SCHEMA_FILE);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $configured) {
    try {
        check_csrf();
        $action = $_POST['action'] ?? '';
        if ($action === 'login') {
            if (login_limited()) throw new RuntimeException('Слишком много попыток. Повторите через 15 минут.');
            $password = $_POST['password'] ?? '';
            if (!is_string($password) || !verify_password($password, read_auth())) {
                login_limited(true);
                throw new RuntimeException('Неверный пароль.');
            }
            login_limited(false, true);
            session_regenerate_id(true);
            $_SESSION['logged_in'] = true;
            $_SESSION['last_activity'] = time();
            header('Location: ./');
            exit;
        }
        if (!logged_in()) throw new RuntimeException('Войдите в админку заново.');
        if ($action === 'logout') {
            $_SESSION = [];
            session_destroy();
            header('Location: ./');
            exit;
        }
        if ($action === 'change_password') {
            $old = $_POST['old_password'] ?? '';
            $new = $_POST['new_password'] ?? '';
            if (!is_string($old) || !verify_password($old, read_auth())) throw new RuntimeException('Текущий пароль неверный.');
            if (!is_string($new) || preg_match_all('/./us', $new) < 16 || strlen($new) > 256) {
                throw new RuntimeException('Новый пароль должен содержать не менее 16 символов.');
            }
            write_auth(make_auth($new));
            session_regenerate_id(true);
            $_SESSION['notice'] = 'Пароль изменён.';
        } elseif ($action === 'save_fields') {
            $data = update_fields(load_json(DATA_FILE), $schema, $_POST['fields'] ?? null);
            publish_data($data);
            $_SESSION['notice'] = 'Тексты сохранены и опубликованы.';
        } elseif ($action === 'save_links') {
            $data = update_links(load_json(DATA_FILE), $schema, $_POST['links'] ?? null);
            publish_data($data);
            $_SESSION['notice'] = 'Контактные ссылки сохранены и опубликованы.';
        } elseif ($action === 'save_prices') {
            $data = update_prices(load_json(DATA_FILE), $schema, $_POST['prices'] ?? null);
            publish_data($data);
            $_SESSION['notice'] = 'Цены сохранены и опубликованы.';
        } elseif ($action === 'save_events') {
            $data = update_featured_events(load_json(DATA_FILE), $_POST['events'] ?? null);
            publish_data($data);
            $_SESSION['notice'] = 'Афиша сохранена и опубликована.';
        } elseif ($action === 'save_credentials') {
            $data = update_credentials(load_json(DATA_FILE), $_POST['credentials'] ?? null);
            publish_data($data);
            $_SESSION['notice'] = 'Документы сохранены и опубликованы.';
        } elseif ($action === 'save_reviews') {
            $data = update_reviews(load_json(DATA_FILE), $_POST['reviews'] ?? null);
            publish_data($data);
            $_SESSION['notice'] = 'Отзывы сохранены и опубликованы.';
        } elseif ($action === 'save_blocks') {
            $data = update_custom_blocks(load_json(DATA_FILE), $_POST['blocks'] ?? null);
            publish_data($data);
            $_SESSION['notice'] = 'Дополнительные блоки сохранены и опубликованы.';
        } elseif ($action === 'upload_image') {
            upload_image(load_json(DATA_FILE), $schema);
            $_SESSION['notice'] = 'Фотография заменена и опубликована.';
        } else {
            throw new RuntimeException('Неизвестное действие.');
        }
        header('Location: ./');
        exit;
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Leonid admin error: ' . get_class($e));
        $error = 'Не удалось выполнить действие. Проверьте настройки хостинга.';
    }
}

$notice = $_SESSION['notice'] ?? '';
unset($_SESSION['notice']);
$authenticated = $configured && logged_in();
$data = $authenticated ? load_json(DATA_FILE) : null;
$csrf = csrf_token();
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow,noarchive">
  <title>Редактор сайта · Леонид Агафонов</title>
  <style>
    * { box-sizing: border-box; }
    body { margin: 0; background: #f2f5f4; color: #2d3e43; font: 16px/1.5 Arial, sans-serif; }
    a { color: #2e625c; }
    .wrap { width: min(1060px, calc(100% - 32px)); margin: 0 auto; }
    .top { background: #e1e9e6; border-bottom: 1px solid #cddad5; }
    .top-inner { display: flex; justify-content: space-between; align-items: center; gap: 1rem; padding: 18px 0; }
    .top strong { font-size: 1.1rem; }
    .top small { display: block; color: #647277; font-size: .77rem; }
    .top nav { display: flex; align-items: center; gap: 1rem; }
    main { padding: 38px 0 80px; }
    h1 { margin: 0 0 10px; font-size: clamp(2rem, 4vw, 3rem); letter-spacing: -.04em; line-height: 1.1; }
    h2 { margin: 0 0 10px; font-size: 1.4rem; }
    h3 { margin: 0 0 8px; font-size: 1.05rem; }
    p { margin-top: 0; color: #5c6d70; }
    .intro { max-width: 710px; margin-bottom: 30px; }
    .card { padding: clamp(18px, 3vw, 32px); margin-bottom: 18px; background: white; border: 1px solid #d8e1df; border-radius: 16px; }
    .login { max-width: 460px; margin: 6vh auto; }
    .notice, .error { margin-bottom: 20px; padding: 14px 18px; border-radius: 9px; }
    .notice { background: #e1f1e7; color: #24523c; }
    .error { background: #fff0ed; color: #883d33; }
    label { display: block; margin-bottom: 15px; color: #3a5054; font-size: .86rem; font-weight: 700; }
    input[type="text"], input[type="password"], input[type="file"], textarea { display: block; width: 100%; margin-top: 7px; padding: 11px 12px; border: 1px solid #bdceca; border-radius: 8px; background: #fff; color: #283a3e; font: inherit; font-weight: 400; }
    textarea { min-height: 96px; resize: vertical; }
    input:focus, textarea:focus, summary:focus-visible, button:focus-visible, a:focus-visible { outline: 3px solid #76aaa0; outline-offset: 2px; }
    button, .button { display: inline-flex; align-items: center; justify-content: center; min-height: 44px; padding: 10px 18px; border: 1px solid #35645e; border-radius: 100px; background: #35645e; color: white; font: inherit; font-size: .88rem; font-weight: 700; cursor: pointer; text-decoration: none; }
    button:hover, .button:hover { background: #244e49; }
    button.subtle { min-height: 36px; padding: 6px 13px; color: #35645e; background: transparent; }
    .sections { display: flex; gap: .5rem; flex-wrap: wrap; margin-bottom: 24px; }
    .sections a { padding: 7px 12px; border: 1px solid #cbd8d4; border-radius: 30px; text-decoration: none; font-size: .82rem; }
    details { border-top: 1px solid #dde5e2; }
    details:last-of-type { border-bottom: 1px solid #dde5e2; }
    summary { padding: 16px 2px; cursor: pointer; font-weight: 700; }
    .fields { display: grid; grid-template-columns: 1fr 1fr; gap: 0 18px; padding: 5px 0 12px; }
    .fields .wide { grid-column: 1 / -1; }
    .price-group { margin-top: 28px; }
    .price-edit { display: grid; grid-template-columns: minmax(0, 1.3fr) minmax(0, 1fr) 110px 115px 70px; gap: 10px; align-items: end; padding: 12px 0; border-top: 1px solid #e2e9e6; }
    .price-edit label { margin: 0; font-size: .76rem; }
    .price-edit input { min-width: 0; }
    .price-edit .remove { text-align: center; align-self: center; }
    .price-edit .remove input { width: auto; margin: 12px auto 0; }
    .photo-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; }
    .photo-grid form { padding: 16px; border: 1px solid #dbe5e1; border-radius: 10px; }
    .photo-grid img { display: block; width: 100%; height: 160px; margin: 12px 0; object-fit: cover; object-position: top; border-radius: 7px; background: #e3e9e7; }
    .photo-grid button { width: 100%; }
    .item-editor { padding: 0 0 10px; }
    .item-editor summary { font-size: 1rem; }
    .item-editor .fields { padding-top: 16px; }
    .item-editor .checks { display: flex; gap: 1.5rem; margin: 5px 0 18px; }
    .item-editor .checks label { display: flex; gap: .5rem; align-items: center; margin: 0; }
    .item-editor .checks input { margin: 0; }
    .upload-mini { display: flex; align-items: end; gap: 1rem; padding: 14px 0; border-top: 1px solid #e2e9e6; }
    .upload-mini label { flex: 1; margin: 0; }
    .upload-mini img { width: 100px; height: 70px; border-radius: 7px; object-fit: cover; }
    .upload-mini button { flex-shrink: 0; }
    .hint { margin: 12px 0 0; font-size: .81rem; color: #68777b; }
    @media (max-width: 800px) { .price-edit { grid-template-columns: 1fr 1fr; } .price-edit label:first-child { grid-column: 1 / -1; } .photo-grid { grid-template-columns: 1fr; } }
    @media (max-width: 600px) { .fields { grid-template-columns: 1fr; } .fields .wide { grid-column: auto; } .top-inner { align-items: flex-start; } .top nav { flex-direction: column; align-items: flex-end; } .upload-mini { align-items: stretch; flex-direction: column; } }
  </style>
</head>
<body>
<header class="top"><div class="wrap top-inner"><div><strong>Леонид Агафонов</strong><small>Редактор сайта</small></div><nav><a href="../" target="_blank" rel="noopener noreferrer">Открыть сайт ↗</a><?php if ($authenticated): ?><form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="logout"><button class="subtle" type="submit">Выйти</button></form><?php endif; ?></nav></div></header>
<main class="wrap">
  <?php if ($error !== ''): ?><div class="error" role="alert"><?= h($error) ?></div><?php endif; ?>
  <?php if (is_string($notice) && $notice !== ''): ?><div class="notice" role="status"><?= h($notice) ?></div><?php endif; ?>
  <?php if (!$configured): ?>
    <section class="card login"><h1>Настройка доступа</h1><p>Файл доступа ещё не установлен. Используйте подготовленный пакет для PHP-хостинга; пароль в публичном репозитории не хранится.</p></section>
  <?php elseif (!$authenticated): ?>
    <section class="card login"><h1>Вход в админку</h1><p>Редактирование текстов, цен и фотографий.</p><form method="post" autocomplete="off"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="login"><label>Пароль<input type="password" name="password" autocomplete="current-password" required autofocus></label><button type="submit">Войти</button></form></section>
  <?php else: ?>
    <h1>Управление сайтом</h1><p class="intro">Сохранили раздел — изменения сразу появятся на сайте. Перед публикацией проверьте тексты и цены.</p>
    <nav class="sections" aria-label="Разделы редактора"><a href="#texts">Тексты</a><a href="#prices">Цены</a><a href="#announcements">События</a><a href="#credentials-editor">Документы</a><a href="#reviews-editor">Отзывы</a><a href="#extra">Новые блоки</a><a href="#photos">Фотографии</a><a href="#links">Ссылки</a><a href="#security">Пароль</a></nav>

    <section class="card" id="texts">
      <h2>Тексты</h2><p>Редактируйте формулировки без HTML-разметки.</p>
      <form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="save_fields">
        <?php foreach ($schema['groups'] as $groupName => $fields): ?>
          <details><summary><?= h((string) $groupName) ?></summary><div class="fields">
            <?php foreach ($fields as $field): $key = $field['key']; $value = (string) ($data['fields'][$key] ?? ''); ?>
              <label class="<?= $field['type'] === 'textarea' ? 'wide' : '' ?>"><?= h((string) $field['label']) ?>
                <?php if ($field['type'] === 'textarea'): ?><textarea name="fields[<?= h($key) ?>]" maxlength="1500"><?= h($value) ?></textarea>
                <?php else: ?><input type="text" name="fields[<?= h($key) ?>]" value="<?= h($value) ?>" maxlength="250"><?php endif; ?>
              </label>
            <?php endforeach; ?>
          </div></details>
        <?php endforeach; ?>
        <p class="hint">При сохранении обновятся все текстовые разделы. Пустые поля тоже сохраняются.</p><button type="submit">Сохранить тексты</button>
      </form>
    </section>

    <section class="card" id="prices">
      <h2>Услуги и цены</h2><p>Меняйте название, описание, длительность и цену. Для новой услуги заполните пустую строку в конце нужного раздела.</p>
      <form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="save_prices">
        <?php foreach ($schema['price_groups'] as $groupKey => $groupLabel): ?>
          <div class="price-group"><h3><?= h((string) $groupLabel) ?></h3>
            <?php $rows = $data['prices'][$groupKey] ?? []; $rows[] = ['name' => '', 'detail' => '', 'duration' => '', 'price' => '']; ?>
            <?php foreach ($rows as $i => $row): ?>
              <div class="price-edit">
                <label>Услуга<input type="text" name="prices[<?= h($groupKey) ?>][<?= $i ?>][name]" value="<?= h((string) ($row['name'] ?? '')) ?>" maxlength="150"></label>
                <label>Описание<input type="text" name="prices[<?= h($groupKey) ?>][<?= $i ?>][detail]" value="<?= h((string) ($row['detail'] ?? '')) ?>" maxlength="200"></label>
                <label>Время<input type="text" name="prices[<?= h($groupKey) ?>][<?= $i ?>][duration]" value="<?= h((string) ($row['duration'] ?? '')) ?>" maxlength="40"></label>
                <label>Цена<input type="text" name="prices[<?= h($groupKey) ?>][<?= $i ?>][price]" value="<?= h((string) ($row['price'] ?? '')) ?>" maxlength="40"></label>
                <label class="remove">Удалить<input type="checkbox" name="prices[<?= h($groupKey) ?>][<?= $i ?>][delete]" value="1"></label>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
        <button type="submit">Сохранить цены</button>
      </form>
    </section>

    <section class="card" id="announcements">
      <h2>События на главной</h2><p>Конференции, выступления, практики и ретриты. Можно сохранить событие скрытым и опубликовать позже.</p>
      <form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="save_events">
        <?php $events = $data['featured_events'] ?? []; $events[] = ['id' => '', 'title' => '', 'category' => '', 'date' => '', 'place' => '', 'description' => '', 'url' => '', 'visible' => true]; ?>
        <?php foreach ($events as $i => $event): ?>
          <details class="item-editor" <?= $event['id'] === '' ? 'open' : '' ?>><summary><?= $event['id'] === '' ? 'Добавить событие' : h((string) $event['title']) ?></summary>
            <input type="hidden" name="events[<?= $i ?>][id]" value="<?= h((string) $event['id']) ?>">
            <div class="fields">
              <label>Название<input type="text" name="events[<?= $i ?>][title]" value="<?= h((string) $event['title']) ?>" maxlength="150"></label>
              <label>Тип, например «конференция»<input type="text" name="events[<?= $i ?>][category]" value="<?= h((string) $event['category']) ?>" maxlength="50"></label>
              <label>Дата или период<input type="text" name="events[<?= $i ?>][date]" value="<?= h((string) $event['date']) ?>" maxlength="50" placeholder="12 ноября 2026"></label>
              <label>Город или площадка<input type="text" name="events[<?= $i ?>][place]" value="<?= h((string) $event['place']) ?>" maxlength="100"></label>
              <label class="wide">Краткое описание<textarea name="events[<?= $i ?>][description]" maxlength="600"><?= h((string) $event['description']) ?></textarea></label>
              <label class="wide">Ссылка на программу или запись, https://<input type="text" name="events[<?= $i ?>][url]" value="<?= h((string) $event['url']) ?>" maxlength="500"></label>
            </div>
            <div class="checks"><label><input type="checkbox" name="events[<?= $i ?>][visible]" value="1" <?= !empty($event['visible']) ? 'checked' : '' ?>> Показывать на сайте</label><?php if ($event['id'] !== ''): ?><label><input type="checkbox" name="events[<?= $i ?>][delete]" value="1"> Удалить</label><label><input type="checkbox" name="events[<?= $i ?>][remove_image]" value="1"> Убрать афишу</label><?php endif; ?></div>
          </details>
        <?php endforeach; ?>
        <button type="submit">Сохранить события</button>
      </form>
      <?php foreach ($data['featured_events'] ?? [] as $event): ?>
        <form class="upload-mini" method="post" enctype="multipart/form-data">
          <?php if (!empty($event['image'])): ?><img src="../<?= h(safe_image_path((string) $event['image'])) ?>" alt="Афиша события"><?php endif; ?>
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="upload_image"><input type="hidden" name="slot" value="event:<?= h((string) $event['id']) ?>">
          <label>Афиша: <?= h((string) $event['title']) ?><input type="file" name="image" accept="image/jpeg,image/png,image/webp" required></label><button type="submit">Загрузить фото</button>
        </form>
      <?php endforeach; ?>
      <p class="hint">Новое событие сначала сохраните, затем можно загрузить его афишу. Без фото карточка тоже будет выглядеть аккуратно.</p>
    </section>

    <section class="card" id="credentials-editor">
      <h2>Документы и сертификаты</h2><p>Название, подпись и видимость можно менять. Для нового документа сначала сохраните карточку, затем загрузите его изображение.</p>
      <form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="save_credentials">
        <?php $credentials = $data['credentials'] ?? []; $credentials[] = ['id' => '', 'title' => '', 'detail' => '', 'visible' => true]; ?>
        <?php foreach ($credentials as $i => $item): ?>
          <details class="item-editor" <?= $item['id'] === '' ? 'open' : '' ?>><summary><?= $item['id'] === '' ? 'Добавить документ' : h((string) $item['title']) ?></summary>
            <input type="hidden" name="credentials[<?= $i ?>][id]" value="<?= h((string) $item['id']) ?>"><div class="fields">
              <label>Название<input type="text" name="credentials[<?= $i ?>][title]" value="<?= h((string) $item['title']) ?>" maxlength="150"></label>
              <label>Подпись<input type="text" name="credentials[<?= $i ?>][detail]" value="<?= h((string) $item['detail']) ?>" maxlength="300"></label>
            </div><div class="checks"><label><input type="checkbox" name="credentials[<?= $i ?>][visible]" value="1" <?= !empty($item['visible']) ? 'checked' : '' ?>> Показывать</label><?php if ($item['id'] !== ''): ?><label><input type="checkbox" name="credentials[<?= $i ?>][delete]" value="1"> Удалить</label><label><input type="checkbox" name="credentials[<?= $i ?>][remove_image]" value="1"> Убрать фото</label><?php endif; ?></div>
          </details>
        <?php endforeach; ?><button type="submit">Сохранить документы</button>
      </form>
      <?php foreach ($data['credentials'] ?? [] as $item): ?><form class="upload-mini" method="post" enctype="multipart/form-data">
        <?php if (!empty($item['image'])): ?><img src="../<?= h(safe_image_path((string) $item['image'])) ?>" alt="Документ"><?php endif; ?>
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="upload_image"><input type="hidden" name="slot" value="credential:<?= h((string) $item['id']) ?>">
        <label>Изображение: <?= h((string) $item['title']) ?><input type="file" name="image" accept="image/jpeg,image/png,image/webp" required></label><button type="submit">Загрузить</button>
      </form><?php endforeach; ?>
    </section>

    <section class="card" id="reviews-editor">
      <h2>Отзывы</h2><p>Добавляйте ссылки на публикации с отзывами. На сайте показывается краткое описание и ссылка на оригинал.</p>
      <form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="save_reviews">
        <?php $reviews = $data['reviews'] ?? []; $reviews[] = ['id' => '', 'title' => '', 'description' => '', 'url' => '', 'visible' => true]; ?>
        <?php foreach ($reviews as $i => $item): ?><details class="item-editor" <?= $item['id'] === '' ? 'open' : '' ?>><summary><?= $item['id'] === '' ? 'Добавить отзыв' : h((string) $item['title']) ?></summary>
          <input type="hidden" name="reviews[<?= $i ?>][id]" value="<?= h((string) $item['id']) ?>"><div class="fields">
            <label>Название<input type="text" name="reviews[<?= $i ?>][title]" value="<?= h((string) $item['title']) ?>" maxlength="150"></label>
            <label>Ссылка на публикацию<input type="text" name="reviews[<?= $i ?>][url]" value="<?= h((string) $item['url']) ?>" maxlength="500"></label>
            <label class="wide">Краткое описание<textarea name="reviews[<?= $i ?>][description]" maxlength="500"><?= h((string) $item['description']) ?></textarea></label>
          </div><div class="checks"><label><input type="checkbox" name="reviews[<?= $i ?>][visible]" value="1" <?= !empty($item['visible']) ? 'checked' : '' ?>> Показывать</label><?php if ($item['id'] !== ''): ?><label><input type="checkbox" name="reviews[<?= $i ?>][delete]" value="1"> Удалить</label><?php endif; ?></div>
        </details><?php endforeach; ?><button type="submit">Сохранить отзывы</button>
      </form>
    </section>

    <section class="card" id="extra">
      <h2>Дополнительные разделы</h2><p>Для новых направлений или материалов можно добавить отдельный блок с текстом, фотографией и кнопкой.</p>
      <form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="save_blocks">
        <?php $blocks = $data['custom_blocks'] ?? []; $blocks[] = ['id' => '', 'eyebrow' => '', 'title' => '', 'body' => '', 'button' => '', 'url' => '', 'visible' => true]; ?>
        <?php foreach ($blocks as $i => $block): ?>
          <details class="item-editor" <?= $block['id'] === '' ? 'open' : '' ?>><summary><?= $block['id'] === '' ? 'Добавить раздел' : h((string) $block['title']) ?></summary>
            <input type="hidden" name="blocks[<?= $i ?>][id]" value="<?= h((string) $block['id']) ?>">
            <div class="fields">
              <label>Короткая подпись<input type="text" name="blocks[<?= $i ?>][eyebrow]" value="<?= h((string) $block['eyebrow']) ?>" maxlength="50"></label>
              <label>Заголовок<input type="text" name="blocks[<?= $i ?>][title]" value="<?= h((string) $block['title']) ?>" maxlength="150"></label>
              <label class="wide">Текст<textarea name="blocks[<?= $i ?>][body]" maxlength="1000"><?= h((string) $block['body']) ?></textarea></label>
              <label>Текст кнопки, если нужна<input type="text" name="blocks[<?= $i ?>][button]" value="<?= h((string) $block['button']) ?>" maxlength="50"></label>
              <label>Ссылка кнопки, https://<input type="text" name="blocks[<?= $i ?>][url]" value="<?= h((string) $block['url']) ?>" maxlength="500"></label>
            </div>
            <div class="checks"><label><input type="checkbox" name="blocks[<?= $i ?>][visible]" value="1" <?= !empty($block['visible']) ? 'checked' : '' ?>> Показывать на сайте</label><?php if ($block['id'] !== ''): ?><label><input type="checkbox" name="blocks[<?= $i ?>][delete]" value="1"> Удалить</label><label><input type="checkbox" name="blocks[<?= $i ?>][remove_image]" value="1"> Убрать фото</label><?php endif; ?></div>
          </details>
        <?php endforeach; ?>
        <button type="submit">Сохранить разделы</button>
      </form>
      <?php foreach ($data['custom_blocks'] ?? [] as $block): ?>
        <form class="upload-mini" method="post" enctype="multipart/form-data">
          <?php if (!empty($block['image'])): ?><img src="../<?= h(safe_image_path((string) $block['image'])) ?>" alt="Фото раздела"><?php endif; ?>
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="upload_image"><input type="hidden" name="slot" value="block:<?= h((string) $block['id']) ?>">
          <label>Фото раздела: <?= h((string) $block['title']) ?><input type="file" name="image" accept="image/jpeg,image/png,image/webp" required></label><button type="submit">Загрузить фото</button>
        </form>
      <?php endforeach; ?>
    </section>

    <section class="card" id="photos">
      <h2>Фотографии</h2><p>JPG, PNG или WebP до 5 МБ. После загрузки фотография сразу заменится на сайте.</p>
      <div class="photo-grid">
        <?php foreach ($schema['images'] as $slot => $label): $photo = $data['images'][$slot]; ?>
          <form method="post" enctype="multipart/form-data">
            <h3><?= h((string) $label) ?></h3><img src="../<?= h(safe_image_path((string) $photo['path'])) ?>" alt="<?= h((string) $photo['alt']) ?>">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="upload_image"><input type="hidden" name="slot" value="<?= h((string) $slot) ?>">
            <label>Новое фото<input type="file" name="image" accept="image/jpeg,image/png,image/webp" required></label>
            <label>Описание для доступности<input type="text" name="alt" value="<?= h((string) $photo['alt']) ?>" maxlength="150" required></label>
            <button type="submit">Заменить фото</button>
          </form>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="card" id="links"><h2>Контактные ссылки</h2><p>Они обновятся во всех кнопках сайта, включая афишу и ответы на вопросы.</p>
      <form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="save_links">
        <?php foreach ($schema['links'] as $key => $label): ?><label><?= h((string) $label) ?><input type="text" name="links[<?= h((string) $key) ?>]" value="<?= h((string) $data['links'][$key]) ?>" maxlength="500" required></label><?php endforeach; ?>
        <button type="submit">Сохранить ссылки</button>
      </form>
    </section>

    <section class="card" id="security"><h2>Сменить пароль</h2><form method="post" autocomplete="off"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="change_password"><label>Текущий пароль<input type="password" name="old_password" autocomplete="current-password" required></label><label>Новый пароль, от 16 символов<input type="password" name="new_password" autocomplete="new-password" minlength="16" required></label><button type="submit">Изменить пароль</button></form></section>
  <?php endif; ?>
</main>
</body>
</html>
