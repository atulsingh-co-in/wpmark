# Privacy and your data

This page is for site owners, marketing leads and whoever looks after
privacy in your company. It covers what WPMark reads, what leaves your site,
and what to check under the main privacy laws.

> **Not legal advice.** This is a practical guide to how WPMark handles
> data, to help you and your adviser. Privacy laws differ by country and
> change often. If you process personal data from visitors in several
> regions, check your setup with a qualified adviser.

Last updated: 25 September 2026.

---

## The short version

- WPMark runs **entirely on your WordPress site.** There is no WPMark cloud
  service and no middleman server.
- It **reads** your content, SEO settings and form entries, and **never
  changes** anything.
- Data leaves your site **only** when a signed-in person on your team asks
  their AI app a question. It then goes **to that AI provider** (Anthropic,
  OpenAI, Google or another), under **your team's account** with them.
- **Nothing is sent to the WPMark developer.** No analytics, tracking,
  usage statistics or "phone home".
- **You control** who can connect, which tools exist, and whether contact
  details are shared in full, masked, left out, or not at all.

## What WPMark reads

| Data | Read by | Contains personal data? |
|---|---|---|
| Published posts and pages | Content and SEO tools | Rarely. Author display names only. |
| Drafts, pending and scheduled posts | Content tools, only for people who can edit them in WordPress | Rarely |
| SEO settings (Rank Math) | SEO tools | No |
| Form entries (Elementor Pro, WPForms, Contact Form 7 + Flamingo) | Lead tools, according to each role's setting | **Yes:** names, emails, phone numbers, addresses, and whatever visitors typed in messages |
| Form entry page address and campaign tags (`utm_…`) | Lead tools | Usually not. Other query-string parts are removed. |

WPMark does **not** read user accounts, passwords, comments, orders,
payment data, visitor IP addresses or analytics.

## What leaves your site, and when

Nothing leaves your site on its own. The flow is:

1. A person on your team, signed in to WordPress, connects their AI app.
2. They ask a question. The AI app asks WPMark for data, for example "list the
   last 20 leads".
3. WPMark checks that person's permissions and returns only what their role
   allows.
4. The AI provider receives that answer and uses it to reply in the chat.

So whatever WPMark returns is **shared with the AI provider your team
member uses.** How long the provider keeps it, and whether it may be used to
train models, depends on **your agreement with that provider** and your
account settings, not on WPMark. Business and enterprise plans from the major
providers generally offer a data processing agreement (DPA) and exclude your
data from training by default. Consumer plans may not. Check before
enabling lead tools.

## What WPMark stores on your site

| What | Why | How long |
|---|---|---|
| Settings (tools on/off, role access levels) | To apply your choices | Until you uninstall |
| Connected apps: app name, WordPress user, when connected and last used | So people can see and disconnect their apps | Until disconnected, or 30 days after last use |
| Sign-in tokens | So connected apps stay signed in | Stored only as one-way hashes (SHA-256). Access tokens last 1 hour, refresh tokens 30 days. Expired tokens are cleaned up daily. |

Uninstalling WPMark (Deactivate → Delete) removes all of the above.
Connection keys (WordPress Application Passwords) belong to WordPress and
stay in user profiles until revoked.

## Roles in data protection terms

- **You (the site owner)** decide why and how visitor data is used. You are
  the **controller** (GDPR, and most GCC laws) or **data fiduciary** (India's
  DPDP Act) for your form entries.
- **Your AI provider** processes data on your team's instructions. Under a
  business agreement it is normally your **processor** (or "service provider"
  in US state laws). You need a contract with it.
- **The WPMark developer** receives no data from your site and has no access
  to it, so is **not a processor** of your site's data. If you ever give
  temporary access for support, that is a separate arrangement you control.

## Your controls in WPMark

- **Who can connect:** by role, under **WPMark → Access & privacy**.
- **How much of a lead each role can share:**
  - no leads
  - leads without contact details
  - masked details (`r***@acme.com`, `*********210`, `R*** S***`)
  - full details

  Emails and phone numbers typed *inside* messages are masked or removed to match.
- **Which tools exist:** switch any tool off under **WPMark → Tools**.
- **Who is connected:** **WPMark → Connect → Connected apps**, with one-click
  disconnect. Administrators see everyone's.
- **Sign-in on or off:** under **WPMark → Access & privacy**. Switching it off
  signs every app out at once.

## Before you switch on lead tools: a checklist

1. **Use a business plan with your AI provider**, with a data processing
   agreement and training switched off.
2. **Update your privacy policy.** Say that enquiries may be processed with an
   AI provider. WPMark adds suggested wording under **Settings → Privacy →
   Policy Guide**.
3. **Give the least access that works.** "Leads without contact details" is
   enough for most analysis: themes, volumes, campaigns.
4. **Limit who can connect.** Only roles that need it.
5. **Keep a record.** Note which AI provider you use and where it processes data.
6. **Review now and then.** Check **Connected apps** and disconnect anything
   no longer used.

## Notes by region

These notes highlight what usually matters when form entries are shared
with an AI provider. They are not complete statements of the law.

### European Union and United Kingdom: GDPR and UK GDPR

- **Lawful basis:** analysing enquiries to respond and improve your service
  is usually *legitimate interests*. Record your assessment. Don't
  use special-category data (health, religion and similar) this way without
  a proper basis.
- **Transparency:** your privacy notice should mention AI-assisted
  processing and name the provider or category of provider.
- **Processor contract (Art. 28):** sign your AI provider's DPA.
- **International transfers:** most AI providers process data in the US. Rely
  on the EU–US Data Privacy Framework (where the provider is certified), the
  UK Extension, or Standard Contractual Clauses.
- **Data minimisation:** role-based masking and "leads without contact
  details" help you share only what's needed.
- **Rights requests:** visitors' access and erasure requests apply to your form
  entries in WordPress; delete them there. Ask your AI provider how chat
  history is handled.

### India: Digital Personal Data Protection Act, 2023 and DPDP Rules, 2025

- **Notice and consent:** the notice shown when visitors submit your forms
  should cover the purposes you use enquiries for, including AI-assisted analysis.
- **Data processor contract:** the Act requires a valid contract with any
  processor, including your AI provider.
- **Cross-border transfer:** allowed, except to countries the government
  restricts by notification. Check the current list.
- **Security and breach notice:** take reasonable safeguards. Report personal
  data breaches to the Data Protection Board and affected people.
- **Retention and erasure:** delete entries once their purpose is served, and
  respond to erasure and grievance requests. Publish a contact for grievances.

### United States: state privacy laws

- California (CCPA as amended by CPRA) and a growing number of states
  (Virginia, Colorado, Connecticut, Texas, Oregon and others) have
  comprehensive privacy laws. Thresholds apply, so smaller businesses may be
  out of scope.
- **Service provider contract:** make sure your AI provider's terms qualify it
  as a *service provider* or *processor*, so the sharing is not a "sale" or
  "sharing" of personal information.
- **Privacy policy:** disclose the categories of data collected and the
  categories of recipients, such as AI and cloud service providers.
- **Sector rules:** don't send health (HIPAA), financial (GLBA) or children's
  (COPPA) data through general AI tools without the right agreements.

### United Arab Emirates

- **Federal level:** Federal Decree-Law No. 45 of 2021 on the Protection of
  Personal Data (PDPL). It asks for a clear purpose, a lawful basis (often
  consent), security measures and conditions for transfers outside the UAE.
  Check the current status of its implementing regulations.
- **Free zones:** businesses in the DIFC (Data Protection Law 2020) or ADGM
  (Data Protection Regulations 2021) follow those regimes instead. Both are
  close to GDPR and require processor contracts and transfer safeguards.

### Saudi Arabia

- **Personal Data Protection Law (PDPL)**, enforced by SDAIA since September
  2024. It calls for a legal basis, a privacy notice, and processor contracts. It
  also has specific **transfer rules** for sending data outside the Kingdom:
  review the Transfer Regulations before using a foreign AI provider for form
  entries. Registration duties may apply to some controllers.

### Other GCC countries

- **Qatar:** Law No. 13 of 2016 on Personal Data Privacy Protection.
- **Bahrain:** Personal Data Protection Law, Law No. 30 of 2018.
- **Oman:** Personal Data Protection Law, Royal Decree 6/2022.
- **Kuwait:** CITRA Data Privacy Protection Regulation.

These share common themes: tell people how their data is used, have a lawful
basis, protect it, contract with processors, and take care with transfers
abroad. Local rules on consent and cross-border transfer differ, so check
the specifics for your country.

## Security measures in WPMark

- Every tool checks the person's WordPress permissions on every request.
- WPMark has no tools that write, publish or delete.
- Sign-in follows OAuth 2.1 with PKCE. Tokens are stored hashed and rotated,
  and a reused token signs the whole connection out.
- Sign-in only works over HTTPS (except local development sites).
- Text from forms and posts is cleaned of hidden characters and labelled as
  untrusted data, so instructions typed into a form are not treated as
  commands by the AI.
- The code is open source for anyone to review. Report security problems
  privately: see [SECURITY.md](../SECURITY.md).

## Questions

Privacy questions about WPMark itself: join@atulsingh.co.in.

For the data collected when you join the beta programme, see the
[beta privacy notice](beta-privacy-notice.md).
