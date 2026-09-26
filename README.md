# WPMark

**Ask your AI assistant about your own website.** WPMark connects Claude,
ChatGPT or Gemini to your WordPress site, so your marketing team can ask
about content, SEO and leads in plain language, and get answers from the
real site.

> "What did people ask in our contact forms this month?"
> "Which landing pages brought the most enquiries, and from which campaigns?"
> "Which pages are missing a meta description or haven't been updated in a year?"

WPMark **only reads**. It never publishes, changes or deletes anything on your site.

**Status: public beta.** Free and open source. [Join the beta](https://atulsingh.co.in/wpmark/) ·
[Setup guide](docs/getting-started.md) · [Privacy and your data](docs/privacy-and-data.md) ·
[Beta terms](docs/beta-terms.md)

---

## The problem

Your website is full of marketing answers, but getting them out takes work:

- **Leads sit inside a form plugin.** To see what people are asking, someone
  exports a spreadsheet and reads it row by row.
- **Content audits take days.** Which posts are old, thin, or missing SEO
  basics? Someone clicks through every page.
- **SEO warnings go unread.** The SEO plugin knows what is missing, but only
  shows it one page at a time.
- **Copy-and-paste into ChatGPT.** People paste bits of the site into an AI
  chat, lose context, and sometimes paste customer details they shouldn't.
- **"Can a developer pull a report?"** Simple marketing questions wait on
  technical help.

## The solution

WPMark lets the AI assistant your team already uses read your site directly,
with your WordPress login, and only what your role is allowed to see.

You ask in plain language. The AI checks your pages, posts, SEO settings
and form entries through WPMark, then answers: summaries, lists, content
ideas, fixes to make. No exports, no copy-paste, no developer.

### Where MCP comes in

AI apps connect to other software through **MCP (Model Context
Protocol)**, an open standard now supported by Claude, ChatGPT, Gemini and
many others. Think of it as a universal plug: any AI app that speaks MCP
can plug into any service that offers it.

WPMark gives your WordPress site that plug. It offers the AI a small set of
clearly described, read-only tools, like "list recent leads" or "find SEO
gaps". The AI decides which to use to answer your question, and WPMark
checks your permissions every time.

```
You ──ask──▶ Claude / ChatGPT / Gemini ──MCP──▶ WPMark on your site
                                         ◀─data─ (read-only, as you)
```

Built on the official WordPress Abilities API and the official WordPress
MCP Adapter, so it follows WordPress's own direction for AI.

## What you can ask

| Marketing job | Ask things like |
|---|---|
| Understand the site | "Give me an overview of our site: what we write about, how much content, which forms." |
| Review incoming leads | "Summarise this week's enquiries. Which ones look most urgent?" |
| Find content gaps | "What do customers keep asking that we have no page about?" |
| Audit SEO | "List pages with no meta description or focus keyword, worst first." |
| Measure landing pages | "Which landing pages and UTM campaigns brought the most leads this month?" |
| Refresh old content | "Which posts are over a year old and under 600 words?" |

More in [What you can ask](docs/what-you-can-ask.md).

## What it includes

| Tool | What it answers |
|---|---|
| Site overview | What the site is about, how much content it has, which form and SEO plugins it uses, recent lead counts |
| Search content | Which posts and pages cover a topic |
| Read a page or post | The full text, headings and SEO settings of one page |
| Content inventory | Every post and page with word count, age and SEO basics, oldest first |
| Find SEO gaps | Missing meta descriptions and focus keywords, noindex pages, orphan pages, thin, stale and duplicate-title pages |
| List leads | Form submissions, newest first, with contact details shown, masked or hidden by role |
| Lead summary | Leads by form, landing page, UTM campaign and week |

Plus three ready-made playbooks (weekly lead review, content plan from
customer questions, SEO health check), a health check that finds connection
problems and explains the fix, and an admin screen to switch tools on or off.

**Works with:**
- **AI apps:** Claude (web, desktop, mobile), ChatGPT (developer mode), Gemini (early support), Claude Code, Cursor, VS Code and other MCP apps.
- **Forms:** Elementor Pro forms, WPForms (paid editions), Contact Form 7 with Flamingo.
- **SEO:** Rank Math.

## Why teams can trust it

- **Read-only, always.** WPMark has no tool that publishes, edits, trashes or deletes.
- **You sign in as yourself.** Each person connects with their own
  WordPress login and sees only what their role allows. Disconnect any app in one click.
- **Customer details stay under your control.** For each role, choose: no
  leads, leads without contact details, masked details (`r***@acme.com`), or full details.
- **Nothing is sent to us.** WPMark has no tracking, analytics or "phone home".
  Your data goes only from your site to the AI app your team chooses.
- **Built to resist manipulation.** Text from forms and comments is labelled as
  data, never instructions, so a visitor cannot steer the AI through a form.
- **Open source.** GPL v3. Anyone can read exactly what it does.

## Get started

1. **Download** the plugin zip from [atulsingh.co.in/wpmark](https://atulsingh.co.in/wpmark/)
   or the [Releases page](https://github.com/atulsingh-co-in/wpmark/releases).
   Not GitHub's green "Code → Download ZIP" button: that copy is incomplete.
2. **Upload** it in WordPress under **Plugins → Add New → Upload Plugin**, then activate.
3. **Connect** under **WPMark → Connect**: copy your site's address into
   Claude, ChatGPT or Gemini, log in to WordPress and click **Allow**.

Needs WordPress 6.9+, PHP 8.1+ and HTTPS. The full walk-through, with
where to click in each AI app, is in the
[setup guide](docs/getting-started.md). If something doesn't connect, see
[troubleshooting](docs/troubleshooting.md).

## The beta programme

WPMark is in public beta: it works, it is tested, and it is still evolving
with feedback from marketing teams. Beta members get the setup guide, early
versions and a direct line for feedback; what they ask for shapes what gets
built next.

Join at [atulsingh.co.in/wpmark](https://atulsingh.co.in/wpmark/). Taking
part is voluntary and covered by the [beta terms](docs/beta-terms.md) and the
[beta privacy notice](docs/beta-privacy-notice.md).

## Privacy and compliance

WPMark runs entirely on your own site. When someone asks their AI app a
question, the answer data (possibly including form entries) goes to that AI
provider under your team's account. You decide who can connect and how much
of each lead they can share.

The [privacy and data guide](docs/privacy-and-data.md) explains exactly what
is read, what leaves your site, and notes for GDPR (EU/UK), India's DPDP Act,
US state privacy laws, and the UAE, Saudi Arabia and the wider GCC. WPMark
also adds suggested text to **Settings → Privacy → Policy Guide**.

## Help and custom work

- **Help:** the [setup guide](docs/getting-started.md),
  [troubleshooting](docs/troubleshooting.md), and
  [GitHub issues](https://github.com/atulsingh-co-in/wpmark/issues) for bugs and ideas.
- **Custom work:** another form plugin, your CRM, a tool for your team's
  workflow, or setting it up for you. See [SUPPORT.md](SUPPORT.md).
- **Security issues:** please report privately. See [SECURITY.md](SECURITY.md).

## For developers

- Built on the WordPress Abilities API and the official MCP Adapter, bundled in
  `vendor/` through the Jetpack Autoloader so several plugins can ship it
  without conflicts. WPMark does not implement the MCP protocol itself.
- Every tool is a WordPress ability (`wpmark/<tool>`, shown to AI apps as
  `wpmark-<tool>`) with a real permission check and read-only annotations.
- Sign-in is OAuth 2.1 following the MCP authorization spec: discovery
  (RFC 9728, RFC 8414), dynamic client registration (RFC 7591), PKCE S256,
  rotating refresh tokens and revocation (RFC 7009). WordPress Application
  Passwords work too, for apps without sign-in.
- Form plugins sit behind `WPMark\Forms\Form_Source`; SEO plugins behind
  `WPMark\Seo\Seo_Source`. Adding Gravity Forms or Yoast is one new class,
  registered through the `wpmark_form_sources` or `wpmark_seo_sources` filter.
- `composer lint` runs WordPress Coding Standards; `composer test` runs PHPUnit.
  See [tests/README.md](tests/README.md) and [CONTRIBUTING.md](CONTRIBUTING.md).

## About

WPMark is made by [Atul Singh](https://atulsingh.co.in), who designs,
directs and tests it, with [Claude](https://claude.ai) (Anthropic's AI) as
coding partner. It is part of a build-in-public series on AI for sales and
marketing teams.

Claude, ChatGPT, Gemini and WordPress are trademarks of their owners. WPMark
is an independent project and is not affiliated with or endorsed by
Anthropic, OpenAI, Google or the WordPress Foundation.

## Licence

GPL v3 or later. See [LICENSE](LICENSE). WPMark is provided as is, without
warranty of any kind.
