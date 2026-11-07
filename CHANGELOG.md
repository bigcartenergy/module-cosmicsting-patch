# Changelog

All notable changes to `bigcartenergy/module-cosmicsting-patch` by Big Cart Energy.

## 1.0.0 - 2026-11-07

### Added

- A `before` plugin on `Magento\Framework\Webapi\ServiceInputProcessor::convertValue()` that refuses a conversion to `SimpleXMLElement` or `DOMElement`, the class itself or any subclass, directly or as an array item type, with the official message "Invalid data type". Simple types and `mixed` are never checked; every other request is untouched.
- The same refusal for an object-typed custom attribute, which the processor builds without `convertValue()`: the custom attributes container is recognised exactly as the processor recognises it (every key whose `SimpleDataObjectConverter::snakeCaseToUpperCamelCase()` form is `CustomAttributes`, not a fixed pair of spellings), the attribute's type is resolved as the processor resolves it (the entity type of the owning object, then the custom attribute type locator) and refused when it is, or is an array of, an XML class. Simple and `mixed` attributes, unknown codes, scalar values, owners without an entity type and a locator failure are left to the processor.
- One log line per refusal in `var/log/bigcartenergy_cve-2024-34102.log`: UTC time, client IP address, request method and path, and the refused type. Never the request body. The `enableLog` argument of the plugin in `di.xml` turns it off.
- Unit tests with harmless fixture classes, runnable standalone or through Magento's `dev/tests/unit`.
- README: the log switch is a small module of the store's own whose `module.xml` declares a `<sequence>` on this module, so its `enableLog` override loads after this module's value and wins the configuration merge; the README gives the three files and the check that confirms the switch took.

### Tested

- Magento Open Source 2.4.9 (PHP 8.5, MySQL 8.4), 2.4.8 (PHP 8.3, MySQL 8.4), 2.4.7 (PHP 8.2, MySQL 8.0), 2.4.6 (PHP 8.1, MySQL 8.0), 2.4.3-p3 (PHP 7.4, MySQL 8.0), 2.3.7-p4 (PHP 7.4, MySQL 5.7), 2.3.5-p2 (PHP 7.3, MySQL 5.7) and 2.3.0 (PHP 7.2, MySQL 5.7): install and compile, the REST checkout, customer, search and product-save flows (Magento's own object-typed custom attribute included), one SOAP call, the refusal of an XML-typed parameter with its log line, an object-typed custom attribute of a non-XML type passing untouched, every legitimate flow identical with and without the module on every release, and on the releases that already carry Adobe's fix the refusal identical too (the module answers first with the same message and adds its log line), removal, and the unit suite on PHPUnit 6.5, 9, 10 and 12. Alone and together with `bigcartenergy/module-sessionreaper-patch`. The README's table gives the details per release.
