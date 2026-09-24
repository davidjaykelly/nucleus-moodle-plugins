# Contributing

Issues and pull requests are welcome. It's just me, so I read everything that lands.

## Issues

- Check the [existing issues](https://github.com/davidjaykelly/nucleus-moodle-plugins/issues) first, but a duplicate is better than a missed bug.
- Include your Moodle version, the plugins' versions (Site administration → Plugins → Plugins overview), your PHP version and the relevant log lines.
- One problem per issue.

## Pull requests

Open an issue first for anything bigger than a bug fix. The plugins are still moving, and a small-looking change can ripple into the federation or the hosted product.

- **Bug fixes:** straight to a pull request is fine. Fix the bug, not the surrounding style.
- **Features and refactors:** issue first, so we agree the approach before you spend the time.

## Code

The plugins follow Moodle's conventions:

- PHP: 4 spaces, Moodle's brace style, `else if` as two words, lines up to 180 characters.
- Every PHP file starts with the GPL header and `defined('MOODLE_INTERNAL') || die();` where Moodle expects it.
- Classes, methods and properties have phpdoc.
- All user-facing text is a language string in `lang/en/`, sorted by key. Strings are in British English and describe Nucleus in the third person.
- Every `db/upgrade.php` block ends with `upgrade_plugin_savepoint(...)`.

## Tests

Each plugin has PHPUnit tests under `tests/`:

```bash
# In your Moodle checkout, with the plugins installed:
php admin/tool/phpunit/cli/init.php
vendor/bin/phpunit --testsuite local_nucleushub_testsuite
```

## Versions

`version.php` uses Moodle's date-based versions (`YYYYMMDDxx`). I bump them when merging, so you don't need to.

## Out of scope

- **The control plane and the portal.** They're DK Labs' hosted service and aren't open source. The plugins expose web services for them.
- **Anything that makes the plugins worse on their own** to push people to a paid option.

David
