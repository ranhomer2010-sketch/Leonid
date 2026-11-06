#!/usr/bin/env python3
"""Build the GitHub Pages preview from the same content as the PHP editor."""
from html import escape
from pathlib import Path
import json
import re

ROOT = Path(__file__).resolve().parent.parent
data = json.loads((ROOT / "content/site.json").read_text())
template = (ROOT / "site.template.html").read_text()

def h(value):
    return escape(str(value), quote=True).replace("&#x27;", "&#039;")

def safe_image(path):
    if not re.fullmatch(r"assets/(?:uploads/)?[A-Za-z0-9._-]+\.(?:jpe?g|png|webp)", path, re.I):
        raise ValueError("Invalid image path")
    return path

def safe_url(url):
    if url and (not url.startswith("https://") or re.search(r"\s", url)):
        raise ValueError("Invalid URL")
    return url

def price_rows(rows):
    result = []
    for row in rows:
        detail = " <small>" + h(row["detail"]) + "</small>" if row["detail"] else ""
        result.append('<div class="price-row"><div class="price-name">' + h(row["name"]) + detail
                      + '</div><div class="price-time">' + h(row["duration"])
                      + '</div><strong class="price-value">' + h(row["price"]) + '</strong></div>')
    return "\n            ".join(result)

def price_from(rows):
    amounts = [int(digits) for row in rows if (digits := re.sub(r"\D", "", row["price"])) and int(digits) > 0]
    return "от " + f"{min(amounts):,}".replace(",", " ") + " ₽" if amounts else ""

def upcoming():
    fields = data["fields"]
    cards = []
    for event in data["featured_events"]:
        if not event["visible"]:
            continue
        image = ('<div class="event-image empty" aria-hidden="true"></div>' if not event["image"] else
                 '<div class="event-image"><img src="' + h(safe_image(event["image"])) +
                 '" alt="' + h(event["title"]) + '" loading="lazy"></div>')
        meta = (event["date"] + (" · " + event["category"] if event["category"] else "")).strip(" ·")
        place = '<div class="event-place">' + h(event["place"]) + '</div>' if event["place"] else ""
        description = '<p>' + h(event["description"]) + '</p>' if event["description"] else ""
        url = safe_url(event["url"])
        href = h(url if url else safe_url(data["links"]["channel"]))
        cards.append('<a class="event-card" href="' + href + '" target="_blank" rel="noopener noreferrer">' + image + '<div class="event-body"><span class="event-meta">' + h(meta) + '</span><h3>' + h(event["title"]) + '</h3>' + place + description + '<span class="event-arrow" aria-hidden="true">↗</span></div></a>')
    if not cards:
        cards.append('<div class="event-empty"><p>' + h(fields['upcoming_empty']) + '</p><a href="' + h(safe_url(data['links']['channel'])) + '" target="_blank" rel="noopener noreferrer">' + h(fields['label_channel']) + ' ↗</a></div>')
    return ('<section class="section upcoming reveal" id="upcoming" aria-labelledby="upcoming-title"><div class="container">'
            + '<div class="section-head"><div><span class="section-index">' + h(fields["label_events"]) + '</span><h2 id="upcoming-title">' + h(fields["upcoming_title"]) + '</h2></div></div>'
            + '<div class="upcoming-grid">' + "\n        ".join(cards) + '</div></div></section>')

def hero_event():
    for event in data["featured_events"]:
        if not event["visible"]:
            continue
        url = safe_url(event["url"])
        href = h(url) if url else '#upcoming'
        external = ' target="_blank" rel="noopener noreferrer"' if url else ''
        image_path = event["image"] if event["image"] else data['images']['events']['path']
        image = '<img src="' + h(safe_image(image_path)) + '" alt="" loading="lazy">'
        return ('<div class="gallery-stage"><a class="gallery-card gallery-event" href="' + href + '"' + external + '>' + image
                + '<span class="gallery-label">' + h(data["fields"]["nav_events"]) + '</span>'
                + '<span class="gallery-event-title">' + h(event["title"]) + '</span>'
                + '<span class="gallery-arrow" aria-hidden="true">↗</span></a></div>')
    return ('<div class="gallery-stage"><a class="gallery-card gallery-event" href="#upcoming"><img src="' + h(safe_image(data['images']['events']['path'])) + '" alt="" loading="lazy"><span class="gallery-label">' + h(data['fields']['nav_events']) + '</span><span class="gallery-arrow" aria-hidden="true">↗</span></a></div>')

def extra_blocks():
    blocks = []
    for block in data["custom_blocks"]:
        if not block["visible"]:
            continue
        image = ('<div><img src="' + h(safe_image(block["image"])) + '" alt="' + h(block["title"]) + '" loading="lazy"></div>' if block["image"] else "")
        url = safe_url(block["url"])
        button = ('<a class="button outline" href="' + h(url) + '" target="_blank" rel="noopener noreferrer">' + h(block["button"]) + ' <span class="arrow" aria-hidden="true">↗</span></a>' if url and block["button"] else "")
        blocks.append('<section class="section custom-block"><div class="container custom-block-grid' + (' no-image' if not image else '') + '"><div>'
                      + '<span class="eyebrow">' + h(block["eyebrow"]) + '</span><h2>' + h(block["title"]) + '</h2>'
                      + '<p>' + h(block["body"]) + '</p>' + button + '</div>' + image + '</div></section>')
    return "\n\n    ".join(blocks)

def credentials():
    cards = []
    for item in data.get('credentials', []):
        if not item['visible'] or not item['image']:
            continue
        path = h(safe_image(item['image']))
        cards.append('<a class="credential-card" href="' + path + '" target="_blank" rel="noopener noreferrer"><div class="credential-image"><img src="' + path + '" alt="' + h(item['title']) + '" loading="lazy"></div><div class="credential-caption"><h3>' + h(item['title']) + '</h3><p>' + h(item['detail']) + '</p><span aria-hidden="true">↗</span></div></a>')
    if not cards:
        return ''
    return '<section class="section credentials reveal" id="credentials" aria-labelledby="credentials-title"><div class="container"><div class="section-head"><span class="section-index">' + h(data['fields']['label_credentials']) + '</span><h2 id="credentials-title">' + h(data['fields']['credentials_title']) + '</h2></div><div class="credentials-grid">' + ''.join(cards) + '</div></div></section>'

def reviews():
    cards = []
    for item in data.get('reviews', []):
        if not item['visible']:
            continue
        cards.append('<a class="review-card" href="' + h(safe_url(item['url'])) + '" target="_blank" rel="noopener noreferrer"><h3>' + h(item['title']) + '</h3><p>' + h(item['description']) + '</p><span aria-hidden="true">Читать в Telegram ↗</span></a>')
    if not cards:
        return ''
    return '<section class="section reviews reveal" id="reviews" aria-labelledby="reviews-title"><div class="container"><div class="section-head"><span class="section-index">' + h(data['fields']['label_reviews']) + '</span><h2 id="reviews-title">' + h(data['fields']['reviews_title']) + '</h2></div><p class="reviews-lead">' + h(data['fields']['reviews_lead']) + '</p><div class="reviews-grid">' + ''.join(cards) + '</div></div></section>'

values = {"{{" + key + "}}": h(value.rstrip("/") if key == "site_url" else value) for key, value in data["fields"].items()}
for key, image in data["images"].items():
    values["{{image_" + key + "_path}}"] = h(safe_image(image["path"]))
    values["{{image_" + key + "_alt}}"] = h(image["alt"])
for key, url in data["links"].items():
    values["{{url_" + key + "}}"] = h(safe_url(url))
for key, rows in data["prices"].items():
    values["{{PRICE_" + key.upper() + "}}"] = price_rows(rows)
    values["{{PRICE_" + key.upper() + "_FROM}}"] = h(price_from(rows))
values["{{FEATURED_EVENTS}}"] = upcoming()
values["{{HERO_EVENT}}"] = hero_event()
values["{{EVENTS_NAV}}"] = '<a href="#upcoming">' + h(data["fields"]["nav_events"]) + '</a>' if values["{{FEATURED_EVENTS}}"] else ''
values["{{CUSTOM_BLOCKS}}"] = extra_blocks()
values["{{CREDENTIALS}}"] = credentials()
values["{{REVIEWS}}"] = reviews()
for key, value in values.items():
    template = template.replace(key, value)
if re.search(r"\{\{[A-Za-z0-9_]+\}\}", template):
    raise SystemExit("Unrendered content token")
(ROOT / "index.html").write_text(template)
print("Rendered", ROOT / "index.html")
