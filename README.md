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
  └─ modules: [ PresenceRotator, SlashCommands, Onboarding, EventLogger, … ]
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
    ->addModule(new EventLogger());
```

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
guild id. Runtime edits are layered on top from the state store.

## Tests

```bash
vendor/bin/phpunit
```

Pure logic only — no gateway, no network. `phpunit.xml` runs strict
(`beStrictAboutCoversAnnotation`, `failOnRisky`, `failOnWarning`); every test
method carries a `@covers`.

## Ported from the legacy bot — and still to come

Done: rotating presence, per-guild channel/role config, event logging,
`/whois` · `/invite` · `/ping`, native onboarding management.

Planned as further modules (kept out of this first cut deliberately):

- **SS13 integration** — `discord2ckey` verifier link, `/ckey`, the verifier
  HTTP endpoint.
- **WebAPI** — the legacy `webapi.php` surface, as a module owning its own
  `React\Http` server.
- **Twitch relay** — TwitchPHP bridge (was commented out in the legacy bot).
- **Tiered `!s` message commands** + a per-guild config command to replace the
  hand-edited templates.
- **MySQL/PDO layer** — only if a module actually needs relational storage; the
  JSON store covers everything so far.

## License

MIT — see [LICENSE.md](LICENSE.md). Original Tutelar bot by Valithor Obsidion /
[VZGCoders](https://github.com/VZGCoders).
