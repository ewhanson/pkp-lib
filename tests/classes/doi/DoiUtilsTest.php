<?php

/**
 * @file tests/classes/dois/DoiUtilsTest.php
 *
 * Copyright (c) 2013-2022 Simon Fraser University
 * Copyright (c) 2000-2022 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class DoiUtilsTest
 * @ingroup tests_classes_doi
 *
 * @see Core
 *
 * @brief Tests for the \PKP\doi\Utils
 */

use PKP\doi\Utils as DoiUtils;

import('lib.pkp.tests.PKPTestCase');

class DoiUtilsTest extends PKPTestCase
{

    public function testEncodeDoi()
    {
        // Provides public access to protected method for testing
        $doiUtils = new class extends DoiUtils {
            public static function base32EncodeSuffix(int $number): string {
                return parent::base32EncodeSuffix($number);
            }
        };
        $number = 123;

        self::assertEquals('0000-3v20', $doiUtils::base32EncodeSuffix($number));
        self::assertEquals('0000-3v20', $doiUtils::base32EncodeSuffix((string) $number));
        self::assertMatchesRegularExpression(
            '/^[0-9abcdefghjkmnpqrstvwxyz]{4}-[0-9abcdefghjkmnpqrstvwxyz]{2}[0-9]{2}$/',
            DoiUtils::encodeSuffix()
        );
    }

    public function testDecodeDoi()
    {
        $validSuffix = DoiUtils::encodeSuffix();
        $decodedValidSuffix = DoiUtils::decodeSuffix($validSuffix);
        self::assertIsNumeric($decodedValidSuffix);

        $invalidSuffix = '0000-3v25';
        $decodedInvalidSuffix = DoiUtils::decodeSuffix($invalidSuffix);
        self::assertNull($decodedInvalidSuffix);
    }
}
