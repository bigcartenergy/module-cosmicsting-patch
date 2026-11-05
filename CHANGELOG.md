# Changelog

All notable changes to `bigcartenergy/module-cosmicsting-patch` by Big Cart Energy.

## 1.0.0 - Unreleased

### Added

- A `before` plugin on `Magento\Framework\Webapi\ServiceInputProcessor::convertValue()` that refuses a conversion to `SimpleXMLElement` or `DOMElement`, the class itself or any subclass, directly or as an array item type, with the official message "Invalid data type". Simple types and `mixed` are never checked; every other request is untouched.
- One log line per refusal in `var/log/bigcartenergy_cve-2024-34102.log`: UTC time, client IP address, request method and path, and the refused type. Never the request body. The `enableLog` argument of the plugin in `di.xml` turns it off.
- Unit tests with harmless fixture classes, runnable standalone or through Magento's `dev/tests/unit`.

### Tested

- Magento Open Source 2.4.9 (PHP 8.5, MySQL 8.4), 2.4.8 (PHP 8.3, MySQL 8.4) and 2.3.7-p4 (PHP 7.4, MySQL 5.7): install and compile, the REST checkout, customer, search and product-save flows, one SOAP call, the refusal of an XML-typed parameter with its log line, identical behaviour with and without the module on the two releases that already carry Adobe's fix, removal, and the unit suite on PHPUnit 9, 10 and 12. Alone and together with `bigcartenergy/module-sessionreaper-patch`.
