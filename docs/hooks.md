# Hooks for add-ons

WPMark Pro, and any other add-on, builds on these WordPress hooks. Add-ons
never change WPMark's files. Everything an add-on adds still goes through
WPMark's own checks: the person's role must be switched on, drafts must be
allowed, and apps that signed in must have the person's consent.

| Hook | Type | What it is for |
| --- | --- | --- |
| `wpmark_loaded` | action | WPMark has started. Hook your add-on here: it does not fire when WPMark could not start, so your add-on never runs alone. |
| `wpmark_abilities` | filter | Add tools, resources or prompts. Each must extend `WPMark\Abilities\Ability`. Names must be new: WPMark's own cannot be replaced. |
| `wpmark_oauth_scopes` | filter | Add a checkbox to the sign-in screen that grants an extra scope. Give a `label` and an `allowed` callback (given the person, whether they may grant it). Scopes start with `wpmark:`. Check a scope in a tool with `WPMark\OAuth\Bearer_Auth::lacks_scope()`. |
| `wpmark_publishing_allowed` | filter | Return true when your add-on lets AI apps publish, so WPMark's own wording (the sign-in screen, the server description, the admin page) stays accurate. |
| `wpmark_admin_tabs` | filter | Add a tab to the WPMark admin page, as slug => label. Only administrators see it. |
| `wpmark_admin_tab_{slug}` | action | Show the content of a tab you added. |
| `wpmark_draft_saved` | action | An AI app created or updated a draft: `( int $id, ?WP_Post $existing, array $input, ?WP_Post $source )`. `$source` is the page a new draft copies whole (`copy_of`), or null. |
| `wpmark_drafts_per_hour` | filter | How many drafts one person's apps may create or change per hour (default 30). |
| `wpmark_builders` | filter | Add a page builder adapter (`WPMark\Builders\Builder`). |

## Example

```php
add_action( 'wpmark_loaded', static function (): void {
	add_filter( 'wpmark_abilities', static function ( array $abilities ): array {
		$abilities[] = new My_Addon\My_Tool(); // extends WPMark\Abilities\Ability
		return $abilities;
	} );
} );
```

The tools list an app sees is fixed when WordPress first starts its REST API
on a request, so add abilities on `wpmark_loaded` (or earlier), not later.
