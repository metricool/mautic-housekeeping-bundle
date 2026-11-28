# Mautic Housekeeping

Mautic Housekeeping deletes old rows from Mautic's largest tables, so the database stays small. It started from Leuchtfeuer's Housekeeping bundle (see Credits) and adds `--exclude-emails` on `--page-hits`, `--batch-size`, `--max-rows`, `--archive`, `--lead-object`, `--ip-addresses`, `--ip-slice`, `--unlink-segments`, `--exclude-segment-emails`, `--anonymous-contacts` and the `housekeeping_skip_email_stats_tokens` setting.

## Overview / Purpose / Features
This plugin provides a Mautic Housekeeping Command for database cleanup purposes.

## Requirements / Version Support
- Mautic 5.2 or 7.2
- PHP 8.1 to 8.3 on Mautic 5.2, PHP 8.2 to 8.4 on Mautic 7.2

## Installation
### Composer
This plugin can be installed through composer, as `metricool/mautic-housekeeping-bundle`. It goes into `plugins/HousekeepingBundle`.
### Manual Installation
Alternatively, it can be installed manually, following the usual steps:
- Download the plugin
- Unzip to the Mautic `plugins` directory
- Rename folder to `HousekeepingBundle`
- In the Mautic backend, go to the `Plugins` page as an administrator
- Click on the `Install/Upgrade Plugins` button to install the Plugin.
  OR
- If you have shell access, execute `php bin\console cache:clear` and `php bin\console mautic:plugins:reload` to install the plugins.
### Turn it on
After either way of installing, open "Mautic Housekeeping" on the `Plugins` page, set `Is Enabled?` to `Yes` and save. Until then the command stops with "not enabled" and `housekeeping_skip_email_stats_tokens` does nothing.
### Moving from Housekeeping by Leuchtfeuer
The old plugin's switch does not carry over. After the move:
- Remove `plugins/LeuchtfeuerHousekeepingBundle`, install this plugin and turn it on again.
- Change every crontab line from `leuchtfeuer:housekeeping` to `mautic:housekeeping`.


## Command
Command to delete lead_event_log table entries, campaign_lead_event_log table entries, email_stats table entries where the referenced email entry is currently not published and email_stats_devices table entries.

Important: If referenced email is ever switched back to published, the contacts will get the email again.

```
bin/console mautic:housekeeping
```
By default, entries older than 365 days are deleted from the CampaignLeadEventLog, LeadEventLog, EmailStats (only email_stats entries that referenced emails entry is currently not published), EmailStatsDevices and PageHit tables.

### Parameter
```
-d  | --days-old                | Specifies the minimum age of the entries to be deleted. Default: 365 days
-r  | --dry-run                 | Execute as dry run. No entries will be deleted
    | --batch-size              | Process this many rows at a time, each batch committed on its own, instead of one large transaction. Off by default.
    | --max-rows                | Stop the run once it has deleted or changed this many rows in all. Needs --batch-size. Rows removed through a foreign key cascade are not counted, and neither are the segment links that --unlink-segments removes. Off by default.
    | --archive                 | Copy every row the run deletes or changes, and the rows a foreign key cascade removes with them, to a housekeeping_archive_<table> table in the same transaction. Needs --batch-size. Off by default.
-0  | --optimize-tables         | Runs OPTIMIZE TABLE on all Mautic tables. 
-i  | --cmp-id                  | Delete only data for a specific campaign ID from campaign_lead_event_log
-c  | --campaign-lead           | Only entries from the campaign_lead_event_log table will be deleted
-m  | --email-stats             | Only entries from the email_stats table where the referenced email entry is currently not published and from the email_stats_devices table will be deleted.
-t  | --email-stats-tokens      | Only set tokens fields in Email Stats Records to NULL instead of deleting the whole record
-l  | --lead                    | Only entries from the lead_event_log table will be deleted.
    | --lead-object             | Only these objects from lead_event_log will be deleted, the rest are kept. Accepts comma-separated list (e.g., segment,import). Implies --lead.
-p  | --page-hits               | Only entries from the page_hits table will be deleted.
    | --exclude-emails          | Exclude specific email IDs from event log cleanup. Accepts comma-separated list of email IDs (e.g., 1,44,61). Works with --email-stats, --email-stats-tokens and --page-hits.
    | --exclude-segment-emails  | Also exclude every email that a segment filter names, read from all segments when the run starts. Add more filter fields whose values are email IDs, comma-separated (e.g., --exclude-segment-emails=plugin_email_clicked). Works with --email-stats, --email-stats-tokens and --page-hits. Off by default.
    | --unlink-segments         | With --email-stats, remove the segments of each email whose rows are deleted, so it cannot be sent again to the same contacts. Needs --batch-size. Off by default.
    | --anonymous-contacts      | Delete the contacts that were never identified and never active, added more than --days-old days ago. Needs --batch-size. Not part of the default cleanup.
    | --ip-addresses            | Delete the rows of ip_addresses that no other table uses. Needs --batch-size. Not part of the default cleanup.
    | --ip-slice                | With --ip-addresses, check only slice N of M of the IDs, for example 5/31. Off by default.
```

### Config parameters
Set in `config/local.php`.
```
housekeeping_skip_email_stats_tokens | Set to true to stop saving tokens in email_stats (Mautic 5.1+). Sending is not affected, but "view in browser" shows them unfilled and its links do not work. Stats already stored lose their tokens when saved again, for example when the email is opened. Default: false
```

### Notice
- Every last entry from the campaign_lead_event_log per campaign will be kept. This is due to contacts restarting campaigns if there is no last step preserved in the log.

### Deleting huge ammounts of data
- Use `--batch-size` (for example `--batch-size=5000`), so that each batch is committed on its own. If a run stops, what it already committed stays deleted, and running it again carries on. `--campaign-lead` works out which rows to keep for every batch, so use a larger batch size there.
- Add `--max-rows` a little above what `--dry-run` counts, so a wrong `--days-old` cannot delete far more than you expect. The run then stops at the limit and says so in its output. The rest waits for the next run.
- Add `--archive` to keep what the run removes, so it can be put back. The archive tables have the same columns as their source table, with no keys, and a generated column such as `email_stats.generated_sent_date` becomes a plain one. If the write and the copy of a batch ever differ, the batch is rolled back and the run stops. The command never empties the archive tables. After a Mautic upgrade changes a table's columns, the next run with `--archive` stops with an error until its archive table is renamed or dropped. A table whose delete would set another table's column to NULL is rejected, and so is one whose cascade does not come through a single key on its id, and one that a table in another database points at.
- It might happen that the plugin fails to delete if the data is too large to handle. In that case: Create the following bash script and iterate through the deletion day by day:
```
#!/bin/sh
DOCROOT="path/to/mautic"
TIME="/usr/bin/time"
OUT="$DOCROOT/../log/housekeeping-loop.date +%Y%m%d_%H%M%S"
START=put_start_day_here #(for example 200)
END=put_end_day_here  #(for example 100)
OP="put_your_operator_here" #(for example "-m" for emails / "-p" for page_hits | needs to be in "")

seq $START -1 $END |while read i ; do
echo "********** $i **********" >> $OUT
$TIME -ao $OUT sudo -u www-data php $DOCROOT/bin/console mautic:housekeeping $OP -d $i 2>&1 >>$OUT
sleep 2
done
```

### Segment emails and `--email-stats`
A segment email goes to the contacts in its segments who have no `email_stats` row for it. `--email-stats` deletes these rows for closed emails: emails that are unpublished, or whose end date passed more than `--days-old` days ago. If such an email is published again, it goes again to the same contacts.

`--unlink-segments` stops that. Each batch removes the segments of the emails whose rows it deletes, in the same transaction, before it deletes the rows. An email with no segments sends to nobody. To send it again, someone has to add segments to it in the email form.
- Variants and translations count as one email, because Mautic does not send an email to contacts who already got one of its variants or translations. When the rows of one of them go, all of them lose their segments. The rows of a closed email are kept while one of its variants or translations is still open, or is in `--exclude-emails`.
- Each batch checks and locks these related emails before it writes, so an email published during the run keeps its segments.
- Only emails that lose rows in the run lose their segments. An email unpublished to pause a send counts as closed too, so it loses its segments if it has rows older than `--days-old`.
- The removed links are copied first to `housekeeping_archive_email_list_xref`, with or without `--archive`. To put them back, copy the rows from there into `email_list_xref`.
- The removed links do not count towards `--max-rows`. Each email loses its links only when the run deletes rows of it, so the limit bounds them too.
- It needs `--batch-size`. A dry run counts the links it would remove.
```
bin/console mautic:housekeeping --email-stats --unlink-segments --days-old=365 --batch-size=5000
```

### Emails named in segment filters
A segment can filter on one email, with "Read a specific email" or "Was sent a specific email". Its members come from the `email_stats` rows of that email, so deleting those rows changes who is in the segment. `--exclude-segment-emails` keeps them.
- When the run starts, before it deletes anything, it reads the filters of every segment, published or not. Every email they name is excluded, as if it were in `--exclude-emails`. It adds to `--exclude-emails` and works with the same options: `--email-stats`, `--email-stats-tokens` and `--page-hits`.
- It reads the `lead_email_received` and `lead_email_sent` filters, with any operator. A plugin can add a filter whose values are email IDs too, for example one on clicks in `page_hits`. Add its field name to the option, comma-separated: `--exclude-segment-emails=plugin_email_clicked`.
- If the filters of a segment cannot be read, or one of these filters holds a value that is not an email ID, the run stops with an error before it deletes anything.
- Filters such as "Read any email (date)", "Read any email X number of times" or "Clicked any link from any email" read the same rows without naming an email. So do these filters with the "empty" or "not empty" operator, because Mautic then ignores their value. No email ID can keep these segments as they are, so the output lists them in a warning.
- A filter with no field name stops the run before it deletes anything too. Filters that a custom operator filter holds are read like any other filter.
- The filters are read once per run. A filter added during a run counts from the next run.
- With `--unlink-segments`, the variants and translations of a named email keep their `email_stats` rows and their segments too.
```
bin/console mautic:housekeeping --email-stats --page-hits --exclude-segment-emails --days-old=365 --batch-size=5000
```

### Anonymous contacts that were never active
Mautic's own `mautic:maintenance:cleanup` deletes anonymous contacts by their last activity. A contact whose `last_active` is empty never matches, so it stays for good. With `--gdpr` the command does reach these contacts, but it deletes identified contacts too.

`--anonymous-contacts` deletes the contacts that:
- were never identified (`date_identified` is empty) and were never active (`last_active` is empty),
- were added more than `--days-old` days ago (a contact with no `date_added` stays),
- have every unique identifier field empty, `email` included. A value of only spaces counts as a value. The fields are read from `lead_fields` when the run starts, so an identifier field you added counts too,
- have no row in a table whose foreign key to `leads` does not cascade, such as `page_hits`, `email_stats` or `form_submissions`. Such a row means the contact did something. These tables are read from the database when the run starts.

How a run works:
- The rows that a foreign key cascade removes with a contact go too: its segment memberships, its `lead_event_log` rows, its IP links, its campaign memberships and the like.
- It needs `--batch-size`. `--max-rows` counts the contacts, not the rows their cascade removes.
- Each batch runs at READ COMMITTED, so it locks only the contacts it deletes. If binary logging is on, `binlog_format` must be `ROW` or `MIXED`.
- Each batch checks again, under lock, that no row in those other tables points at its contacts. If one does, the batch is rolled back and the run stops.
- With `--archive`, each batch copies its contacts to `housekeeping_archive_leads` and the rows their cascade removes to `housekeeping_archive_<table>`, in the same transaction. Before it copies, it checks that no table points at those rows, because the delete would change such a row without a copy, for example a `campaign_lead_event_failed_log` row. If one does, the batch is rolled back and the run stops. The output says how many rows the cascade removed from each table.
- `--dry-run` counts the contacts and the rows their cascade would remove from each table. It also counts the rows one level further down that point at those rows. A run with `--archive` stops on them, and a run without it removes or changes them, or stops where their key blocks the delete.
- A foreign key from a table in another database, to `leads` or to any table its cascade reaches, stops the run before it deletes anything.
- A foreign key from `leads` to itself stops the run before it deletes anything, because MySQL cannot delete from a table that the same statement reads.
- A foreign key from `leads` to a table its cascade reaches stops the run before it deletes anything, because the delete could then remove or change other contacts through it.
- Rows in tables with no foreign key, such as `integration_entity`, keep the contact's ID, as with Mautic's own cleanup.

To put contacts back, copy them first, then their rows. `housekeeping_archive_leads` has `generated_email_domain` as a plain column, so leave it out of the column list:
```
INSERT INTO leads (<all columns except generated_email_domain>) SELECT <same columns> FROM housekeeping_archive_leads WHERE ...;
INSERT INTO lead_lists_leads SELECT * FROM housekeeping_archive_lead_lists_leads WHERE lead_id IN (...);
```
Do the same for each other `housekeeping_archive_` table the run filled, for example `lead_event_log` and `lead_ips_xref`.

```
bin/console mautic:housekeeping --anonymous-contacts --days-old=180 --batch-size=500 --max-rows=100000 --archive
```

### Unused IP addresses
`--ip-addresses` deletes the rows of `ip_addresses` that no other table uses. It is not part of the default cleanup. `--days-old` has no effect on it, because the table has no date column. It needs `--batch-size`, and MySQL 8.0 or MariaDB 10.6 or newer. If binary logging is on, `binlog_format` must be `ROW` or `MIXED`, because each batch runs at READ COMMITTED.

An address counts as used when one of these tables points at it:
- Any table with a foreign key to `ip_addresses`, whatever the key does on delete. The command reads the keys each time it starts, so tables that Mautic or a plugin add later count too.
- Any `housekeeping_archive_` table with the same column, so archived rows can always be put back. The command adds an index named `housekeeping_ip_id` to these tables when they have none, so they are quick to check. This takes a few seconds once per table and changes no rows. A dry run does it too. If you rename an archive table, keep your Mautic table prefix and `housekeeping_archive_` at the start of its name.

The command stops with an error before it deletes any address when no foreign key points at `ip_addresses`, when a key comes from another database, or when any other table has the same column with no foreign key. A new row in such a table does not wait for the delete, so the address it points at could be deleted under it. Add a foreign key from that column to `ip_addresses`, or drop the table, then run the command again. Other cleanups in the same run, such as `--page-hits`, run first, so their deletes are already done when this error stops the run.

How a run works:
- It leaves out the newest 1000000 IDs, because Mautic saves a new address a moment before the row that uses it.
- It reads the IDs in small steps, so no query runs for long.
- Each batch is locked, checked again and deleted in its own short transaction. It skips any address that another transaction holds, and keeps any address that came into use since the IDs were read. The output says how many were kept, and a later run checks them again. `--batch-size=1000` keeps each lock short.
- One narrow case remains: a request that looked up an address before the batch, and saves its row after the delete, gets a foreign key error. Mautic logs it and loses that one row, for example a page hit. A request that was creating a new contact fails. The next visit from that address creates the address again.
- During the batches, the command lowers its own lock waits to 30 seconds and puts them back at the end. A batch that waits longer is rolled back and tried once more, so it never holds its locks for long. If the second try fails too, the run stops with an error, and batches already done stay done.
- Each batch runs at READ COMMITTED, so its delete only holds up new rows that point at an address in the batch. Mautic can keep adding other rows to the tables that use an address.
- When `--max-rows` stops a run early, the output says the ID it stopped at, so you know which IDs it did not check.
- If a foreign key to `ip_addresses`, or a table with the same column, is added or dropped during the run, the run stops with an error. Batches already done stay done. Run it again.
- With `--archive`, each deleted address is copied to `housekeeping_archive_ip_addresses` in the same transaction. To put one back, copy its row from there into `ip_addresses`. New addresses never reuse those IDs.
- Do not also run Mautic's `mautic:unusedip:delete`. It does not know about the archive tables.
- Deleted rows free space inside the table, but the database file does not shrink. Do not run `--optimize-tables` to get it back, because it rebuilds every table.

One slice a night:
Checking every address of a large table can take hours. `--ip-slice=N/M` checks one slice instead. The IDs are cut into blocks of 100000. Slice N takes block N, then every Mth block after it. Run a different N each night, and the whole table is checked every M nights:
```
bin/console mautic:housekeeping --ip-addresses --ip-slice=5/31 --batch-size=1000 --max-rows=200000 --archive
```
In a crontab, write each `%` as `\%`. This value picks the next slice every day, so each slice runs every 31 days:
```
--ip-slice=$(( $(date +\%s) / 86400 \% 31 + 1 ))/31
```
This value moves to the next slice every day, even when `--max-rows` or an error stopped the slice before. The IDs it did not reach wait until that slice comes back, 31 days later, unless you run that slice again by hand. So set `--max-rows` above what one slice deletes, or leave it out while a large backlog is cleared.

A run without `--ip-slice` checks the whole table in one go. If it stops, run it again. It starts from the first ID again.

`--dry-run` counts what a run would delete, in the same small steps and outside any transaction. It reads as much as a real run, so try it on one slice first.

## Code checks
The code passes PHPStan at level 6 (`phpstan.neon`) and Mautic's PHP-CS-Fixer rules (`.php-cs-fixer.php`), with one difference: `@return` tags that repeat the native type stay.

The checks and the tests run from a Mautic install with its development dependencies, with the plugin in `plugins/HousekeepingBundle`. The tests run on Mautic 7.2 with `phpunit.xml.dist` and on Mautic 5.2 with `phpunit-mautic5.xml.dist` (PHPUnit 9). The other checks run on Mautic 7.2.

GitHub Actions (`.github/workflows/ci.yaml`) run the tests on Mautic 5.2.4 with PHP 8.1, 8.2 and 8.3, on Mautic 7.2.0 with PHP 8.3 and on the latest 7.2 release with PHP 8.2, 8.3 and 8.4, and the other checks on the latest 7.2 release, for every pull request and every push to `main`.

## Credits
Based on [Housekeeping by Leuchtfeuer](https://github.com/Leuchtfeuer/mautic-housekeeping-bundle), modified since 5 November 2026.

Original author: Leuchtfeuer Digital Marketing GmbH, mautic@Leuchtfeuer.com

## License
This plugin is licensed under the GPL v3 License, or any later version, as the original. See [LICENSE](LICENSE).
