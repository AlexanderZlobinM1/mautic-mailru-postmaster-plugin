# Changelog

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
