=== Promises ===
Contributors: thebleedingdeacons
Tags: mcp, model context protocol, unity, intergroup, rota
Requires at least: 6.1
Tested up to: 6.9
Stable tag: 1.1.7
Build date: 2026/09/05 16:43:01
Requires PHP: 8.1
License: MIT (Modified)

A Model Context Protocol server for Unity, exposing members, groups, meetings, positions and the Trusted responder rota as MCP tools.

== Description ==

**A Model Context Protocol server for Unity, served from WordPress.**

Promises exposes the intergroup's records — members, groups, meetings and
service positions — and, when Trusted is active, the telephone-responder rota,
as MCP tools on an authenticated REST endpoint. An MCP client such as Claude
can then answer questions about the intergroup, and if you allow it, fill gaps
in the rota, without anyone exporting a spreadsheet first.

MCP revision 2026-07-28 made the protocol stateless, which is what allows a
complete implementation to be a single WordPress REST route: there is no
session to hold open between requests and no handshake to remember.

**Features:**

* Twelve MCP tools across Unity's members, groups, meetings and service positions
* Rota tools for Trusted: read a week or a day, and optionally assign or unassign responders
* Every tool registered only when the data behind it is actually available, so a
  missing plugin or unbound repository loses tools rather than causing errors
* Personal email addresses and mobile numbers masked by default
* One API key, Argon2id-hashed, generated and revoked from the settings screen
* Down-level clients speaking older MCP revisions are served unchanged

== Installation ==

1. Install and activate Unity, and make sure its repositories are bound
   (tsml-for-unity does this in the Bleeding Deacons suite).
2. Install and activate Promises.
3. Go to Promises → Settings and generate an API key. Copy it — it is shown once.
4. Point your MCP client at the endpoint shown on that screen and send the key
   as a bearer token.

Optionally activate Trusted to gain the rota tools. The two rota *write* tools
additionally need "Allow rota changes" ticked; they are off by default.

== Frequently Asked Questions ==

= Does this expose members' contact details? =

Not by default. Personal email addresses and mobile numbers are masked, and
each record states that it was masked so a model cannot mistake a masked value
for a real one. The rota's member summaries carry no contact details at all.
Masking can be switched off deliberately in settings.

= What happens if Unity is not active? =

The REST endpoint is never registered, and the settings screen says so. Unity
is declared as a required plugin, so WordPress will also block activation.

= Can a client change the rota? =

Only if you tick "Allow rota changes". Even then a client can only assign
members already flagged as telephone responders in Unity, and cannot assign
anyone to a shift that is already covered.

= Is anything logged? =

Rejected requests, assignment changes, and unexpected failures go to the shared
logger when Sentinel is present, and are silently skipped when it is not.

== Changelog ==

= 1.0.0 =
* Initial release: MCP 2026-07-28 server over a WordPress REST route.
* Unity tools for members, groups, meetings and service positions.
* Trusted rota tools, with opt-in assignment writes.
* Argon2id API key, PII masking, and a settings screen.
