# Compatibility index

Last synchronized from confirmed workspace evidence: 24 September 2026.

Current release: `0.6.7` (`f4fe6e6`). Declared support: Mautic 7.x; PHP >=8.2.

| Mautic | Status | Confirmed scope |
| --- | --- | --- |
| 5.2.10 | — | Outside declared range |
| 6.0.9 | — | Outside declared range |
| 7.1.3 | ✓ | Fresh container, integration and form checks inherited from 0.6.6; 0.6.7 unit and service-wiring regression checks passed 24 Sep 2026 |
| 7.2.0 | ✓ | Fresh container, integration and form checks inherited from 0.6.6; 0.6.7 unit and service-wiring regression checks passed 24 Sep 2026 |

Version 0.6.7 adds a read-only Warmup domain-signal provider over already
persisted Postmaster rows. It does not change the provider API client, OAuth,
database schema, campaign guard or declared platform requirements. The local
0.6.7 suite passed 50 tests with 171 assertions, PHP lint, Composer validation
and public-service visibility checks on PHP 8.5.10. No fresh Mautic container
or Mail.ru provider exchange was exercised for this release.

Update this file and the workspace `../../COMPATIBILITY_INDEX.md` row with
every plugin-related change or review.
