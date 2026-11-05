# Troubleshooting

**Start with WPMark → Health check.** It tests the usual causes and explains
the fix for each one. Most connection problems come from site setup (a
security plugin, a cache or the web server), not from WPMark.

## Installing

| What you see | What to do |
|---|---|
| "This copy of WPMark is incomplete" | It was downloaded with GitHub's green "Download ZIP" button. Delete it and install the zip from [atulsingh.co.in/wpmark](https://atulsingh.co.in/wpmark/) or the [Releases page](https://github.com/atulsingh-co-in/wpmark/releases). |
| "WPMark needs WordPress 6.9 or newer" | Update WordPress under **Dashboard → Updates**. |
| "This plugin requires a newer version of PHP" | WPMark needs PHP 8.2 or newer. Older versions no longer get security fixes. Change the PHP version in your hosting control panel, or ask your host. |
| Health check: **PHP security** is red or yellow | Your PHP version is unsupported or is missing recent security fixes. This affects your whole site, not just WPMark. Ask your host to update PHP; the health check names the version you need. |
| "The uploaded file exceeds the upload_max_filesize" | Your host limits upload size. Ask your host to raise it to 8 MB, or upload the unzipped `wpmark` folder to `wp-content/plugins/` by SFTP. |
| "Destination folder already exists" | An older copy is installed. Choose **Replace current with uploaded**, or delete the old copy first (for example a `wpmark-main` folder). |

## Connecting

| What you see | What to do |
|---|---|
| Claude says "Couldn't register with … sign-in service" | Check the health check's **Sign-in discovery** line. If it is red, your server keeps `/.well-known/` addresses to itself. The health check shows the line to add, or ask your host to pass `/.well-known/oauth-*` requests to WordPress. |
| The sign-in window says the site isn't secure | Sign-in needs HTTPS. Ask your host for a free SSL certificate (most offer Let's Encrypt), then set both addresses under **Settings → General** to `https://`. |
| "Sign-in for AI apps is switched off" | An administrator turned it off under **WPMark → Access & privacy**. |
| "Your role is not allowed to use WPMark" | An administrator can allow your role under **WPMark → Access & privacy**. |
| "Something in front of WordPress blocked the request" | A firewall (Cloudflare, Sucuri, ModSecurity, Wordfence or similar) is blocking `/wp-json/`. Allow `/wp-json/wpmark/` and `/.well-known/`. |
| "A cache answered instead of WordPress" | Exclude `/wp-json/` and `/.well-known/` from your caching plugin, CDN or host cache. |
| You clicked **Allow**, then the app says "returned an error when connecting", "not allowed" or "unauthorized"; **Connected apps** shows "Last used: Not yet" | Open **WPMark → Health check** and look at **Recent connections from AI apps**. It says what happened. Most often the web server, or a CDN or firewall in front of it, removes the `Authorization` header. On Apache or LiteSpeed hosting (including Hostinger), add `SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1` at the top of `.htaccess`. Then disconnect WPMark in your AI app and connect again. |
| "Connection keys are switched off" | A security plugin has disabled Application Passwords. In Wordfence: **Login Security → Settings**, then uncheck "Disable application passwords". Other plugins have a similar setting. Only needed for connection keys, not sign-in. |
| ChatGPT has no "Create" button | Custom connectors need **developer mode** (**Settings → Apps & Connectors → Advanced settings**), available on paid plans on the web. On Business and Enterprise, a workspace admin may need to allow it. |
| Gemini has no "Add a custom app" option | Google is still rolling this out and it depends on the account type. Use Gemini CLI with a connection key instead. |

## Using

| What you see | What to do |
|---|---|
| The lead tools are missing | No supported form plugin is active, or it doesn't save submissions (WPForms Lite, or Contact Form 7 without Flamingo). The health check's **What WPMark can read** line says which. |
| The AI says it can't see contact details | That's your role's setting under **WPMark → Access & privacy**, working as intended. |
| SEO checks mention missing Rank Math | Meta description, focus keyword and noindex checks need Rank Math. Other checks work without it. |
| The AI's answer seems wrong | Ask it which WPMark tool it used and what it saw. Then check the page or lead in WordPress. If WPMark returned wrong data, [report it](https://github.com/atulsingh-co-in/wpmark/issues). |
| A tool you expect isn't offered | An administrator may have switched it off under **WPMark → Tools**. After changing tools, reconnect or start a new chat. |
| The drafting tools are missing | Drafts are off, or your role isn't ticked under **Can create drafts** (**WPMark → Access & privacy**). After changing this, start a new chat. |
| "When you connected this app, you did not allow it to create drafts" | Disconnect WPMark in your AI app, connect again, and tick **Also let it create draft pages and posts** on the WordPress sign-in screen. |
| "Your AI apps have created or changed 30 drafts in the last hour" | A safety limit, so a runaway app can't flood your site. Wait a while, or ask for several changes in one request. |
| "This page is no longer a draft" | The draft was published, scheduled or sent for review, so WPMark won't change it. Ask the AI for a new draft instead. |
| An image is missing from a draft | Drafts only use images already in your media library. Upload the image under **Media**, then ask again. |
| A page built from Elementor widgets or blocks looks plain or unbalanced | Ask the AI to copy one of your best-looking pages or saved templates and change the text. Page design from scratch is experimental in this version. |
| A page made with the experimental custom design looks unstyled or broken | WPMark must be active: it adds the page's styling. If a caching or optimisation plugin combines or delays CSS, clear its cache. If it still looks wrong, keep it as a draft and [tell us](https://github.com/atulsingh-co-in/wpmark/issues) with a screenshot and your theme and page builder. |
| The SEO fix list is missing | It needs Rank Math. |
| An Elementor draft doesn't open, or looks unstyled | Check Elementor is active and up to date, and open the draft from **WPMark → Drafts by AI**, which opens it in Elementor. If it still fails, [report it](https://github.com/atulsingh-co-in/wpmark/issues) with your Elementor version. |
| **Agent ready**: AI assistants are blocked by a firewall | Often Cloudflare's "Block AI bots" or "AI Crawl Control". In Cloudflare, allow the AI search and assistant crawlers you want (for example OAI-SearchBot, ChatGPT-User, Claude-User, PerplexityBot). Other firewalls have similar settings. |
| **Agent ready**: the llms.txt switch is greyed out | A file on your server or your SEO plugin (Rank Math or Yoast) already provides llms.txt. WPMark leaves it alone, so there is only one. |
| Claude Desktop (config file) doesn't show WPMark | Check Node.js is installed (`node -v` in a terminal), that the config file is valid JSON, and quit and reopen Claude Desktop fully. Adding WPMark as a connector under **Settings → Connectors** is simpler. |

## Still stuck?

1. Go to **WPMark → Health check** and click **Copy health report** at the
   bottom. It lists versions and results; no leads, passwords or keys.
2. [Open an issue](https://github.com/atulsingh-co-in/wpmark/issues/new/choose)
   with the report, which AI app you used, and the exact message you saw.
   Beta members can also use the beta channel.

**Never post** passwords, connection keys, lead details or screenshots
showing customer information in an issue: issues are public.
