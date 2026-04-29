# Contributing

Thanks for taking the time. This is a small, side-of-desk project — issues and PRs are very welcome and I read everything that lands.

## Before you open an issue

- **Check it's not already there.** [Existing issues](https://github.com/davidjaykelly/nucleus-moodle-plugins/issues) catch a lot of duplicates. Don't worry too much if you can't tell — better to file a duplicate than skip a real bug.
- **Include the boring stuff.** Moodle version, plugin version (`Site admin → Plugins → Plugin overview`), PHP version, and the relevant log lines. Reproducing a federation bug without that is mostly archaeology.
- **One thing per issue.** If you've found two unrelated bugs, two issues — easier to track resolution.

## Before you open a PR

A short heads-up in an issue first saves wasted work. The plugins are alpha and the public API still moves; what looks like a small change can ripple into the federation contract or the hosted product.

- **Bug fixes** — straight to a PR is fine. Keep it minimal: fix the bug, not the surrounding style.
- **New features** — issue first to align on the approach. Happy to chat through what fits the federation model and what might be a better fit for a separate plugin.
- **Refactors** — also issue first. Even if the change makes the code objectively nicer, if it conflicts with in-flight work in the hosted product I'll have to ask you to rebase.

## Coding standards

The plugins follow Moodle's own conventions:

- **PHP**: 4 spaces (never tabs), PSR-style braces, `else if` (two words) in normal brace syntax. Aim for ≤180 char lines.
- **Boilerplate**: every PHP file starts with the GPL header + `defined('MOODLE_INTERNAL') || die();`.
- **Docblocks**: classes, methods, and member variables get phpdoc. `@param`, `@return`, `@throws` where relevant.
- **Capabilities**: defined in `db/access.php` with sensible archetypes + risk bitmasks.
- **Strings**: all user-facing copy is a language string in `lang/en/<plugin>.php`, sorted alphabetically by key.
- **Database changes**: every `if ($oldversion < N)` block in `db/upgrade.php` ends with `upgrade_plugin_savepoint(true, N, 'local', 'pluginname');` — the upgrade CI checks this.

The repo's `CLAUDE.md` has the full conventions list if you want the long form.

## Running tests locally

Each plugin has PHPUnit tests under `tests/`. The standard Moodle setup applies:

```bash
# In your moodle/ checkout, with the plugin installed:
php admin/tool/phpunit/cli/init.php
vendor/bin/phpunit local/<plugin>
```

Behat coverage is sparse on purpose — federation flows are integration-shaped and the hosted product's CI exercises them end-to-end. If you're touching UI behaviour, a Behat scenario is welcome but not required.

## Commits + PR titles

- One logical change per commit when you can, but don't agonise — squash on merge if the history is messy.
- Imperative subject (`Fix federation token race`, not `Fixed federation token race`).
- Reference an issue if there is one (`Fixes #42`).

PRs are merged with a squash commit using the PR title, so a clean title matters more than a clean commit history on the branch.

## Releases

Plugin versions follow Moodle's date-based scheme (`YYYYMMDDxx`) in `version.php`. The `release` field is human-readable semver-ish (`0.5.0-phase3`). I bump versions when merging to `main`; you don't need to touch them in your PR.

## What's out of scope

A few things that won't land in this repo, no matter how good the PR:

- **Control plane** code or scaffolding. The plugins are designed to work without one; the hosted product's CP is closed-source. If you'd find an open-source CP useful for your own federation, a separate community project would be the right home for it.
- **Admin UI / frontend**. Same reasoning. The plugins expose REST endpoints; the hosted product wraps them in Vue. Anything you'd want to build for the community side is yours to keep — happy to link to it from the README if it's useful.
- **Anti-features** to push people toward the paid tiers. The plugins should work as well as they can on their own — that's the whole point of the GPL release.

## A note on tone

If you've read the README, you've got the gist. Friendly, honest, no formality. Tell me if something's broken and I'll do my best to fix it; tell me if you've fixed something and I'll do my best to merge it. We're all running Moodle, life is short.

— David
