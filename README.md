# WP Cron Monitor

See every scheduled WP-Cron event, spot overdue ("missed") jobs, and fix them — without digging through `wp cron event list`.

## Features

- **Full event table** at **Tools → Cron Monitor**: hook name, next run (with relative time), recurrence, args
- **Overdue highlighting** — events past their due time (+ 10 min grace) are flagged red; usually means cron isn't firing (common on `DISABLE_WP_CRON` sites with no server cron)
- **Run now** — executes any event immediately via `do_action_ref_array()`, exactly as cron would
- **Unschedule** — removes one event (nonce-protected, with confirm dialog)
- **Admin notice** — warns you (max once per week) when overdue events exist

## Filter

```php
// Change the "overdue" grace period (default 600 seconds):
add_filter( 'wcm_overdue_grace', function() { return 3600; } );
```

## Notes

- Events are identified by hook + args signature, so two schedules of the same hook are handled independently.
- Run-now executes the hook in the current request — long jobs may hit PHP timeouts, same as in any manual run.
- Uninstalling removes the plugin's only option.

## License

MIT
