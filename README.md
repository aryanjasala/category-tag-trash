# Term Trash

[![PHPCS](https://github.com/aryanjasala/term-trash/actions/workflows/phpcs.yml/badge.svg)](https://github.com/aryanjasala/term-trash/actions/workflows/phpcs.yml) [![PHPStan](https://github.com/aryanjasala/term-trash/actions/workflows/phpstan.yml/badge.svg)](https://github.com/aryanjasala/term-trash/actions/workflows/phpstan.yml) [![Unit tests](https://github.com/aryanjasala/term-trash/actions/workflows/unit-tests.yml/badge.svg)](https://github.com/aryanjasala/term-trash/actions/workflows/unit-tests.yml) [![E2E tests](https://github.com/aryanjasala/term-trash/actions/workflows/e2e-tests.yml/badge.svg)](https://github.com/aryanjasala/term-trash/actions/workflows/e2e-tests.yml) [![Plugin Check](https://github.com/aryanjasala/term-trash/actions/workflows/plugin-check.yml/badge.svg)](https://github.com/aryanjasala/term-trash/actions/workflows/plugin-check.yml)

A trash for WordPress categories, tags and custom terms. Deleting a term moves it to Term Trash for 30 days instead of removing it, and an **Undo** shows up right after the delete.

![Undo right after deleting a category](.wordpress-org/screenshot-1.png)

## Features

- **Undo** on every term screen, for single and bulk deletes
- **Restore** puts back the term with the **same ID**, name, slug, description, parent and term meta
- Every post the term was assigned to is reattached, in the same order
- Child terms move back under it, and menu links to it are recreated in the same menu position
- Posts that only got "Uncategorized" because of the delete lose it again
- **Tools > Term Trash** lists deleted terms by taxonomy, with search, bulk restore and delete, and Empty trash
- Deleted terms are kept for 7, 30, 90 or 365 days, then a daily task deletes them for good
- Works with categories, tags, WooCommerce product categories and attributes, and any custom taxonomy with an admin screen

The same ID matters because navigation blocks, widgets and shortcodes store terms by ID, so they work again without editing.

## Installation

1. Download `term-trash.zip` from the [latest release](https://github.com/aryanjasala/term-trash/releases/latest).
2. In WordPress, go to **Plugins > Add New Plugin > Upload Plugin**, choose the zip and activate it.

Or with WP-CLI:

```sh
wp plugin install https://github.com/aryanjasala/term-trash/releases/latest/download/term-trash.zip --activate
```

Needs WordPress 6.6+ and PHP 7.4+.

## Usage

Delete a term as usual. An **Undo** notice appears above the list right away. For anything older, open **Tools > Term Trash**:

![Tools > Term Trash](.wordpress-org/screenshot-2.png)

Each row shows what the term was attached to. **What restore brings back** lists the posts, child terms and menus before you restore:

![What a restore brings back](.wordpress-org/screenshot-3.png)

### WP-CLI

```sh
wp term-trash list [--taxonomy=<taxonomy>] [--format=table|csv|json|ids]
wp term-trash restore <id>...
wp term-trash delete <id>... | --all
```

### For developers

| Hook | Use |
| --- | --- |
| `term_trash_supports_taxonomy` (filter) | Return `false` to delete terms of a taxonomy for good, as WordPress normally does. Menus are never kept |
| `term_trash_retention_days` (filter) | Days a trashed term is kept. Wins over the setting on the screen |
| `term_trash_restored` (action) | Fires after a restore with the new term ID, taxonomy, the saved snapshot and the reattached object IDs |

`AryanX\TermTrash\Plugin::instance()->restorer()->restore( $item_id )` restores from code.

## Development

```sh
composer install
npm ci
```

| Command | What it runs |
| --- | --- |
| `composer lint` | PHPCS with the WordPress standard and PHPCompatibility from PHP 7.4 |
| `composer analyse` | PHPStan level 6 with WordPress and WP-CLI stubs |
| `composer test` | PHPUnit unit tests. WordPress functions are mocked with Brain Monkey, so no database is needed |
| `npm run env:start` | WordPress and MariaDB in Docker on http://localhost:8895 |
| `npm run test:e2e` | Playwright end-to-end tests against that site, in headless Chrome |
| `npm run screenshots` | Regenerates the screenshots in `.wordpress-org/` |
| `npm run env:stop` | Removes the site and its database |

The plugin is mounted read-only into the Docker site, so nothing in WordPress can change or delete the source. The end-to-end run empties the site's content before it starts, so point it only at this throwaway site.

## Tests

- **7 unit tests**: snapshot storage and type casting, the retention period and its filter, which taxonomies are kept
- **5 end-to-end tests**: deleting a category from the Categories screen and pressing Undo, bulk delete with Undo, the trash screen (tabs, search, details, restore), bulk restore and delete, Empty trash, the retention setting, and restoring after the slug was reused

Each end-to-end test checks the result in the database through WP-CLI, not only on screen: posts, child terms, term meta and menu items.

## Contributing

`main` is protected: every change goes through a pull request, and it can only be merged once these pass:

- PHPCS and PHPStan
- PHPUnit on PHP 7.4, 8.0, 8.1, 8.2, 8.3 and 8.4
- the end-to-end suite against the latest WordPress
- the WordPress.org Plugin Check on the built zip
- the PR title check

Pull requests are squash-merged, so the **PR title becomes the commit on `main`** and decides the next release. Use a [Conventional Commit](https://www.conventionalcommits.org/) title:

| Title | Release |
| --- | --- |
| `feat: add bulk restore` | minor (1.1.0 to 1.2.0) |
| `fix: keep the notice on narrow screens`, `perf: ...` | patch (1.1.0 to 1.1.1) |
| `feat!: drop PHP 7.4`, or a `BREAKING CHANGE:` footer | major (1.1.0 to 2.0.0) |
| `docs:`, `ci:`, `chore:`, `test:`, `refactor:`, `build:` | none |

## Releases

Releases are automatic:

1. After each merge to `main`, the **Prepare release** workflow checks for `feat`, `fix`, `perf` or breaking commits since the last tag. If there are any, it opens (or updates) a `chore(release): x.y.z` pull request that bumps the version in the plugin header, the `VERSION` constant and the readme `Stable tag`, and adds the changelog entry to `readme.txt`.
2. Merging that pull request runs the **Release** workflow: it tags `vx.y.z`, builds the zip with `bin/build-zip.sh` (leaving out everything in `.distignore`) and publishes the GitHub release with the changelog entry as notes.

`bin/release.sh next` and `bin/release.sh notes` show locally what the next release would contain.

Deploying to WordPress.org is off until the repository variable `WPORG_DEPLOY` is `true` and the secrets `SVN_USERNAME` and `SVN_PASSWORD` are set. The deploy uses `.wordpress-org/` for the screenshots.

## License

GPL-2.0-or-later
