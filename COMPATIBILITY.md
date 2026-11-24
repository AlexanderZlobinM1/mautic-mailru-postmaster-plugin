# Mautic compatibility

Supported Mautic range remains 7.x.

Run against each supported Mautic installation with this plugin installed:

```sh
MAUTIC_ROOT=/path/to/mautic php -d memory_limit=1G Tests/runtime-compatibility.php
```

The test compiles an isolated container, instantiates this plugin’s integrations
and form types, then removes its temporary cache. Production cache and provider
settings are not changed. Live external-provider delivery requires a separate
configured acceptance environment; a successful kernel test does not prove delivery.

## Verified on 6 September 2026

Fresh-kernel service instantiation and form construction/resolution passed on `7.2.0`, `7.1.3`. Mautic 5/6 used PHP 8.2.33; Mautic 7 used PHP 8.4.25. External delivery and OAuth/CAPTCHA provider exchanges were not exercised.

## 0.6.16 form regression — 24 November 2026

The campaign guard/condition form now builds on the Mautic 7 Symfony Form stack:
the history-depth `IntegerType` no longer receives the removed `html5` option.
Focused condition-form construction and malformed/empty sender-address tests
pass. The release inherits the existing Mautic 7.1.3/7.2.0 compatibility scope;
the exact live Mautic 7.2.2 HTTP 500 was reproduced locally as
`UndefinedOptionsException` for `IntegerType` option `html5`.
