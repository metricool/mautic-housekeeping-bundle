# Housekeeping by Leuchtfeuer

This is a modified fork of [Leuchtfeuer's Housekeeping bundle](https://github.com/Leuchtfeuer/mautic-housekeeping-bundle), kept by Metricool for its own use since 2026-11-05. It adds `--exclude-emails` on `--page-hits`, `--batch-size`, `--max-rows`, `--archive`, `--lead-object` and the `housekeeping_skip_email_stats_tokens` setting.

## Overview / Purpose / Features
This plugin provides a Mautic Housekeeping Command for database cleanup purposes.

## Requirements / Version Support
- Mautic 5.0 - 7.2.1
- PHP 8.1 or higher

## Installation
### Composer
This plugin can be installed through composer.
### Manual Installation
Alternatively, it can be installed manually, following the usual steps:
- Download the plugin
- Unzip to the Mautic `plugins` directory
- Rename folder to `LeuchtfeuerHousekeepingBundle`
- In the Mautic backend, go to the `Plugins` page as an administrator
- Click on the `Install/Upgrade Plugins` button to install the Plugin.
  OR
- If you have shell access, execute `php bin\console cache:clear` and `php bin\console mautic:plugins:reload` to install the plugins.


## Command
Command to delete lead_event_log table entries, campaign_lead_event_log table entries, email_stats table entries where the referenced email entry is currently not published and email_stats_devices table entries.

Important: If referenced email is ever switched back to published, the contacts will get the email again.

```
bin/console leuchtfeuer:housekeeping
```
By default, entries older than 365 days are deleted from the CampaignLeadEventLog, LeadEventLog, EmailStats (only email_stats entries that referenced emails entry is currently not published), EmailStatsDevices and PageHit tables.

### Parameter
```
-d  | --days-old                | Specifies the minimum age of the entries to be deleted. Default: 365 days
-r  | --dry-run                 | Execute as dry run. No entries will be deleted
    | --batch-size              | Process this many rows at a time, each batch committed on its own, instead of one large transaction. Off by default.
    | --max-rows                | Stop the run once it has deleted or changed this many rows in all. Needs --batch-size. Rows removed through a foreign key cascade are not counted. Off by default.
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
- Add `--archive` to keep what the run removes, so it can be put back. The archive tables have the same columns as their source table, with no keys, and a generated column such as `email_stats.generated_sent_date` becomes a plain one. If the write and the copy of a batch ever differ, the batch is rolled back and the run stops. The command never empties the archive tables. After a Mautic upgrade changes a table's columns, the next run with `--archive` stops with an error until its archive table is renamed or dropped. A table whose delete would set another table's column to NULL is rejected.
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
$TIME -ao $OUT sudo -u www-data php $DOCROOT/bin/console leu:hou $OP -d $i 2>&1 >>$OUT
sleep 2
done
```

### Author
Leuchtfeuer Digital Marketing GmbH

mautic@Leuchtfeuer.com

## License
This plugin is licensed under the GPL v3 License.
