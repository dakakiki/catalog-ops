# Making schedules run on time

A CatalogOps schedule is a promise: *change these products at 2am*. Whether that
promise is kept has nothing to do with CatalogOps and everything to do with what
drives WordPress's background queue on your server.

## Why the default is wrong for this

WordPress has no scheduler of its own. "WP-Cron" is a list of due jobs that gets
checked **when somebody loads a page**. On a busy shop that is close enough to
real scheduling that nobody notices the difference.

It fails at exactly the job scheduling exists for. You schedule 30,000 price
changes for 2am *because* the shop is quiet then — and a quiet shop is one with no
page loads, so nothing runs. The queue sits until the first visitor next morning,
and then thousands of products start changing during opening hour: the one time
you were trying to avoid.

The fix is to let the operating system do the timing. Set up one repeating task
and every schedule you ever create fires on time.

## What the task has to do

Run Action Scheduler's queue. Two forms, in order of preference.

**With WP-CLI** — the reliable one. It runs outside the web server, so there is no
request timeout and no PHP memory limit from Apache/nginx to hit, and it keeps
processing batches until the operation is *finished* rather than stopping after
one. A 30,000-product change started at 2am completes inside that single run.

```bash
wp action-scheduler run --path=/path/to/wordpress
```

**Without WP-CLI** — shared hosting, cPanel, no shell. Fetch the cron endpoint
over HTTP. It works, but each request is bounded by the web server's timeout, so a
very large operation continues across several runs instead of finishing in one.

```bash
curl -sS "https://example.com/wp-cron.php?doing_wp_cron=1" >/dev/null 2>&1
```

## How often

**Every 5 minutes.** CatalogOps checks for due schedules on a 5-minute supervisor,
so that cadence is what makes an "02:00" schedule start at 02:00 rather than 02:55.

A tick with nothing due costs a WordPress bootstrap and exits — cheap enough to run
all day. Ticks never pile up on each other: Action Scheduler claims the work, so a
second run arriving while a long operation is still going finds nothing to claim
and exits immediately.

Hourly is the finest recurrence CatalogOps offers, so 5 minutes is comfortably
precise. Going below that buys nothing.

## Windows

Two steps: a batch file, then a scheduled task pointing at it.

**1. Save the batch file** somewhere permanent — not the Desktop, not a temp
folder. Fill in your own PHP and WP-CLI paths:

```bat
@echo off
"C:\wamp64\bin\php\php8.1.29\php.exe" "C:\wamp64\wp-cli\wp-cli.phar" action-scheduler run --path="C:\wamp64\www\your-site"
```

Run it once by double-clicking. If it prints Action Scheduler output (even "no
actions to run"), the paths are right. If a window flashes and vanishes, open a
Command Prompt and run it there to see the error.

**2. Create the task** — Win+R → `taskschd.msc`:

1. **Create Task…** in the right-hand panel. Not *Basic Task* — the repeat setting
   you need only exists in the full dialog.
2. **General** tab: name it `CatalogOps queue`. Tick **Run whether user is logged
   on or not**, so it survives you signing out.
3. **Triggers** tab → **New…**: choose **Daily**, start at `00:00`. Then tick
   **Repeat task every** and type `5 minutes` into the box (it accepts typed values
   even though the dropdown does not offer 5). Set **for a duration of** to
   **Indefinitely**.
4. **Actions** tab → **New…**: **Start a program**, and browse to your `.bat` file.
5. **Settings** tab: leave **Do not start a new instance** selected under "If the
   task is already running". This is what stops a long overnight run being
   doubled up by the next tick.

Verify from an elevated Command Prompt:

```bat
schtasks /Run /TN "CatalogOps queue"
schtasks /Query /TN "CatalogOps queue" /V /FO LIST
```

`Last Result: 0` means it ran cleanly.

If you would rather skip the dialog entirely:

```bat
schtasks /Create /TN "CatalogOps queue" /SC MINUTE /MO 5 /TR "C:\path\to\catalogops-queue.bat" /RL HIGHEST /F
```

## Linux server / cPanel

**With shell access**, `crontab -e`:

```bash
*/5 * * * * cd /var/www/example.com && wp action-scheduler run --quiet >/dev/null 2>&1
```

Use the full path to `wp` if it is not on the cron user's PATH — cron runs with a
much smaller environment than your login shell, and `wp: command not found` is the
usual first failure. `command -v wp` tells you the path to use.

Run it as the **web server user** (often `www-data`), not root, so files the run
touches keep the right ownership.

**On cPanel / Plesk**, use the Cron Jobs screen. Set the interval to every 5
minutes and paste one of these as the command:

```bash
cd /home/USER/public_html && /usr/local/bin/wp action-scheduler run --quiet >/dev/null 2>&1
```

```bash
curl -sS "https://example.com/wp-cron.php?doing_wp_cron=1" >/dev/null 2>&1
```

## Turning off the visitor-driven cron

Optional. Once the OS task is running you can stop WordPress from also firing the
queue on page loads, which removes a small amount of work from every request:

```php
define( 'DISABLE_WP_CRON', true );
```

in `wp-config.php`, above the `/* That's all, stop editing! */` line.

Only do this **after** you have confirmed the task works. With it on and no task,
nothing runs at all — no schedules, no CatalogOps operations, and no WordPress
maintenance either.

## Checking it works

The Scheduling panel in CatalogOps prints these commands with your own paths
already filled in — open **CatalogOps → Schedules → Make schedules run on time**.

To prove the whole chain end to end:

1. Create a schedule set to **Once**, a few minutes from now, over a filter that
   matches a handful of products.
2. Wait past the start time plus the 5-minute window.
3. **Operation history** should show a completed operation, source `schedule`.
4. The notification email should arrive with the applied/skipped counts.

If nothing happens, work backwards:

```bash
wp action-scheduler run          # does the queue run at all, by hand?
wp action-scheduler status       # anything pending, or a backlog of failures?
wp cron event list               # is WordPress's own cron even reachable?
```

A pending action that never runs usually means the task is not firing (check
`Last Result` in Task Scheduler, or the cron user's mail); an action that runs but
fails shows up under **Tools → Scheduled Actions** with its error.

## A note on scale

The 5-minute task decides *when* an operation starts, not how long it takes.
CatalogOps processes in adaptive chunks and keeps its own progress, so an
interrupted run resumes rather than restarting, and a schedule that fires while a
previous operation is still writing waits for the write lock instead of colliding
with it.
