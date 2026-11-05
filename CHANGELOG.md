# Changelog

All notable changes to `bigcartenergy/module-cosmicsting-patch` by Big Cart Energy.

## 1.0.0 - Unreleased

### Added

- A `before` plugin on `Magento\Framework\Webapi\ServiceInputProcessor::convertValue()` that refuses a conversion to `SimpleXMLElement` or `DOMElement`, the class itself or any subclass, directly or as an array item type, with the official message "Invalid data type". Simple types and `mixed` are never checked; every other request is untouched.
- One log line per refusal in `var/log/bigcartenergy_cve-2024-34102.log`: UTC time, client IP address, request method and path, and the refused type. Never the request body. The `enableLog` argument of the plugin in `di.xml` turns it off.
- Unit tests with harmless fixture classes, runnable standalone or through Magento's `dev/tests/unit`.

### Tested

- Filled in from the bench before the release (see the README's Tested versions table).
