# MauticMailRuPostmasterBundle compatibility index

| Release | Mautic | Status | Evidence |
| --- | --- | --- | --- |
| 0.6.16 (branch `azlobin`) | 7.1.3, 7.2.0 | ✓ inherited; focused form regression passed | Campaign condition form builds with saved domains and independent history fields; malformed and empty `From` values are ignored and the effective global sender is retained. The 0.6.16 patch removes the unsupported Symfony `IntegerType` `html5` option that caused the live Mautic 7.2.2 editor HTTP 500. Exact provider delivery remains outside this unit scope. |

Declared support remains Mautic 7.x and PHP >=8.2. The compatibility mark is
inherited from the 0.6.15 fresh-kernel evidence; this patch changes only form
option compatibility and adds focused form tests.
