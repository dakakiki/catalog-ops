# Making schedules run on time

A schedule fires when something tells WordPress to check for due work. Set that
up once and every schedule you create afterwards runs on time.

The commands below need nothing installed — `curl` ships with Windows 10 and
later and with every Linux host, and hosting panels expect exactly this shape of
command. **CatalogOps → Bulk edit → Scheduling → Server setup** prints them with
your own site URL already filled in.

Run it **every 5 minutes**. CatalogOps checks for due schedules on a 5-minute
cycle, so that is what makes an 02:00 schedule start at 02:00 rather than 02:55.
A run with nothing due costs almost nothing, and runs never pile up: the queue is
claimed, so a tick arriving while a long operation is still going exits straight
away.

## Windows

Win+R → `taskschd.msc`, then **Create Task…** in the right-hand panel — not
*Basic Task*, because the repeat setting only exists in the full dialog.

1. **General**: name it `CatalogOps queue`, and tick **Run whether user is logged
   on or not** so it survives you signing out.
2. **Triggers → New…**: **Daily**, start `00:00`. Tick **Repeat task every** and
   type `5 minutes` (the box accepts a typed value even though the dropdown does
   not offer 5). Set **for a duration of** to **Indefinitely**.
3. **Actions → New…**: **Start a program**.
   - **Program/script**: `curl.exe`
   - **Add arguments**: `-s "https://example.com/wp-cron.php?doing_wp_cron=1"`
4. **Settings**: leave **Do not start a new instance** selected, so a long
   overnight run is never doubled up by the next tick.

To check it, select the task and click **Run**. The **Last Run Result** column
should show `0x0`.

## Server (cPanel or cron)

In cPanel, **Advanced → Cron Jobs**. Set the interval to every 5 minutes and use:

```bash
curl -s "https://example.com/wp-cron.php?doing_wp_cron=1" >/dev/null 2>&1
```

Plesk, DirectAdmin and most other panels have the same screen under a similar
name. Over SSH the equivalent is `crontab -e` with:

```bash
*/5 * * * * curl -s "https://example.com/wp-cron.php?doing_wp_cron=1" >/dev/null 2>&1
```

## Checking it works

1. Create a schedule set to **Once**, a few minutes ahead, over a filter matching
   a handful of products.
2. Wait until past the start time plus five minutes.
3. **Operation history** should show a completed operation with source
   `schedule`, and the notification email should arrive.

If nothing happens, the task is usually not firing rather than failing: check
**Last Run Result** in Task Scheduler, or your host's cron log or cron email.

## If you do have WP-CLI

Optional, and better for very large catalogues. `action-scheduler run` works
outside the web server, so there is no request timeout to hit and it keeps
processing until the operation is *finished* rather than stopping after one batch
— a 30,000-product change started at 02:00 completes inside that single run.

```bash
*/5 * * * * cd /var/www/example.com && wp action-scheduler run --quiet >/dev/null 2>&1
```

Use the full path to `wp` if it is not on the cron user's PATH; `command -v wp`
gives it. Run it as the web server user, not root, so file ownership stays right.

On Windows, put the same command in a `.bat` file and point the task's
**Program/script** at that file instead of `curl.exe`:

```bat
@echo off
"C:\path\to\php.exe" "C:\path\to\wp-cli.phar" action-scheduler run --path="C:\path\to\wordpress"
```

## A note on scale

The 5-minute task decides *when* an operation starts, not how long it takes.
CatalogOps writes in adaptive chunks and keeps its own progress, so an interrupted
run resumes rather than restarting, and a schedule that comes due while another
operation is still writing waits for the write lock instead of colliding with it.
