# Reference

## Hooks

| Hook | Type | Use |
| --- | --- | --- |
| `term_trash_supports_taxonomy` | filter | `( bool $keep, string $taxonomy )`. Return `false` to delete a taxonomy's terms for good, as WordPress normally does. Menus (`nav_menu`) are never kept |
| `term_trash_retention_days` | filter | `( int $days )`. Days a trashed term is kept. Wins over the setting on the screen |
| `term_trash_restored` | action | `( int $term_id, string $taxonomy, Snapshot $snapshot, int[] $object_ids )`. Fires after a restore |

```php
// Never keep deleted tags.
add_filter( 'term_trash_supports_taxonomy', function ( $keep, $taxonomy ) {
	return 'post_tag' === $taxonomy ? false : $keep;
}, 10, 2 );
```

## WP-CLI

```sh
wp term-trash list [--taxonomy=<taxonomy>] [--format=table|csv|json|count|ids]
wp term-trash restore <id>...
wp term-trash delete <id>...
wp term-trash delete --all
```

`<id>` is the trash item ID from `wp term-trash list`, not the term ID.

## PHP API

```php
$plugin = AryanX\TermTrash\Plugin::instance();

$plugin->trash()->find( array( 'taxonomy' => 'category' ) );   // [ 'ids' => [...], 'total' => n ]
$plugin->restorer()->restore( $item_id );                       // [ 'term_id' => n, 'notes' => [...] ] or WP_Error
```

## How a restore works

The term is captured on `pre_delete_term`, before WordPress touches posts or child terms, and saved on `delete_term` once the delete has really happened.

Restoring:

- inserts the term row with its original `term_id` and `term_taxonomy_id` when they are still free, and otherwise gives it new ones and says so
- re-adds the meta and reattaches objects with their `term_order`, skipping objects deleted in the meantime
- moves children back only if nobody moved them since
- recreates the menu items in their old position

## Data

Each trashed term is a `term_trash_item` post (hidden, not exported) with the snapshot in post meta. The setting is the `term_trash_settings` option. Uninstalling removes both and the daily `term_trash_purge` cron event.
