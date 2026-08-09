# Changelog

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
