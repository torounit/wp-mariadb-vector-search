# WP MariaDB Vector Search

## Structure

- `wp-mariadb-vector-search.php`: Plugin bootstrap and lifecycle hooks.
- `includes/class-plugin.php`: Main plugin class for hook registration.
- `uninstall.php`: Cleanup entrypoint for uninstall routines.

## Notes

- This scaffold intentionally excludes JavaScript tooling (`package.json`, `package-lock.json`, build configs).
- Add PHP-only functionality in `includes/` and wire hooks from `Plugin::register_hooks()`.
