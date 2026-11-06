# CosmicSting patch for Magento Open Source and Mage-OS (CVE-2024-34102)

`bigcartenergy/module-cosmicsting-patch` is a free security patch for Magento Open Source and Mage-OS by Big Cart Energy. It closes CVE-2024-34102 ("CosmicSting") on the Magento releases that never received Adobe's fix, as a composer module: one plugin on the Web API input processor that applies the same rule as the official fix and returns the official error message. No core file is modified, nothing is added to the database or the admin, and `composer remove` takes it out without a trace.

## What it fixes

CVE-2024-34102 (CVSS 3.1 score 9.8, critical; CWE-611, XML external entity) lives in `Magento\Framework\Webapi\ServiceInputProcessor`, the class that turns a REST or SOAP request body into the objects a service method expects: it accepted the PHP XML classes as types to build from request data. Adobe's fix, shipped in June 2024, makes the processor refuse `SimpleXMLElement` and `DOMElement` subclasses with the error "Invalid data type". This module applies the same refusal from a `before` plugin on `convertValue()`, the processor's one public entry point, which the processor also passes through for every nested value, and it refuses the two classes themselves as well as their subclasses. One value the processor builds without that entry point is an object-typed custom attribute; for those the module resolves the attribute's type the way the processor does (the entity type of the owning object, then Magento's custom attribute type locator) and refuses it the same way when it is an XML class. Simple types and `mixed` are never checked; every other request is untouched.

## Who needs it

We read Adobe's fix in the Magento Open Source code of every release below, so the table says what the code says.

| Your Magento Open Source release | Official fix | This module |
| --- | --- | --- |
| 2.3.0 to 2.3.7-p4 | None | Yes |
| 2.4.0 to 2.4.3-p3 | None | Yes |
| 2.4.4 to 2.4.4-p8, 2.4.5 to 2.4.5-p7, 2.4.6 to 2.4.6-p5, 2.4.7 | Adobe's isolated patch VULN-27015, or the patch release of your line below | Yes, if you have applied neither, as the stopgap until you do: both of Adobe's fixes also contain the July 2024 hotfix described under the table, which this module does not |
| 2.4.4-p9 and later, 2.4.5-p8 and later, 2.4.6-p6 and later, 2.4.7-p1 and later, 2.4.8, 2.4.9 | Included | Not needed. Installed anyway, it is redundant and harmless: the processor refuses the same types a moment later |

Mage-OS ships the same Web API code as the Magento Open Source release it is based on, so that release's row applies to it; see "Tested versions" for what this version of the module was actually run on.

Adobe's bulletin for this vulnerability is APSB24-40. Adobe's knowledge base offers the isolated patch for the 2.4.4 to 2.4.7 lines, and recommends rotating the store's encryption keys as well; see "After any suspected exposure" below.

Adobe followed the June 2024 fix with a hotfix on July 17, 2024 (AC-12485), which its 2.4.4 patch notes describe as resolving "a JSON web token (JWT) validation issue related to CVE-2024-34102". The isolated patch contains that hotfix, and so do the patch releases that followed it (2.4.4-p10 is the first of its line to list it). This module does not contain it: it closes the input-processor hole only. On 2.4.4 to 2.4.7, Adobe's isolated patch or the patch release of your line is the complete fix and this module is the stopgap until you apply one. Magento's JSON web tokens for the Web API arrived in 2.4.4 (the `magento/module-jwt-user-token` package is first required by 2.4.4), so on 2.3.0 to 2.4.3 that hotfix has no counterpart and this module is the whole of what we can offer for the vulnerability there.

## Tested versions

A version that is not in this table has not been tested. Use at your own risk and test on staging first.

| Magento Open Source | PHP | Database | Result |
| --- | --- | --- | --- |
| 2.4.9 | 8.5 | MySQL 8.4 | Pass: install and `setup:di:compile`; REST guest checkout to a placed order, customer account create, product search with filters and sort orders, admin product save with custom and extension attributes and with Magento's own object-typed custom attribute; one SOAP call with search criteria; the guard refuses an XML-typed parameter (single and list) and logs it; an object-typed custom attribute of a non-XML type passes untouched (the refusal of an XML-typed custom attribute is covered by the unit suite); the same calls behave the same with and without the module (Adobe's fix is already in this release); removal with a REST pass afterwards; unit tests in place. Alone and together with the SessionReaper patch |
| 2.4.8 | 8.3 | MySQL 8.4 | Pass: the same checks (Adobe's fix is already in this release) |
| 2.4.7 | 8.2 | MySQL 8.0 | Pass: the same checks; on this release the module is the only guard |
| 2.4.6 | 8.1 | MySQL 8.0 | Pass: the same checks; on this release the module is the only guard |
| 2.4.3-p3 | 7.4 | MySQL 8.0 | Pass: the same checks; on this release the module is the only guard |
| 2.3.7-p4 | 7.4 | MySQL 5.7 | Pass: the same checks; on this release the module is the only guard |
| 2.3.5-p2 ¹ | 7.3 | MySQL 5.7 | Pass: the same checks; on this release the module is the only guard |
| 2.3.0 ¹ | 7.2 | MySQL 5.7 | Pass: the same checks; on this release the module is the only guard. 2.3.0's processor converts a service method's own parameters and setter values, where the module refuses the XML classes, but copies nested constructor data without converting it, so that data never reaches an XML class on this release with or without the module |

¹ Installed from Adobe's public release tag of that version rather than as a composer project (the public package mirror serves no 2.3 release before 2.3.7-p2); the code under test is the release's own.

Mage-OS: not tested with this version of the module. Whether a Mage-OS release already carries Adobe's fix follows the Magento Open Source release it is based on.

## Install

```
composer require bigcartenergy/module-cosmicsting-patch
bin/magento module:enable BigCartEnergy_CosmicStingPatch
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:clean
```

`setup:di:compile` is needed in production mode and does no harm in developer mode. In production mode `setup:upgrade` also empties the deployed static files unless it is run with `--keep-generated`, so run `bin/magento setup:static-content:deploy` after the compile, as after any module install, or pass `--keep-generated` (the module ships no static content) and let `setup:di:compile` rebuild the generated code. The module creates no table, setting, menu item, cron job or storefront output. It is a module, not a `.patch` file, so nothing is applied to the Magento source and no patching tool is involved. A store that cannot run composer can download the release archive from the repository, which unpacks into one folder named after the repository and the release, copy that folder's contents (`registration.php` included) to `app/code/BigCartEnergy/CosmicStingPatch` and run the same `bin/magento` commands.

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

Nothing is left behind except the log file, which you can delete. The production-mode note under Install applies to this `setup:upgrade` as well.

## Logging and IP addresses

Logging is on by default. IP addresses are personal data in the EU, so if your store has no basis to keep them, turn the log off with this override inside the `<config>` root of the `etc/di.xml` of a module of your own, then run `bin/magento setup:di:compile` and `bin/magento cache:clean`. Do not put it in Magento's `app/etc/di.xml`: that file belongs to the `magento/magento2-base` package and is written over when that package updates, which would switch the log back on without notice.

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

If your store ran an exposed release while the vulnerability was public, installing a fix is not the end of it: Adobe's guidance for this vulnerability is to rotate the store's encryption keys as well, and to review the admin users, integrations and API tokens. On 2.4.4 to 2.4.7, apply Adobe's isolated patch or the patch release of your line too, for the July 2024 hotfix this module does not contain. This module does none of that for you.

## Licence

Open Software License 3.0 (OSL-3.0), the same licence as Magento Open Source. See [LICENSE.txt](https://github.com/bigcartenergy/module-cosmicsting-patch/blob/main/LICENSE.txt). The validation rule mirrors the fix in Magento Open Source (Copyright Adobe, OSL-3.0), re-implemented as a plugin; see NOTICE.

Repository: https://github.com/bigcartenergy/module-cosmicsting-patch · Issues: https://github.com/bigcartenergy/module-cosmicsting-patch/issues

Use at your own risk. Test on staging before production.

Copyright (c) Big Cart Energy. https://bigcartenergy.com
