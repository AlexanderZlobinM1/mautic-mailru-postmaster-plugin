# Changelog

## 0.5.15

- Replace the campaign guard email selector with an explicit sender-domain
  selector populated only from domains actually used by this Mautic instance.
- Resolve legacy email-based guard nodes at runtime and prefill their domain
  when edited, so existing campaigns keep working until they are saved again.
- Show the selected domain directly on the campaign guard node.

## 0.5.14

- Start a detached campaign watcher from Mautic's normal campaign trigger, so
  fast guard polling no longer requires MCD or a separate cron.
- Keep API latency completely outside the email path: neither email batches nor
  individual messages wait for Mail.ru statistics.
- Serialize runtime API reads across watcher processes with one shared,
  non-blocking seven-second domain lock. A competing watcher skips immediately
  instead of delaying campaign throughput.
- Stop the campaign when a fresh watcher response breaches its threshold while
  allowing messages already moving during Mail.ru's reporting delay to finish.
- Reload managed domain statistics before each new poll generation so parallel
  watcher processes see the result written by the process that queried Mail.ru.
- Hide delayed/date scheduling controls for guard nodes; protection is always
  configured as an immediate action directly before the protected email.

## 0.5.13

- Persisted as an internal development build while moving runtime polling from
  the scheduler into the plugin.

## 0.5.12

- Route every campaign-guard decision to a dedicated 30-day rotating audit
  file. The standard production handler buffers warnings until an error and
  therefore did not persist successful guard stops reliably.

## 0.5.11

- Log every campaign-guard evaluation with its source, timestamps, campaign,
  email, domain, stored statistics, thresholds and final decision.
- Find active Postmaster guards by Mautic's event `type` field so the plugin's
  scheduled evaluation and active-domain discovery can see saved guard nodes.

## 0.5.10

- Always list every historical sender domain from this Mautic instance in the
  Postmaster report, even when Mail.ru has returned no statistics for it.
- Display zero-valued current-day placeholders for sender domains without API
  data and allow their domain detail page to open normally.

## 0.5.8

- Make the legacy minute-level `--scheduled-full` MCD probe an API-free no-op.
  This preserves compatibility with MCD 0.10.30 without changing agent code or
  consuming the Mail.ru rate limit.

## 0.5.7

- Match the verified live API behavior: synchronize, store, aggregate and show
  only the rolling last 30 days. No routine or emergency command reads deeper
  history.
- Keep legacy `--full`, `--scheduled-full` and `--force-rescan` flags as safe
  compatibility aliases for the same 30-day window so an older scheduler
  configuration cannot trigger deep reads.
- Remove retention and weekly-history fields from the plugin tile; global
  settings return to the canonical enable switch, token and short instructions.
- Prune previously stored older statistics after making a database backup on
  the upgraded instance.

## 0.5.6

- Move first-run detection entirely into the plugin. MCD only invokes the
  current-month command; a fresh plugin database escalates that first call to
  the missing 365-day backfill, while an existing installation adopts its
  stored domain/month rows.
- Persist an initial-backfill completion marker only after the whole plugin run
  succeeds. Interrupted first runs resume from their per-domain/month markers.
- Treat a successful empty month as checked: the live Mail.ru API was verified
  to return only a rolling 30-day window even for older documented ranges, so
  returned daily row count is not a reliable completeness signal.

## 0.5.5

- Make first-run and scheduled 365-day synchronization a missing-month
  backfill driven by persistent, successful per-domain/month completion
  markers rather than the mere presence of a partial daily row.
- Persist each month attempt independently so a later API failure resumes at
  the first unfinished month.
- Keep current-month refresh and current-day active campaign guard polling
  independent from the completed-history markers.
- Add an explicit emergency `--force-rescan` mode that ignores markers and
  rebuilds the available year; MCD never invokes it automatically.

## 0.5.4

- Request detailed statistics separately for every sender domain, keeping API
  reads scoped to the domains actually used by the current Mautic instance.
- Retry short API `429` responses using Mail.ru's reported availability delay.
- Serialize regular and full bulk synchronization jobs so an MCD current-month
  run cannot collide with a long first or weekly full sync. Active campaign
  guard polling remains independent and domain-scoped.

## 0.5.3

- Remove the duplicate Mail.ru Postmaster section from Mautic's global
  Configuration screen. Enablement, token, retention and weekly synchronization
  settings now live only on the canonical plugin integration tile.
- Keep the campaign-guard location hint on the plugin tile.

## 0.5.2

- Hide the trend column in the retained-history domain summary as intended;
  Twig's `default` filter was treating the explicit `false` flag as empty and
  restoring the column.

## 0.5.1

- Clarify in the report that message and complaint counts come from Mail.ru
  Postmaster, which attributes traffic to the DKIM `d=` domain rather than to
  Mautic's `From` field or total send log.
- Correct the campaign guard help text for emails with an empty `From`: the
  effective global Mautic sender is used.

## 0.5.0

- Add a dedicated **Mail.ru Postmaster** section to Mautic's standard
  Configuration screen with the enable switch, token, retention and weekly
  full-sync schedule.
- Keep that section and the integration tile on one encrypted settings record;
  the API token is never duplicated into `config/local.php`.
- Resolve empty email `From` values through Mautic's canonical
  `CoreParametersHelper`, matching the actual `mailer_from_email` sender even
  when the raw DI parameter is an unresolved environment placeholder.

## 0.4.0

- Group domain history into year/month accordions. The current month opens by
  default, and opening another month closes the previous one.
- Aggregate sent messages and complaints across retained history in the domain
  summary while keeping reputation/deliverability from the latest day and
  hiding the non-meaningful aggregate trend column.
- Make the current calendar month the normal incremental synchronization
  window; previously stored old months are left untouched.
- Add a weekly full 365-day fallback synchronization, defaulting to Sunday at
  03:00 local time and configurable in the plugin or overridden from MCC.
- Add explicit `--current-month`, `--full`, and MCD-facing `--scheduled-full`
  command modes and document safe standalone cron equivalents.

## 0.3.0

- Add the MCD scheduling contract: active MCD profiles auto-detect the plugin
  per Mautic instance and run the two-day synchronization every ten minutes by
  default; MCC can override the instance switch and interval.
- Add a guarded fast path for published campaigns: MCD probes for active
  Postmaster guard nodes and refreshes only their verified sender domains every
  seven seconds, leaving room under Mail.ru's ten-requests-per-minute limit.
- Add configurable statistics retention (30/90/180/365 days), defaulting to the
  API maximum of 365 days. Pruning happens only after a successful regular sync.
- Split long history backfills into 30-day detailed-statistics windows and
  space calls by seven seconds, avoiding the API's effective 30-row response
  cap and its ten-requests-per-minute/domain limit.
- Resolve emails with an empty explicit `From` through the effective Mautic
  `mautic.mailer_from_email` container parameter, including environment-backed
  configuration, while retaining strict per-instance domain isolation.
- Document the standalone cron fallback for hosts without active MCD.
- Clarify where to find the campaign guard and preserve its required placement
  immediately before the email send action.

## 0.2.0

- Register as a canonical Mautic `AbstractIntegration` so the plugin tile opens its configuration form.
- Add the enable switch, validated token JSON field, linked setup instructions and Sales-snap.ru copyright.
- Keep the Mail.ru Postmaster report menu visible before the first synchronization.
- Add the `ru` locale used by Russian Mautic installations.

## 0.1.1

- Create the disabled Mautic integration configuration during plugin install or update.

## 0.1.0

- Initial Mail.ru Postmaster OAuth token configuration.
- Sender-domain filtering against real Mautic email `From` addresses.
- Detailed daily statistics persistence and Mautic report sources.
- Standalone domain summary and daily report screens.
- Campaign guard with separate spam and probably-spam thresholds.
- Automatic campaign unpublish and stop audit records.
