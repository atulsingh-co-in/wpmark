# Troubleshooting

**Start with WPMark → Health check.** It tests the usual causes and explains
the fix for each one. Most connection problems come from site setup (a
security plugin, a cache or the web server), not from WPMark.

## Installing

| What you see | What to do |
|---|---|
| "This copy of WPMark is incomplete" | It was downloaded with GitHub's green "Download ZIP" button. Delete it and install the zip from [atulsingh.co.in/wpmark](https://atulsingh.co.in/wpmark/) or the [Releases page](https://github.com/atulsingh-co-in/wpmark/releases). |
| "WPMark needs WordPress 6.9 or newer" | Update WordPress under **Dashboard → Updates**. |
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
| The app connects, then says "not allowed" or "unauthorized" | Your web server drops the `Authorization` header. The health check tests this and shows the line to add to `.htaccess` on Apache. Otherwise, ask your host to pass the Authorization header to PHP. |
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
| Claude Desktop (config file) doesn't show WPMark | Check Node.js is installed (`node -v` in a terminal), that the config file is valid JSON, and quit and reopen Claude Desktop fully. Adding WPMark as a connector under **Settings → Connectors** is simpler. |

## Still stuck?

1. Go to **WPMark → Health check** and click **Copy health report** at the
   bottom. It lists versions and results; no leads, passwords or keys.
2. [Open an issue](https://github.com/atulsingh-co-in/wpmark/issues/new/choose)
   with the report, which AI app you used, and the exact message you saw.
   Beta members can also use the beta channel.

**Never post** passwords, connection keys, lead details or screenshots
showing customer information in an issue: issues are public.
