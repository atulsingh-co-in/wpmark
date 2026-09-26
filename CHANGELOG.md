# Changelog

All notable changes to WPMark. Versions follow [semantic versioning](https://semver.org):
0.x versions are beta, and any of them may change behaviour.

## 0.3.2 (public beta)

- **Sign-in works on more hosts.** WPMark now also finds the app's sign-in
  pass where LiteSpeed servers (such as Hostinger) put it. Before, an app
  could sign in and then be refused on every request.
- **New health check: "Recent connections from AI apps".** When an app signed
  in but can't connect, it says why and how to fix it: the server removes
  the Authorization header, the sign-in is old or unknown, or the role isn't
  allowed. Only the outcome, time and app name are kept: never tokens.
- **Connected apps:** "Not yet" now links to the health check.
- Each release has one download, `wpmark.zip`.

## 0.3.1 (public beta)

- First public download. The same plugin as 0.3.0, released with the
  installable zip attached. (0.3.0 was published without it.)

## 0.3.0 (public beta)

- **Public beta.** Beta badge, a footer with the setup guide, privacy guide,
  beta terms and "Report a problem" on every WPMark screen.
- **Copy health report** on the Health check tab, for help requests. It has
  versions and check results only: no leads, passwords, keys or site address.
- **Suggested privacy policy text** under Settings → Privacy → Policy Guide,
  explaining that enquiries may be shared with the AI provider your team uses.
- **Gemini** setup steps on the Connect tab (early support).
- WordPress no longer looks for WPMark updates on WordPress.org, so an
  unrelated plugin with the same name there can never replace it. New
  versions come from atulsingh.co.in and GitHub Releases.
- New public documentation: setup guide, what you can ask, troubleshooting,
  privacy and data (GDPR, DPDP, US, UAE and GCC notes), beta terms and privacy
  notice, security policy, support.

## 0.2.0

- **Sign in with WordPress** (OAuth 2.1). Paste one address into Claude or
  ChatGPT, log in and click Allow. No keys or config files.
- **Connected apps** list with one-click disconnect. Administrators see everyone's.
- Health check tests sign-in discovery (`/.well-known/`).
- Works with the older MCP Adapter copies some plugins (such as Elementor) include.

## 0.1.1

- The MCP Adapter is now built in: WPMark installs as a single plugin.
- The installable zip is attached to every GitHub Release.

## 0.1.0

- First version: site overview, search content, read a page, content
  inventory, SEO gaps, list leads and lead summary.
- Elementor Pro forms, WPForms and Contact Form 7 + Flamingo; Rank Math SEO.
- Role-based lead privacy: no leads, no contact details, masked or full.
- Admin screens: connection wizard with connection keys, health check,
  tools on/off, access and privacy.
- Three playbooks: weekly lead review, content plan, SEO health check.
