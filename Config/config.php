<?php

return [
    'name'        => 'Mautic Housekeeping',
    'description' => 'Database Cleanup Command to delete lead_event_log table entries, campaign_lead_event_log table entries, email_stats table entries where the referenced email entry is currently not published and email_stats_devices table entries.',
    'version'     => '1.0.0',
    'author'      => 'Metricool',
    'parameters'  => [
        'housekeeping_skip_email_stats_tokens' => false,
    ],
];
