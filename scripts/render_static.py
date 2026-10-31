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

def upcoming():
    fields = data["fields"]
    channel = h(safe_url(data["links"]["channel"]))
    cards = []
    for event in data["featured_events"]:
        if not event["visible"]:
            continue
        image = ('<div class="event-image empty" aria-hidden="true">✳</div>' if not event["image"] else
                 '<div class="event-image"><img src="' + h(safe_image(event["image"])) +
                 '" alt="' + h(event["title"]) + '" loading="lazy"></div>')
        meta = (event["date"] + (" · " + event["category"] if event["category"] else "")).strip(" ·")
        place = '<div class="event-place">' + h(event["place"]) + '</div>' if event["place"] else ""
        description = '<p>' + h(event["description"]) + '</p>' if event["description"] else ""
        url = safe_url(event["url"])
        link = ('<a class="text-link" href="' + h(url) + '" target="_blank" rel="noopener noreferrer">Подробнее <span aria-hidden="true">↗</span></a>' if url else "")
        cards.append('<article class="event-card">' + image + '<div class="event-body"><span class="event-meta">' + h(meta) + '</span><h3>' + h(event["title"]) + '</h3>' + place + description + link + '</div></article>')
    content = ('<div class="upcoming-grid">' + "\n        ".join(cards) + '</div>' if cards else
               '<div class="upcoming-empty"><p>Пока ближайшие даты не опубликованы. Анонсы практик можно посмотреть в <a href="' + channel + '" target="_blank" rel="noopener noreferrer">Telegram-канале Леонида</a>.</p></div>')
    return ('<section class="section upcoming" id="upcoming" aria-labelledby="upcoming-title"><div class="container">'
            + '<div class="upcoming-layout"><div class="section-intro"><span class="eyebrow">Афиша Леонида</span><h2 id="upcoming-title">' + h(fields["upcoming_title"]) + '</h2><p>' + h(fields["upcoming_lead"]) + '</p></div>'
            + '<a class="text-link" href="' + channel + '" target="_blank" rel="noopener noreferrer">Все анонсы <span aria-hidden="true">↗</span></a></div>'
            + content + '</div></section>')

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

values = {"{{" + key + "}}": h(value.rstrip("/") if key == "site_url" else value) for key, value in data["fields"].items()}
for key, image in data["images"].items():
    values["{{image_" + key + "_path}}"] = h(safe_image(image["path"]))
    values["{{image_" + key + "_alt}}"] = h(image["alt"])
for key, url in data["links"].items():
    values["{{url_" + key + "}}"] = h(safe_url(url))
for key, rows in data["prices"].items():
    values["{{PRICE_" + key.upper() + "}}"] = price_rows(rows)
values["{{FEATURED_EVENTS}}"] = upcoming()
values["{{CUSTOM_BLOCKS}}"] = extra_blocks()
for key, value in values.items():
    template = template.replace(key, value)
if re.search(r"\{\{[A-Za-z0-9_]+\}\}", template):
    raise SystemExit("Unrendered content token")
(ROOT / "index.html").write_text(template)
print("Rendered", ROOT / "index.html")
