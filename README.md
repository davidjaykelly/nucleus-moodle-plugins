# Nucleus plugins for Moodle

The Moodle side of [Nucleus](https://dklabs.co.uk/nucleus): four GPL plugins that link separate Moodle sites into a federation. One **hub** shares versioned courses; each **spoke** takes a version when it suits; and, optionally, people sign in to every spoke with their hub account.

They're published so you can see exactly what runs on your Moodle, and so a Moodle you already run can join a Nucleus federation as a spoke.

| Plugin | Goes on | What it does |
| --- | --- | --- |
| `local_nucleuscommon` | Hub and spokes | The connection to the Nucleus control plane, shared settings, the Nucleus bar on course pages. |
| `local_nucleushub` | Hub | Publishes course versions, keeps the catalogue, and (when a federation signs in with the hub) is the sign-in provider for its spokes. |
| `local_nucleusspoke` | Spokes | The catalogue, pulling a version as a new course, and new-version notices. |
| `auth_nucleus` | Spokes | Sign in with the hub. Links accounts by the hub's own identifier only, never by email, and never signs in to a site admin or any account with site-level roles. |

## What you need

- **Moodle 5.1** and PHP 8.2 or later.
- **A Nucleus control plane.** Publishing, sharing and sign-in are all driven by it: the plugins on their own don't form a federation. Nucleus is hosted by DK Labs; running the whole thing yourself is by arrangement.

## Joining a hosted federation with your own Moodle

If you've been invited to join a Nucleus federation as a spoke:

1. Copy the plugins into your Moodle:

   ```text
   public/local/nucleuscommon/   <- local_nucleuscommon
   public/local/nucleusspoke/    <- local_nucleusspoke
   public/auth/nucleus/          <- auth_nucleus
   ```

2. Run the upgrade: Site administration → Notifications, or `php admin/cli/upgrade.php`.
3. Run the join command from your invitation email.

Hub sign-in stays off until the federation turns it on. Local accounts, including every site admin, always keep working at `/login/index.php?local=1`.

## Help

Open an issue with your Moodle version, the plugins' versions (Site administration → Plugins → Plugins overview) and any relevant log lines. For anything else, email contact@dklabs.co.uk.

## Licence

GPL v3 or later, the same as Moodle. See [LICENSE](LICENSE).

David Kelly, [DK Labs](https://dklabs.co.uk) · contact@dklabs.co.uk
