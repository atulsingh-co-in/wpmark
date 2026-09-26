# Running WPMark's tests

The tests start a real WordPress with the MCP Adapter and WPMark active,
then check what WPMark does. They need three things: PHP 8.1+, Composer, and
an **empty MySQL database that exists only for tests**. The suite wipes that
database on every run, so never point it at a real site's database.

## One-time setup

```bash
composer install          # development tools, including the MCP Adapter
bin/install-wp.sh 6.9     # WordPress core for the tests, into .wp/wordpress
```

`bin/install-wp.sh` also takes `latest` or an exact version such as `6.9.1`.

### A test database in LocalWP

1. In LocalWP, open any site and go to **Database → Open Adminer**.
2. Create a new, empty database called `wpmark_tests`.
3. On the site's **Database** tab, note the socket path (Mac and Linux) or the
   port (Windows).
4. Create `tests/wp-tests-config.local.php`. Git ignores this file, so your
   settings stay on your machine:

```php
<?php
// Mac and Linux: use the socket path from LocalWP's Database tab.
define( 'WPMARK_TEST_DB_HOST', 'localhost:/path/from/local/mysqld.sock' );
// Windows: use the port instead, e.g. '127.0.0.1:10005'.

define( 'WPMARK_TEST_DB_NAME', 'wpmark_tests' );
define( 'WPMARK_TEST_DB_USER', 'root' );
define( 'WPMARK_TEST_DB_PASSWORD', 'root' );
```

The same settings can come from environment variables with the same names
(`WPMARK_TEST_DB_HOST` and so on). CI uses those. `WPMARK_TEST_WP_DIR` points
at a different WordPress copy if you need one.

## Running

```bash
composer test    # the PHPUnit suite
composer lint    # WordPress Coding Standards
```

## Testing against another WordPress version

Match the test framework to WordPress core, then run as usual:

```bash
bin/install-wp.sh 7.1
composer update wp-phpunit/wp-phpunit --with "wp-phpunit/wp-phpunit:7.1.*"
composer test
```

Run `git checkout composer.lock && composer install` afterwards to go back.

## Checking the MCP surface with MCP Inspector

Unit tests prove each part works; [MCP Inspector](https://github.com/modelcontextprotocol/inspector)
proves an AI app sees the right thing. Run it against a LocalWP site before
trying a real AI app:

1. On the site, go to WPMark → Connect, choose "MCP Inspector (testing)" and
   create a key. Copy the address and the `Authorization` value.
2. Run `npx @modelcontextprotocol/inspector`, choose **Streamable HTTP**, paste
   the address, add the `Authorization` header, and connect.
3. Check:
   - **Tools**: seven `wpmark-…` tools when a form plugin is active (five
     without), each marked read-only, each with a full description.
   - Call `wpmark-site-overview` with no arguments: it returns the site's name,
     content counts and the list of available tools.
   - Call `wpmark-list-leads` as an administrator, editor and author: contact
     details are masked, left out, and refused respectively (the defaults).
   - **Prompts**: `wpmark-seo-check`, `wpmark-content-plan`, and
     `wpmark-lead-review` when a form plugin is active.
   - **Resources**: `wpmark://site/profile` and `wpmark://forms`.

The same checks from the command line, useful after a change:

```bash
npx @modelcontextprotocol/inspector --cli https://your-site/wp-json/wpmark/mcp \
  --transport http --header "Authorization: Basic …" --method tools/list
```

To set WPMark up on a real site, see [docs/getting-started.md](../docs/getting-started.md).

## What is where

| Path | What it holds |
|---|---|
| `bootstrap.php` | Starts WordPress and loads the MCP Adapter and WPMark |
| `wp-tests-config.php` | Test database and WordPress settings |
| `includes/class-fake-form-source.php` | A form plugin that exists only in memory |
| `includes/class-form-source-contract-test-case.php` | Checks every form adapter must pass. A new adapter's test extends it |
| `test-*.php` | The tests |
