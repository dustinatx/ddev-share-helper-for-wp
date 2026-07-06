# DDEV Share Helper for WordPress

[![tests](https://github.com/dustinatx/ddev-share-helper-for-wp/actions/workflows/tests.yml/badge.svg)](https://github.com/dustinatx/ddev-share-helper-for-wp/actions/workflows/tests.yml)
[![project is maintained](https://img.shields.io/maintenance/yes/2026.svg)](https://github.com/dustinatx/ddev-share-helper-for-wp)

A [DDEV](https://ddev.com) add-on for WordPress that makes `ddev share` (cloudflared/ngrok tunnels) work out of the box.

## What it does

Normally, sharing a local WordPress DDEV site over a tunnel breaks: WordPress
generates every URL (assets, links, redirects, REST endpoints) using its
configured local site URL, so visitors hitting the tunnel hostname get
mixed-content errors, broken links, and redirect loops back to the
unreachable local URL.

This add-on installs a single must-use plugin, `ddev-share-helper-for-wp.php`,
that rewrites URLs on the fly for tunneled requests only:

- Local requests (`Host` = your `*.ddev.site` URL) are completely unaffected.
- Tunneled requests (`Host` = the tunnel hostname) get every generated URL,
  and the final HTML/JSON output, rewritten to the tunnel host — with no
  changes to the database.

Because it's a must-use plugin, it loads automatically and needs no
activation step.

## Requirements

- A DDEV project of type `wordpress` with a standard layout, i.e. `wp-content/`
  inside the docroot. Composer-based layouts that relocate `wp-content` (such
  as Bedrock's `web/app/`) are not currently supported.
- `ddev share` uses [ngrok](https://ngrok.com) by default, which requires a
  free ngrok account and authtoken — see the
  [DDEV sharing docs](https://docs.ddev.com/en/stable/users/topics/sharing/)
  for setup and for alternatives like cloudflared.

## Installation

```bash
ddev add-on get dustinatx/ddev-share-helper-for-wp
ddev restart
```

Then share your site as usual:

```bash
ddev share
```

## Removal

```bash
ddev add-on remove share-helper-for-wp
```

## Issues

If you run into problems, please [file an issue](https://github.com/dustinatx/ddev-share-helper-for-wp/issues).

## Maintainer

@dustinatx
