# CosmicSting patch for Magento Open Source and Mage-OS (CVE-2024-34102)

`bigcartenergy/module-cosmicsting-patch` is a free security patch for Magento Open Source and Mage-OS by Big Cart Energy. It closes CVE-2024-34102 ("CosmicSting") on the Magento releases that never received Adobe's fix, as a composer module: one plugin on the Web API input processor that applies the same rule as the official fix and returns the official error message. No core file is modified, nothing is added to the database or the admin, and `composer remove` takes it out without a trace.

## What it fixes

CVE-2024-34102 (CVSS 3.1 score 9.8, critical; CWE-611, XML external entity) lives in `Magento\Framework\Webapi\ServiceInputProcessor`, the class that turns a REST or SOAP request body into the objects a service method expects: it accepted the PHP XML classes as types to build from request data. Adobe's fix, shipped in June 2024, makes the processor refuse `SimpleXMLElement` and `DOMElement` subclasses with the error "Invalid data type". This module applies the same refusal from a `before` plugin on `convertValue()`, the processor's one public entry point, which the processor also passes through for every nested value, and it refuses the two classes themselves as well as their subclasses. Simple types and `mixed` are never checked; every other request is untouched.

## Who needs it

We read Adobe's fix in the Magento Open Source code of every release below, so the table says what the code says.

| Your Magento Open Source release | Official fix | This module |
| --- | --- | --- |
| 2.3.0 to 2.3.7-p4 | None | Yes |
| 2.4.0 to 2.4.3-p3 | None | Yes |
| 2.4.4 to 2.4.4-p8, 2.4.5 to 2.4.5-p7, 2.4.6 to 2.4.6-p5, 2.4.7 | Adobe's isolated patch VULN-27015, or the patch release of your line below | Yes, if you have applied neither |
| 2.4.4-p9 and later, 2.4.5-p8 and later, 2.4.6-p6 and later, 2.4.7-p1 and later, 2.4.8, 2.4.9 | Included | Not needed. Installed anyway, it is redundant and harmless: the processor refuses the same types a moment later |

Mage-OS installs this module unchanged. Whether a Mage-OS release already carries the fix follows the Magento Open Source release it is based on.

Adobe's bulletin for this vulnerability is APSB24-40. Adobe's knowledge base offers the isolated patch for the 2.4.4 to 2.4.7 lines, and recommends rotating the store's encryption keys as well; see "After any suspected exposure" below.

## Tested versions

A version that is not in this table has not been tested. Use at your own risk and test on staging first.

| Magento Open Source | PHP | Database | Result |
| --- | --- | --- | --- |
| 2.4.9 | 8.5 | MySQL 8.4 | Pass: install and `setup:di:compile`; REST guest checkout to a placed order, customer account create, product search with filters and sort orders, admin product save with custom and extension attributes; one SOAP call with search criteria; the guard refuses an XML-typed parameter and logs it; the same calls behave the same with and without the module (Adobe's fix is already in this release); removal with a REST pass afterwards; unit tests in place. Alone and together with the SessionReaper patch |
| 2.4.8 | 8.3 | MySQL 8.4 | Pass: the same checks (Adobe's fix is already in this release) |
| 2.4.7 | 8.2 | MySQL 8.0 | Pass: the same checks; on this release the module is the only guard |
| 2.4.6 | 8.1 | MySQL 8.0 | Pass: the same checks; on this release the module is the only guard |
| 2.4.3-p3 | 7.4 | MySQL 8.0 | Pass: the same checks; on this release the module is the only guard |
| 2.3.7-p4 | 7.4 | MySQL 5.7 | Pass: the same checks; on this release the module is the only guard |
| 2.3.5-p2 ¹ | 7.3 | MySQL 5.7 | Pass: the same checks; on this release the module is the only guard |
| 2.3.0 ¹ | 7.2 | MySQL 5.7 | Pass: the same checks; on this release the module is the only guard. 2.3.0's processor converts a service method's own parameters and setter values, where the module refuses the XML classes, but copies nested constructor data without converting it, so that data never reaches an XML class on this release with or without the module |

¹ Installed from Adobe's public release tag of that version rather than as a composer project (the public package mirror serves no 2.3 release before 2.3.7-p2); the code under test is the release's own.

| Mage-OS | Based on Magento Open Source | PHP | Database | Result |
| --- | --- | --- | --- | --- |
| 4.0.0 | 2.4.9 | 8.4 | MySQL 8.4 | Pass: the same checks; harmless, fix already in core |
| 1.0.6 | 2.4.7-p4 | 8.3 | MySQL 8.0 | Pass: the same checks; harmless, fix already in core |

Whether another Mage-OS release already carries the fix follows the Magento Open Source release it is based on; the module installs unchanged on any of them.

## Install

```
composer require bigcartenergy/module-cosmicsting-patch
bin/magento module:enable BigCartEnergy_CosmicStingPatch
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:clean
```

`setup:di:compile` is needed in production mode and does no harm in developer mode. The module creates no table, setting, menu item, cron job or storefront output. It is a module, not a `.patch` file, so nothing is applied to the Magento source and no patching tool is involved. A store that cannot run composer can copy this package to `app/code/BigCartEnergy/CosmicStingPatch` and run the same `bin/magento` commands.

The module declares no dependency on any other Big Cart Energy package.

## Verify

```
bin/magento module:status BigCartEnergy_CosmicStingPatch
```

prints "Module is enabled". Each refusal writes one line to `var/log/bigcartenergy_cve-2024-34102.log` with the UTC time, the client IP address, the request method and path, and the refused type:

```
bigcartenergy_cosmicsting.WARNING: time=2026-01-31T09:15:02Z ip=203.0.113.5 method=POST path=/rest/V1/example refused type=Vendor\Module\Model\Node class=Vendor\Module\Model\Node
```

Magento's log handler puts its own timestamp in front of the line. The request body is never logged. A store that never receives such a request never gets the file; that is the expected state.

## Remove

```
bin/magento module:disable BigCartEnergy_CosmicStingPatch
composer remove bigcartenergy/module-cosmicsting-patch
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:clean
```

Nothing is left behind except the log file, which you can delete.

## Logging and IP addresses

Logging is on by default. IP addresses are personal data in the EU, so if your store has no basis to keep them, turn the log off with this override in `app/etc/di.xml` (create the file with a `<config>` root if it does not exist) and run `bin/magento setup:di:compile` and `bin/magento cache:clean`:

```xml
<type name="BigCartEnergy\CosmicStingPatch\Plugin\Webapi\ServiceInputProcessorPlugin">
    <arguments>
        <argument name="enableLog" xsi:type="boolean">false</argument>
    </arguments>
</type>
```

The guard works the same with the log off.

## Not a substitute for upgrading

This module closes one hole in a release that Adobe no longer patches; it does not fix the others, and it never will. The lasting fix is a supported release of Magento Open Source. Install the module today, plan the upgrade, and if you want help with either: stuck on an old version? We'll patch you now and upgrade you when you're ready. [bigcartenergy.com](https://bigcartenergy.com)

## After any suspected exposure

If your store ran an exposed release while the vulnerability was public, installing a fix is not the end of it: Adobe's guidance for this vulnerability is to rotate the store's encryption keys as well, and to review the admin users, integrations and API tokens. This module does none of that for you.

## Licence

Open Software License 3.0 (OSL-3.0), the same licence as Magento Open Source. See [LICENSE.txt](https://github.com/bigcartenergy/module-cosmicsting-patch/blob/main/LICENSE.txt). The validation rule mirrors the fix in Magento Open Source (Copyright Adobe, OSL-3.0), re-implemented as a plugin; see NOTICE.

Repository: https://github.com/bigcartenergy/module-cosmicsting-patch · Issues: https://github.com/bigcartenergy/module-cosmicsting-patch/issues

Use at your own risk. Test on staging before production.

Copyright (c) Big Cart Energy. https://bigcartenergy.com
