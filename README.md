# Statamic JSON-LD

Configure and render JSON-LD schema for Statamic 6 sites.

## Installation

```bash
composer require grizzlypumpkin/statamic-json-ld
```

## Usage

Configure the addon from **Tools > Addons > JSON-LD** in the Statamic Control Panel.

Place the tag where the schema should be rendered, usually inside the document `<head>`:

```antlers
{{ json_ld }}
```

Blade:

```blade
{{ Statamic::tag('json_ld') }}
```

The tag renders a single `<script type="application/ld+json">` block containing a Schema.org `@graph`.

# TODO