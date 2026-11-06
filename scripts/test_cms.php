<?php
declare(strict_types=1);
require __DIR__ . '/../admin/lib.php';

function expect(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$data = load_json(DATA_FILE);
$schema = load_json(SCHEMA_FILE);
$html = render_site($data);
expect(str_contains($html, 'id="upcoming"'), 'Events section should be visible');
expect(str_contains($html, 'gallery-event'), 'Events navigation should be visible');
expect(str_contains($html, 'Декабрьское событие'), 'December event missing');
expect(str_contains($html, 'id="credentials"'), 'Credentials section missing');
expect(str_contains($html, 'cert-sadhu.jpg'), 'Certificate image missing');
expect(str_contains($html, 'id="reviews"'), 'Reviews section missing');
expect(str_contains($html, 'gallery-contact'), 'Contact navigation missing');
expect(substr_count($html, '<details class="price-category"') === 4, 'Price groups should expand on click');
expect(str_contains($html, 'от 1 800 ₽'), 'Starting price missing');
expect(str_contains($html, 'Пространство для массажа'), 'Massage room title missing');
expect(str_contains($html, 'class="about-photo"'), 'Leonid portrait missing from biography');
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
expect(str_contains($eventHtml, 'gallery-event'), 'Hero event missing');
expect(str_contains($eventHtml, 'id="upcoming"'), 'Event section missing');
expect(!str_contains($eventHtml, '<Конференция>'), 'Raw event title leaked');
$hidden = $event;
$hidden['visible'] = '';
$hiddenHtml = render_site(update_featured_events($data, [$hidden]));
expect(!str_contains($hiddenHtml, 'Выступление'), 'Hidden event leaked');
expect(str_contains($hiddenHtml, 'id="upcoming"'), 'Events section should remain visible');
$emptyEvents = $data;
$emptyEvents['featured_events'] = [];
expect(str_contains(render_site($emptyEvents), 'Сейчас нет опубликованных событий'), 'Empty events message missing');

$document = ['id' => '', 'title' => '<Сертификат>', 'detail' => 'Тест', 'visible' => '1'];
$withDocument = update_credentials($data, [$document]);
expect(count($withDocument['credentials']) === 1, 'Document editor should add a record');
$withDocument['credentials'][0]['image'] = 'assets/cert-sadhu.jpg';
expect(str_contains(render_site($withDocument), '&lt;Сертификат&gt;'), 'Document title should be escaped');
expect(!str_contains(render_site($withDocument), '<Сертификат>'), 'Document title leaked');
$review = ['id' => '', 'title' => 'Отзыв', 'description' => 'Опыт участника', 'url' => 'https://example.org/review', 'visible' => '1'];
$withReview = update_reviews($data, [$review]);
expect(str_contains(render_site($withReview), 'https://example.org/review'), 'Review link missing');

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
$changedPrices = $data;
$changedPrices['prices']['massage'][0]['price'] = '500 ₽';
expect(str_contains(render_site($changedPrices), 'от 500 ₽'), 'Starting price should update with admin price');
$sameLinks = update_links($data, $schema, $data['links']);
expect($sameLinks['links'] === $data['links'], 'Links round trip failed');
echo "CMS checks OK\n";
