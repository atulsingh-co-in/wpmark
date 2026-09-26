# Security policy

WPMark gives AI apps read access to a WordPress site, including form entries
that hold personal data. We take security reports seriously and appreciate
your help.

## Reporting a vulnerability

**Please don't open a public issue.** Report privately, either:

- through GitHub: **Security → Report a vulnerability** on this repository, or
- by email to **join@atulsingh.co.in** with "WPMark security" in the subject.

Please include the WPMark version, what you found, how to reproduce it, and
the impact you think it has. If you have a proof of concept, include it.

## What to expect

WPMark is maintained by one person during its beta, so these are
best-effort targets:

- acknowledgement within **5 working days**;
- an assessment and a plan within **14 days**;
- a fix released as soon as practical, prioritised by severity. We'll agree a
  disclosure date with you and credit you in the release notes if you'd like.

## Scope

In scope: the WPMark plugin in this repository. This includes permission
checks, the OAuth sign-in, lead data exposure and masking, and prompt
injection that makes WPMark return data a user shouldn't see.

Out of scope: vulnerabilities in WordPress core, other plugins, the MCP
Adapter (report those to their maintainers), AI apps, and hosting setups.
Social engineering, denial-of-service and findings that need an already
compromised administrator account are also out of scope.

## Please

- Test only on sites you own or have permission to test.
- Don't access, change or keep data that isn't yours.
- Give us reasonable time to fix before disclosing publicly.

We won't pursue legal action for good-faith research that follows this policy.

## Supported versions

Only the latest release gets security fixes during the beta.
