# DiscordPHP-Tutelar

A clean-room rebuild of the **Tutelar** community-management bot on
[DiscordPHP](https://github.com/discord-php/DiscordPHP) `dev-master`, replacing
the legacy procedural bot (one 600-line class + a bag of closure "variable
functions") with a small, typed, testable core.

The design follows the [`discord-php-extension`](https://github.com/discord-php/DiscordPHP/tree/master/.github/skills)
and `discord-php-bot-security` skills, and borrows patterns from the sibling
projects **DiscordPHP-MTG**, **-NHA** and **-Sabacc**: config-is-data, a JSON
state store with atomic writes, secrets only from the environment, and a
`MessageCommandClient` subclass that boots a list of feature **modules** once the
gateway is ready.

## What changed from the legacy bot

| Legacy | Now |
| --- | --- |
| `token.php` / `secret.php` / inline `$options` array | [`Config`](src/Tutelar/Config.php) from `config.json`, token from `$TOKEN` only |
| `VarSave()` / `VarLoad()` global array | [`Store`](src/Tutelar/Store.php) — JSON, atomic temp-file+rename, stale-`.tmp` sweep |
| `perm_check()` closure | [`Permissions`](src/Tutelar/Support/Permissions.php) — typed, with a pure `anyGranted()` core |
| `status_changer_random` + `status.txt` | [`PresenceRotator`](src/Tutelar/Modules/PresenceRotator.php) module, list in `config.json` |
| eight near-identical `log_functions.php` handlers | one [`EventLogger`](src/Tutelar/Modules/EventLogger.php) module |
| slash `invite` / `whois` closures | [`SlashCommands`](src/Tutelar/Modules/SlashCommands.php) module, user- **and** guild-installable |
| **custom reaction-role engine** | **removed** — see below |

### Reaction roles → Discord's native community features

The legacy bot maintained its own message-reaction → role-grant maps. Discord
now ships this as a first-class feature: **Server Settings → Onboarding** and the
**Channels & Roles** tab give members a supported, accessible role/channel
picker with none of the reaction-listener fragility (lost reactions on restart,
missing `GUILD_MEMBERS`, emoji-key drift, audit-log noise).

Tutelar therefore does **not** reimplement reaction roles. The
[`Onboarding`](src/Tutelar/Modules/Onboarding.php) module is a thin management
surface over the real thing:

- `/onboarding view` — audit the live flow: every prompt, what roles/channels
  each option grants, the auto-opt-in channels, enabled state. Read-only.
- `/onboarding enable` · `/onboarding disable` — flip onboarding on/off.

Both are gated on **Manage Server**; the writes additionally need the bot to
hold **Manage Server + Manage Roles**. Actual prompt/option editing stays in
Discord's own UI, which is the point.

## Module architecture

```
Tutelar (MessageCommandClient)
  ├─ Config   (readonly, from config.json + env)
  ├─ Store    (runtime per-guild overrides + module scratch)
  └─ modules: [ PresenceRotator, SlashCommands, Onboarding, Moderation, EventLogger, … ]
```

A module is anything implementing [`Module`](src/Tutelar/Modules/Module.php)
(`name()` + `boot(Tutelar $bot)`). `Tutelar` boots them in registration order
after **both** `init` and `application-init` have fired, so `$bot->application`
and the guild cache are already populated. A module that throws in `boot()` is
logged and skipped rather than taking the process down.

Add one in `bot.php`:

```php
$bot->addModule(new PresenceRotator())
    ->addModule(new SlashCommands())
    ->addModule(new Onboarding())
    ->addModule(new Moderation(new CaseBook($baseDir . '/var/moderation.json')))
    ->addModule(new EventLogger());
```

### Moderation

Everything a bot-run server needs that Discord's own UI doesn't give a bot —
using only the API. One `/mod` command, guild-only, gated on a moderator
permission (`kick` / `ban` / `timeout` / `manage server`) **and** a role
hierarchy check (you can't action yourself, a bot, the owner, or anyone whose
top role isn't below yours).

| Discord gives you | Tutelar adds |
| --- | --- |
| kick / ban / unban / timeout | …and a numbered **case** for each, posted to `modlog` |
| a 90-day, unqueryable audit log | `/mod case <n>`, `/mod modlogs @user`, `/mod reason <n>` — a durable case book in `var/moderation.json` |
| — (no warning concept) | `/mod warn`, `/mod warnings`, `/mod delwarn`, with **escalation**: 3 → 1h timeout, 5 → 1d, 7 → kick, 10 → ban |
| — (no scheduled unban) | `/mod ban … duration:7d` and a 30-second sweep that lifts it and closes the case |
| — (no private notes) | `/mod note @user text` |
| bulk delete (all-or-nothing, ≤100, <14 days) | `/mod purge count [user] [contains] [bots]` — filtered client-side, old messages skipped |
| manual permission-overwrite editing | `/mod lock` / `/mod unlock` — records and restores the prior `send_messages` state |
| slowmode in channel settings | `/mod slowmode <seconds>` |
| a "report" that goes to Discord Trust & Safety | a **Report to mods** message command that posts to *this server's* `modlog` |

Set `guilds.<id>.channels.modlog` in `config.json` (it falls back to `log`).

## Setup

Requires PHP 8.3+ and the sibling `../DiscordPHP` and `../DiscordPHP-Http`
checkouts (wired as Composer path repos).

```bash
cp env.example .env          # then edit
COMPOSER_ROOT_VERSION=dev-main composer install
php bot.php
```

`.env` / environment:

| var | meaning |
| --- | --- |
| `TOKEN` | **required** — bot token |
| `OWNER_ID` | owner Discord id (owner-only commands) |
| `TUTELAR_CONFIG` | config file path (default `config.json`) |
| `TUTELAR_STATE_PATH` | state file path (default `var/state.json`) |

`config.json` (committable — no secrets) holds `github`, the `presence` rotation
list + `presence_interval`, and per-guild `channels` / `roles` defaults keyed by
guild id:

```jsonc
{
  "github": "https://github.com/…",
  "presence_interval": 120,
  "presence": [{ "name": "over the server", "type": 3, "state": "idle" }],
  "guilds": {
    "1234567890": { "channels": { "log": "1112223334445556667" } }
  }
}
```

`EventLogger` stays inert for a guild until `channels.log` is set here. The
`Store` (`var/state.json`) can layer runtime overrides on top of these defaults;
the command that would edit them at runtime is still on the to-do list, so for
now edit `config.json` and restart.

**Privileged intents:** `bot.php` requests `GUILD_MEMBERS` and `MESSAGE_CONTENT`
— enable both for the application in the Discord Developer Portal, or the gateway
will refuse the connection.

## Tests

```bash
vendor/bin/phpunit
```

Pure logic only — no gateway, no network. `phpunit.xml` runs strict
(`beStrictAboutCoversAnnotation`, `failOnRisky`, `failOnWarning`); every test
method carries a `@covers`.

## Ported from the legacy bot — and still to come

Done: rotating presence, per-guild channel/role config, event logging,
`/whois` · `/invite` · `/ping`, native onboarding management, and the full
`Moderation` module (see above).

Planned as further modules (kept out of this cut deliberately):

- **Auto-moderation** — a layer over Discord AutoMod: invite-link filter, join-rate
  raid gate, new-account gate. (The legacy bot's word-list ban system lives here.)
- **SS13 integration** — `discord2ckey` verifier link, `/ckey`, the verifier
  HTTP endpoint.
- **WebAPI** — the legacy `webapi.php` surface, as a module owning its own
  `React\Http` server.
- **Twitch relay** — TwitchPHP bridge (was commented out in the legacy bot).
- **Tiered `!s` message commands** + a per-guild config command to replace the
  hand-edited templates and reach the `Store` setters at runtime.
- **MySQL/PDO layer** — only if a module actually needs relational storage; the
  JSON store covers everything so far.

## License

MIT — see [LICENSE.md](LICENSE.md). Original Tutelar bot by Valithor Obsidion /
[VZGCoders](https://github.com/VZGCoders).
