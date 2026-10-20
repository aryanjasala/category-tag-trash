# Term Trash

A trash for WordPress categories, tags and custom terms. Deleting a term moves it here for 30 days instead of removing it, and an **Undo** shows up right after the delete.

Restoring a term brings back:

- the term with the **same ID**, name, slug, description and parent
- its term meta
- every post it was assigned to, in the same order
- its child terms, moved back under it
- menu links that pointed to it, in the same menu position
- and it removes "Uncategorized" from posts that only got it because of the delete

The same ID matters because navigation blocks, widgets and shortcodes store terms by ID, so they work again without editing.

User-facing docs are in [`readme.txt`](readme.txt), which is what WordPress.org shows.

## Requirements

WordPress 6.6+, PHP 7.4+.

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
| `npm run env:stop` | Removes the site and its database |

The plugin is mounted read-only into the Docker site, so nothing in WordPress can change or delete the source. The end-to-end run empties the site's content before it starts, so point it only at this throwaway site.

## Tests

- **7 unit tests**: snapshot storage and type casting, retention period and its filter, which taxonomies are kept
- **5 end-to-end tests**: deleting a category from the Categories screen and pressing Undo, bulk delete with Undo, the trash screen (tabs, search, details, restore), bulk restore and delete, Empty trash, the retention setting, and restoring after the slug was reused

Each end-to-end test checks the result in the database through WP-CLI, not only on screen: posts, child terms, term meta and menu items.

## CI

| Workflow | Runs on | What it checks |
| --- | --- | --- |
| PHPCS | push to `main`, pull requests | WordPress coding standards |
| PHPStan | push to `main`, pull requests | Static analysis, level 6 |
| Unit tests | push to `main`, pull requests | PHPUnit on PHP 7.4, 8.0, 8.1, 8.2, 8.3 and 8.4 |
| E2E tests | push to `main`, pull requests | Playwright against the latest WordPress in Docker |
| Plugin Check | push to `main`, pull requests | The official WordPress.org Plugin Check, on the built zip |
| Release | tags `v*` | Builds the zip and publishes a GitHub release |

## Releasing

1. Update the version in three places: the plugin header, the `VERSION` constant and `Stable tag` in `readme.txt`. Add a `= x.y.z =` entry under `== Changelog ==`.
2. Push a tag: `git tag v1.2.0 && git push origin v1.2.0`.

The release workflow fails if the tag does not match all three versions or the changelog has no entry for it. Otherwise it runs PHPCS and PHPUnit, builds the zip with `bin/build-zip.sh` (leaving out everything in `.distignore`), and publishes a GitHub release with the changelog entry as notes.

Deploying to WordPress.org is off until the repository variable `WPORG_DEPLOY` is `true` and the secrets `SVN_USERNAME` and `SVN_PASSWORD` are set.

## Layout

```
term-trash.php          plugin header and PSR-4 autoloader
src/
  Plugin.php            creates the services and registers their hooks
  Snapshot.php          everything about a term at the moment it was deleted
  Snapshotter.php       captures terms on pre_delete_term, saves on delete_term
  Restorer.php          puts a term, its posts, children and menu links back
  Trash.php             storage (a hidden post type) and queries
  Purger.php            daily cleanup after the retention period
  Admin/                trash screen, list table, Undo notices
  Cli/                  wp term-trash list|restore|delete
tests/
  Unit/                 PHPUnit
  e2e/                  Playwright specs and the Docker site
```

## License

GPL-2.0-or-later
