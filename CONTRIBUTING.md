# Contributing to WPMark

Thank you for helping. The most useful contributions right now are real-world
reports: what works on your site, what doesn't, and what your marketing team
wishes it could ask.

## Reporting bugs and suggesting ideas

Use the [issue forms](https://github.com/atulsingh-co-in/wpmark/issues/new/choose).
For bugs, include the health report (**WPMark → Health check → Copy health
report**) and the AI app you used.

**Issues are public.** Never include passwords, connection keys, your
visitors' form entries, or screenshots showing customer details.

Security problems: please report privately, as described in [SECURITY.md](SECURITY.md).

## What WPMark will and won't do

Ideas are judged against a few fixed rules:

- WPMark is for **marketing teams**. Features should help understand the
  site, review leads, find content gaps, audit SEO, or plan content.
- WPMark **never publishes, deletes or trashes anything.**
- Every tool has a real WordPress permission check.
- Everything from the site is treated as untrusted data, not instructions.
- Personal data is kept to what a tool needs.
- Screens and messages are written for non-developers.

## Code contributions

Please **open an issue first** to discuss the change. Development happens in
a separate working repository, and this public repository is updated with
each release, so accepted pull requests are applied there and credited in the
changelog, rather than merged directly.

By contributing, you agree your contribution is licensed under GPL v3 or
later, like the rest of WPMark.

### Development setup

- PHP 8.1+, Composer, and MySQL or MariaDB for tests.
- `composer install`
- `composer lint`: WordPress Coding Standards (PHPCS).
- `composer test`: PHPUnit on the WordPress test framework. See
  [tests/README.md](tests/README.md) for setup.
- `bin/build-zip.sh`: builds the installable zip with the bundled MCP Adapter.

Standards: WordPress Coding Standards, everything prefixed (`wpmark_`,
`WPMARK_`, `WPMark\`), all strings translatable with the `wpmark` text
domain, and a test for each tool's happy path, permission denied and invalid input.

New form plugins go behind `WPMark\Forms\Form_Source`, and new SEO plugins
behind `WPMark\Seo\Seo_Source`: one new class each.
