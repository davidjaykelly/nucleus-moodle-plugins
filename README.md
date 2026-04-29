# Nucleus federation plugins for Moodle

Three GPL Moodle plugins that turn a stack of separate Moodle sites into a federation: one **hub** publishes courses, many **spokes** pull and run them at their own branded domain. Same architecture the hosted Nucleus product runs on — released here so you can run the federation yourself.

## What's in this repo

| Plugin | Goes on | What it does |
| --- | --- | --- |
| `local_nucleushub` | Hub Moodle | Publishes course versions, owns the catalogue, exposes a federation API spokes can pull from. |
| `local_nucleusspoke` | Spoke Moodle | Pulls versions from a hub, restores them as instances, surfaces them in the catalogue UI. |
| `local_nucleuscommon` | Both | Shared bits: federation-node identity, common settings, control-plane web service contract. |

Tested against **Moodle 4.5 LTS and 5.1 LTS**. Plugins still mark themselves alpha — they work, but the API isn't frozen yet, so expect minor renames between minor releases.

## What this is — and what it isn't

This is the **community / open-source** path. You get the plugins, the contract between them, and the freedom to wire them into whatever Moodle install you already run.

You **do not** get:

- A control plane (the API + scheduler that the hosted product uses to provision spokes, mint tokens, drive course publish/pull jobs, etc.). The plugins are happy to talk to one — they expose web services for that — but you'd build it yourself, or run the federation purely between Moodle sites without one.
- An admin portal / operator UI. Same idea: the plugins expose REST endpoints, and the hosted product wraps them in a Vue frontend. The community version assumes you're an ops team comfortable working from Moodle's site admin pages and the CLI scripts under each plugin's `cli/` directory.
- Commercial support, SLAs, or guaranteed response times. I'll help where I can — see [Help & community](#help--community) — but if your federation is in production and downtime hurts, the [hosted](https://nucleuslms.io/#pricing) or [self-host](https://nucleuslms.io/#pricing) tiers exist for that.

If those gaps are dealbreakers, that's the point of the paid tiers; they aren't going to be added back here later.

## Install

Drop each plugin into the right place in your Moodle codebase:

```text
moodle/local/nucleushub/      ← contents of plugins/local_nucleushub
moodle/local/nucleusspoke/    ← contents of plugins/local_nucleusspoke
moodle/local/nucleuscommon/   ← contents of plugins/local_nucleuscommon
```

A hub install needs `nucleushub` + `nucleuscommon`. A spoke install needs `nucleusspoke` + `nucleuscommon`. They're separate plugins so you don't ship hub code to a spoke and vice versa.

Then visit Site administration → Notifications (or run `php admin/cli/upgrade.php`). You'll see three new entries appear under Site administration → Plugins → Local plugins:

- **Nucleus federation hub**
- **Nucleus federation spoke**
- **Nucleus federation (common)**

## Configure

Two web services are exposed, one per role:

| Service | Shortname | Where |
| --- | --- | --- |
| Federation node API | `nucleus_federation` | Hub — what spokes call to fetch the catalogue and pull versions |
| Control-plane (hub) | `nucleus_cp_hub` | Hub — what an external control plane calls to drive publish jobs |
| Control-plane (spoke) | `nucleus_cp_spoke` | Spoke — what an external control plane calls to drive pull jobs |

Enable web services site-wide (Site admin → Advanced features), then under Site admin → Plugins → Web services → External services pick the ones you need and either enable for all authenticated users or scope by role.

Each plugin has a settings page under Site admin → Plugins → Local plugins → Nucleus federation… for things like the hub URL on a spoke, or the federation-node identity. The labels are self-explanatory; the inline help strings have the specifics.

For first-time bring-up, the CLI scripts handle the awkward bootstrap moments:

```bash
php local/nucleushub/cli/seed_phase0.php       # mints the hub WS token + prints it
php local/nucleuscommon/cli/setup_cp_token.php # creates the control-plane service user (run on either side)
```

The hub's seed script is idempotent — re-running it just re-prints the token rather than creating a duplicate.

## Building your own control plane

The plugins are designed so you can run the federation without a control plane (spokes pull from the hub on a schedule), but most non-trivial deployments will want one. The contract is small enough to implement in any language:

- **Hub side**: implement `nucleus_cp_hub` as the consumer. The publish flow is roughly *enqueue job → call `local_nucleushub_publish_version` → poll `local_nucleushub_get_publish_status`*. Course-version publish events are fired through Moodle's events system if you'd rather subscribe than poll.
- **Spoke side**: same pattern with `nucleus_cp_spoke`. The pull job restores a version into a category you specify; status is polled.
- **Federation node API**: the hub-to-spoke contract is documented at [docs.nucleuslms.io/concepts/federation](https://docs.nucleuslms.io). Stable enough to pin a client against; if it changes, you'll see a major-version bump on `local_nucleushub`.

There's no opinion on what your control plane should look like. The hosted product is a NestJS API + Vue frontend; you could equally drive it from a cron + a few `curl` commands.

## Help & community

This is a side-of-desk maintained project — the day-job that funds it is the [hosted Nucleus product](https://nucleuslms.io). That said:

- **Issues are welcome.** If something's broken, or the API is doing something the docs don't predict, open a GitHub issue with the Moodle version, plugin version, and the relevant log lines. I read everything that lands in the queue.
- **PRs are welcome too.** Bug fixes get merged quickly; feature PRs land best when there's an issue first to align on the approach.
- **Quick questions**: GitHub Discussions on this repo. Happy to answer. Slower than commercial support — usually a couple of days.
- **If you're running this in production and need a faster lane**, the [Self-Host tier](https://nucleuslms.io/#pricing) is the same plugins plus a private Slack and ops runbooks. No pressure — the free path is real.

I try to keep the tone of this project honest about what it is: useful software released under GPL, maintained part-time, evolving alongside the commercial product. Nothing here is a teaser to push you to upgrade — if these plugins fit your federation, that's a great outcome.

## Compatibility

| | Tested | Notes |
| --- | --- | --- |
| Moodle 4.5 LTS | yes | The lowest currently supported version. Federation API stable. |
| Moodle 5.1 LTS | yes | Recommended for new installs. Some performance niceties land here. |
| Moodle main | best-effort | We track main; expect occasional breakage between Moodle minor releases. |
| PHP 8.2+ | yes | Same floor as Moodle 5.1. |
| Postgres / MariaDB | yes | Both work; Postgres is what the hosted product runs. |

If you hit a Moodle version that isn't on this list, an issue is more useful than a pull request — I'd rather know about the breakage and figure out the right fix than fast-merge a workaround.

## License

GPL v3 or later. Same as Moodle itself. See [LICENSE](LICENSE) for the full text.

You can use, modify, redistribute, and run these plugins in any context — commercial or otherwise — provided you keep the licence intact and publish your modifications under the same terms when you redistribute.

## Author

David Kelly · [contact@davidkel.ly](mailto:contact@davidkel.ly) · [davidkel.ly](https://davidkel.ly)
