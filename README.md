# Promises

[![CI](https://github.com/bleedingdeacons/promises/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/bleedingdeacons/promises/actions/workflows/ci.yml)
[![Coverage Status](https://coveralls.io/repos/github/bleedingdeacons/promises/badge.svg?branch=main)](https://coveralls.io/github/bleedingdeacons/promises?branch=main)
![PHPStan](https://img.shields.io/badge/dynamic/yaml?url=https%3A%2F%2Fraw.githubusercontent.com%2Fbleedingdeacons%2Fpromises%2Fmain%2Fphpstan.neon.dist&query=%24.parameters.level&label=PHPStan&prefix=level%20&color=brightgreen)
![PHPCS](https://img.shields.io/badge/dynamic/xml?url=https%3A%2F%2Fraw.githubusercontent.com%2Fbleedingdeacons%2Fpromises%2Fmain%2F.phpcs.xml.dist&query=%2Fruleset%2Frule%5B1%5D%2F%40ref&label=PHPCS&color=brightgreen)
![Version](https://img.shields.io/badge/version-1.0.1-blue)
![PHP](https://img.shields.io/badge/php-8.1%2B-777bb4)
![Licence](https://img.shields.io/badge/licence-MIT%20(Modified)-green)

**A Model Context Protocol server for Unity, served from WordPress.**

Promises exposes the intergroup's records — members, groups, meetings and
service positions — and, when Trusted is active, the telephone-responder rota,
as MCP tools on an authenticated REST endpoint. An MCP client such as Claude
can then answer questions about the intergroup and, if you allow it, fill gaps
in the rota, without anyone exporting a spreadsheet.

**Dependencies:** Unity (required), Trusted (optional, for the rota)
**License:** MIT (Modified — see [License](#license))
**Author:** [The Bleeding Deacons](mailto:thebleedingdeacons@gmail.com)

---

## Table of Contents

- [Why this exists](#why-this-exists)
- [Requirements](#requirements)
- [Installation](#installation)
- [Connecting a client](#connecting-a-client)
- [Tools](#tools)
- [Privacy](#privacy)
- [Architecture](#architecture)
- [Protocol notes](#protocol-notes)
- [Development](#development)
- [License](#license)

---

## Why this exists

Integrity already publishes Unity's data over REST, and the C# and PHP clients
consume it happily. What neither gives you is a way to *ask a question* —
"who's on the helpline on Saturday and is anyone missing", "which meetings run
on Tuesday evenings in the north of the city" — without someone writing the
query first.

MCP is the protocol that closes that gap, and revision `2026-07-28` is what
makes it practical inside WordPress: the protocol is now stateless, so there
is no session to hold open between requests and no handshake to remember.
A plain REST route is a complete implementation.

## Requirements

- WordPress 6.1+
- PHP 8.1+
- **Unity**, active and configured — Promises reads everything through Unity's
  container and registers no route without it
- **Trusted**, optionally, for the rota tools

Unity ships headless: its repositories are bound by a companion plugin
(tsml-for-unity in this suite). Promises feature-detects each one, so a site
missing a binding loses the corresponding tools rather than erroring.

## Installation

Install and activate as usual, then go to **Settings → Promises** and generate
an API key. Until you do, the endpoint rejects every request.

## Connecting a client

The endpoint is:

```
https://your-site.example/wp-json/promises/v1/mcp
```

Authenticate with the generated key, as a bearer token:

```
Authorization: Bearer prm_…
```

`X-API-Key: prm_…` works too, for hosts that strip `Authorization` before PHP
sees it.

A quick check that the key and the tool set are what you expect:

```bash
curl -s https://your-site.example/wp-json/promises/v1/health \
  -H "Authorization: Bearer prm_your_key"
```

## Tools

Every tool is registered only when the data behind it is actually available.

**Unity** — always, when the matching repository is bound:

| Tool | What it does |
|---|---|
| `unity_list_members` | Search members; filter by area, 12th-steppers, telephone responders |
| `unity_get_member` | One member by id or exact email |
| `unity_list_groups` | Groups, optionally filtered by name |
| `unity_get_group` | One group, with its meeting ids |
| `unity_list_meetings` | Meetings by day, online/in-person, group or keyword |
| `unity_get_meeting` | One meeting, with its location |
| `unity_list_positions` | Intergroup service positions |
| `unity_get_position` | One service position |

**Trusted** — only when Trusted is active:

| Tool | What it does |
|---|---|
| `trusted_get_week` | A rota week (snaps any date back to that week's Monday) |
| `trusted_get_day` | One rota day |
| `trusted_assign_member` | Put a responder on an open shift — **opt-in** |
| `trusted_unassign` | Take a responder off a shift — **opt-in** |

The two write tools appear only when **Allow rota changes** is ticked in
settings. Off by default: reading the rota wrong is recoverable, changing who
answers the helpline is not.

## Privacy

Members are real people, and tool output is read by a language model that may
repeat it into a transcript the client stores.

- Personal email addresses and mobile numbers are **masked by default**
  (`j___@e______.com`, `(***) ***-5309`), matching Integrity's conventions.
- Every record says whether it was masked, so a model cannot mistake a masked
  address for a real one.
- The rota's per-slot member summaries carry **no contact details at all**,
  masked or otherwise — listing a week cannot become a way to sweep the
  membership's phone numbers a slot at a time.
- Masking can be turned off in settings, deliberately, for a client that
  genuinely needs to make contact.

Note that Trusted's own rota objects serialise members' email and telephone in
plain text. Promises never hands those objects to the encoder; it builds its
output field by field so masking cannot be bypassed by accident.

## Architecture

```
promises.php                  bootstrap, kill switch, autoloader
└── src/
    ├── Plugin.php            boots on unity/loaded; registers the route
    ├── Core/                 service provider (feature detection lives here)
    ├── Http/McpController    the REST transport + API-key auth
    ├── Mcp/                  JSON-RPC envelope, dispatch, tool registry
    ├── Tools/                one class per tool
    ├── Support/              domain objects → tool output (masking)
    ├── Auth/                 Argon2id key issue and verify
    ├── Settings/             the single option row
    └── Admin/                Settings → Promises
```

Promises has no container of its own — it registers into Unity's on
`unity/register_services` and resolves everything lazily, so plugin load order
never matters.

The tool registry **is** the permission model: a tool that is not registered
is invisible to `tools/list` and unreachable through `tools/call`. There is no
second check downstream.

## Protocol notes

Implements MCP **2026-07-28**. Down-level clients (`2025-11-25` and earlier)
are served: they open with `initialize`, get their own protocol version echoed
back, and their `notifications/initialized` is accepted silently.

Implemented: `initialize`, `ping`, `tools/list`, `tools/call`.

Not implemented, because Promises has nothing to put behind them: resources,
prompts, sampling, completions, roots, and the Tasks and Apps extensions. The
capabilities object advertises tools only, so clients need not probe.

`GET` on the endpoint returns 405. Under Streamable HTTP that opens the
server-to-client notification stream, and this server never initiates
anything.

JSON-RPC batching is not supported — MCP removed it in 2025-06-18 and has not
restored it. Batched requests get an explicit error rather than a guess.

## Development

```bash
composer install
composer test
composer phpcs
composer phpstan
composer build:production
```

PHPStan runs at level 8 with no baseline. It scans `../unity/src` and
`../trusted/src` for cross-plugin symbols, so check those out alongside — CI
does the same.

Releases are cut by CI on merge to `main`; nothing is bumped or built by hand.

## License

MIT (Modified). See [LICENSE](LICENSE).
