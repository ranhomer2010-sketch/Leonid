<?php
declare(strict_types=1);
require __DIR__ . '/../admin/lib.php';

function expect(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$data = load_json(DATA_FILE);
$schema = load_json(SCHEMA_FILE);
$html = render_site($data);
expect(str_contains($html, 'id="upcoming"'), 'Missing homepage events section');
expect(str_contains($html, 'Пока ближайшие даты не опубликованы'), 'Missing empty state');
expect(!str_contains($html, '{{'), 'Unrendered placeholders');

$password = 'A-long-test-password-12345';
expect(verify_password($password, make_auth($password)), 'Password verification failed');
expect(!verify_password('wrong', make_auth($password)), 'Wrong password accepted');

$event = [
    'id' => '', 'title' => '<Конференция>', 'category' => 'Выступление',
    'date' => '12 ноября', 'place' => 'Москва', 'description' => 'О практике',
    'url' => 'https://example.org/event', 'visible' => '1',
];
$withEvent = update_featured_events($data, [$event]);
$eventHtml = render_site($withEvent);
expect(str_contains($eventHtml, '&lt;Конференция&gt;'), 'Event title was not escaped');
expect(str_contains($eventHtml, 'https://example.org/event'), 'Event URL missing');
expect(!str_contains($eventHtml, '<Конференция>'), 'Raw event title leaked');
$hidden = $event;
$hidden['visible'] = '';
$hiddenHtml = render_site(update_featured_events($data, [$hidden]));
expect(!str_contains($hiddenHtml, 'Выступление'), 'Hidden event leaked');

$block = [
    'id' => '', 'eyebrow' => 'Направление', 'title' => 'Новый формат',
    'body' => 'Описание формата', 'button' => 'Узнать больше',
    'url' => 'https://example.org/info', 'visible' => '1',
];
$withBlock = update_custom_blocks($data, [$block]);
expect(str_contains(render_site($withBlock), 'Новый формат'), 'Custom section missing');

$sameFields = update_fields($data, $schema, $data['fields']);
expect($sameFields['fields'] === $data['fields'], 'Fields round trip failed');
$samePrices = update_prices($data, $schema, $data['prices']);
expect($samePrices['prices'] === $data['prices'], 'Prices round trip failed');
$sameLinks = update_links($data, $schema, $data['links']);
expect($sameLinks['links'] === $data['links'], 'Links round trip failed');
echo "CMS checks OK\n";
