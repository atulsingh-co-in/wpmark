# Getting started with WPMark

From download to your first answer in about 10 minutes. No coding, no
terminal.

**On this page:** [Before you start](#before-you-start) ·
[1. Download](#1-download) · [2. Install](#2-install) ·
[3. Health check](#3-run-the-health-check) ·
[4. Choose who sees what](#4-choose-who-sees-what) ·
[5. Connect your AI app](#5-connect-your-ai-app) ·
[6. Ask your first questions](#6-ask-your-first-questions) ·
[Updating and removing](#updating-and-removing)

---

## Before you start

You need:

- **WordPress 6.9 or newer** and **PHP 8.1 or newer.** Your host can tell
  you the PHP version, and so can **Tools → Site Health → Info → Server**.
- **HTTPS**, meaning your site address starts with `https://`. AI apps only
  connect to secure sites.
- **An administrator login** to install the plugin. After that, anyone with
  a role you allow can connect.
- **An AI app that can add custom connectors:** Claude, ChatGPT (paid plans,
  with developer mode) or Gemini, where your account offers it. Details in
  step 5.

For leads, you need one of these form plugins, set to save submissions:
Elementor Pro forms, WPForms (paid editions; WPForms Lite does not save
entries), or Contact Form 7 with the free Flamingo plugin. For the SEO
checks, the Rank Math SEO plugin. Without them WPMark still works for
content; the lead and SEO tools simply say what is missing.

> **Beta tip:** try WPMark on a staging or test copy of your site first, and
> make sure you have a recent backup. WPMark only reads, but that is a good
> habit with any new plugin.

## 1. Download

Get the latest `wpmark.zip` from
[atulsingh.co.in/wpmark](https://atulsingh.co.in/wpmark/) or the
[Releases page](https://github.com/atulsingh-co-in/wpmark/releases). Don't
unzip it.

> Don't use GitHub's green **Code → Download ZIP** button. That copy is
> missing the part that lets AI apps connect, and WPMark will tell you so.

## 2. Install

1. In WordPress, go to **Plugins → Add New Plugin → Upload Plugin**.
2. Choose the zip and click **Install Now**, then **Activate**.
3. A green message appears. Click **Open WPMark**, or find **WPMark** in the
   left-hand menu.

Nothing else needs installing. If your site already has the MCP Adapter
plugin, or another plugin that includes it (Elementor does), that's fine:
only one copy loads.

## 3. Run the health check

Go to **WPMark → Health check**. It tests the usual reasons an AI app
can't connect, and explains how to fix each one.

- **Green:** fine.
- **Yellow:** worth a look, but usually still works.
- **Red:** needs fixing before AI apps can connect. The fix is written under
  the line. Most need a setting changed in a security or caching plugin, or
  one message to your host.

Stuck? See [troubleshooting](troubleshooting.md). When asking for help, use
**Copy health report** at the bottom of the page and paste the report in
your message. It contains no leads, passwords or keys.

## 4. Choose who sees what

Go to **WPMark → Access & privacy** (administrators only).

For every role on your site, choose:

1. **Whether the role can use WPMark at all.** Only roles that can write
   posts (Contributor and up) can connect.
2. **How much of each lead it can share with an AI app:**
   - **No access to leads**
   - **Leads without contact details:** what people asked, with no names,
     emails, phones or addresses
   - **Masked contact details,** for example `r***@acme.com`
   - **Full contact details**

The defaults are cautious. Administrators get masked details, editors get
leads without contact details, and other roles get no leads. Only allow full
details if your privacy policy covers sharing them with your AI provider
(see [privacy and your data](privacy-and-data.md)).

Under **WPMark → Tools**, you can switch off any tool you don't want used.

## 5. Connect your AI app

Go to **WPMark → Connect** and click **Copy address**. It looks like:

```
https://your-site.com/wp-json/wpmark/mcp
```

Each person connects their own AI app with their own WordPress login, so the
AI only ever sees what that person may see.

### Claude (web, desktop and mobile apps)

1. In Claude, open **Settings → Connectors** and click **Add custom connector**.
2. Name it `WPMark`, paste the address, and click **Add**.
3. Click **Connect**. A window opens on your site: log in to WordPress if
   asked, check the app name, and click **Allow**.
4. In a new chat, WPMark's tools are available. Ask a question to try it.

On Claude Team and Enterprise plans, an organisation owner adds the
connector first; members then click **Connect**. Once added on the web,
the connector also works in the desktop and mobile apps.

### ChatGPT

Custom connectors need **developer mode**, available on paid ChatGPT plans
on the web.

1. Open **Settings → Apps & Connectors → Advanced settings** and turn on
   **Developer mode**.
2. Back in **Apps & Connectors**, click **Create**.
3. Name it `WPMark`, paste the address, choose **OAuth** for
   authentication, and click **Create**.
4. Log in to WordPress in the window that opens and click **Allow**.
5. In a new chat, switch WPMark on from the **+** (tools) menu, then ask your question.

### Gemini (early support)

Google is rolling out custom connectors in the Gemini app. Where your
account has the option:

1. On [gemini.google.com](https://gemini.google.com), open **Settings →
   Connected apps** and choose **Add a custom app**.
2. Paste the address, then log in to WordPress and click **Allow**.

If your account doesn't show this option yet, use **Gemini CLI** with a
connection key (next section). Gemini support is newer than Claude and
ChatGPT: if something doesn't work, please
[tell us](https://github.com/atulsingh-co-in/wpmark/issues).

### Other apps: Claude Code, Cursor, VS Code, Claude Desktop config, Gemini CLI

- **Apps that support sign-in** (Claude Code, VS Code and others): add the
  address as a remote (HTTP) MCP server. They open the same WordPress sign-in
  page. For example, in Claude Code run
  `claude mcp add --transport http wpmark https://your-site.com/wp-json/wpmark/mcp`,
  then `/mcp` to sign in.
- **Apps that need a key:** on **WPMark → Connect**, open **Other apps: use
  a connection key**, choose your app, and click **Create key**. WPMark shows
  ready-made setup text to copy. The key is shown once; manage or revoke it
  under **Users → Profile → Application Passwords**.

### Managing connections

**WPMark → Connect → Connected apps** lists every app you have connected,
with when it was connected and last used. Click **Disconnect** to sign an app
out at once. Administrators see everyone's connected apps.

## 6. Ask your first questions

Start a new chat and try:

- "Use WPMark to give me an overview of our website."
- "Summarise the leads from the last 7 days. What are people asking for?"
- "Which of our pages have SEO gaps? Start with the most important."
- "Suggest five blog posts that answer questions our customers keep asking."

In Claude, the **+** button also lists WPMark's three ready-made playbooks:
weekly lead review, content plan from customer questions, and SEO health check.

More ideas: [what you can ask](what-you-can-ask.md).

> **Good to know:** the AI can only read. If you ask it to "fix" or "publish"
> something, it will tell you what to change, and you make the change in
> WordPress yourself.

## Updating and removing

**Updating.** New versions are announced to beta members and published on
the [Releases page](https://github.com/atulsingh-co-in/wpmark/releases).
To update, upload the new zip under **Plugins → Add New Plugin → Upload
Plugin** and choose **Replace current with uploaded**. Your settings and
connected apps stay.

**Pausing.** Deactivate WPMark under **Plugins**. AI apps can no longer
connect. Reactivate to carry on where you left off.

**Removing.** Deactivate, then **Delete**. WPMark removes its settings and
all sign-in data (connected apps and their access). Your posts, pages, forms
and leads are never touched. Connection keys stay in WordPress profiles
until revoked under **Users → Profile → Application Passwords**. Also remove
the connector from your AI app.
