# Changelog

All notable changes to WPMark. Versions follow [semantic versioning](https://semver.org):
0.x versions are beta, and any of them may change behaviour.

## 0.4.0 (public beta, 5 November 2026)

**Drafts in your site's own style, AI visibility checks, and PHP safety.**
WPMark still only reads unless an administrator switches drafts on.

- **Drafts (off by default).** AI apps can create draft pages and posts
  built with your page builder, colours, fonts, page templates and forms,
  and change the drafts they created. A person always reviews and publishes.
  - Switch on under **WPMark → Access & privacy**, for the roles you choose
    (administrators and editors by default).
  - Each person must also tick **Also let it create draft pages and posts**
    when they connect their app.
  - Never publishes, never changes published pages, never uploads files,
    never deletes anything. Scripts and custom code are removed. Up to 30
    drafts per person per hour.
  - New **Drafts by AI** tab, and a "Created by AI" label in Pages and Posts.
- **Page builders.** The WordPress block editor with any theme (theme.json
  colours, fonts and patterns), and Elementor and Elementor Pro (global
  colours and fonts, saved templates, containers or sections). Drafts follow
  each builder's own format. Forms from Contact Form 7, WPForms and Gravity
  Forms can be embedded.
- **Experimental: custom-designed pages** (off unless switched on under
  Access & privacy). For a polished landing page, the AI can write
  the page's own HTML and CSS. WPMark removes scripts, code, form fields and
  outside images and fonts; limits every CSS rule to the page, so the site's
  header, footer and menus can't change; gives the CSS the site's colours and
  fonts as variables linked to the theme or Elementor kit; and returns design
  notes (missing mobile rules, main heading, image descriptions). Pages can
  keep the site's header and footer at full width, or stand alone on a blank
  template (Elementor Canvas, or WPMark's own for other themes).
- **New tools:** design guide, find templates, page structure, list media,
  create, change and list drafts (a draft can also copy a whole page and
  change some of its text), an AI visibility check, an **SEO fix list**
  (pages whose search title or description needs work, for the AI to write
  better ones) and an **internal link checker** (broken links, links to
  unpublished pages, and pages nothing links to, with pages that could).
- **New playbooks:** Write a blog post, Weekly marketing report and Build a
  landing page.
- **Agent ready tab.** Checks whether AI search and assistants can read your
  site: robots.txt crawler by crawler, firewalls such as Cloudflare,
  structured data, sitemap and llms.txt. Two optional switches, both off by
  default: publish an **llms.txt** summary of your public pages, and describe
  your contact and search forms for AI agents in the browser (**WebMCP**,
  experimental).
- **PHP 8.2 or newer is now required.** Older versions no longer get security
  fixes. A new health check, **PHP security**, says whether your PHP version
  has the latest security fixes.
- **For developers:** hooks for add-ons (`docs/hooks.md`).
- **Beta terms version 1.1:** covers drafts (section 2) and reviewing drafts
  before you publish (section 6).

If you connected an app before 0.4.0 and want it to create drafts: switch
drafts on, then disconnect and reconnect the app and tick the drafts box.

## 0.3.4 (public beta)

- **Health check accuracy.** "Recent connections from AI apps" no longer
  warns about an old sign-in once the app has reconnected successfully (as
  apps do every hour when their sign-in renews). The health check's own test
  request is no longer mistaken for an app's.

## 0.3.3 (public beta)

**Fixes connecting Claude and ChatGPT on some hosting.** On some hosts
(LiteSpeed servers, such as Hostinger), an app could sign in but then show
"Your account was authorized, but WPMark returned an error when connecting",
and Connected apps showed "Last used: Not yet".

- **Sign-in works on more hosts.** WPMark now also finds the app's sign-in
  pass where LiteSpeed servers (such as Hostinger) put it. Before, an app
  could sign in and then be refused on every request.
- **New health check: "Recent connections from AI apps".** When an app signed
  in but can't connect, it says why and how to fix it: the server removes
  the Authorization header, the sign-in is old or unknown, or the role isn't
  allowed. Only the outcome, time and app name are kept: never tokens.
- **Connected apps:** "Not yet" now links to the health check.
- Each release has one download, `wpmark.zip`.

If an app showed the error before: after updating, disconnect it under
WPMark → Connect → Connected apps, remove WPMark in the AI app, and connect
again.

## 0.3.2

- Not available to download: published before its zip was attached. Its
  changes are in 0.3.3.

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
