<?php
/**
 * Copyright (c) Big Cart Energy. All rights reserved.
 * SPDX-License-Identifier: OSL-3.0
 */
declare(strict_types=1);

namespace BigCartEnergy\CosmicStingPatch\Test\Unit\Fixture;

/**
 * A subclass of SimpleXMLElement with nothing in it: the guard must refuse it like the class itself.
 */
class XmlNode extends \SimpleXMLElement
{
}
