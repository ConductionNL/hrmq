<?php

/**
 * Register-table double for the pre-contract RegisterSlugLookup fallback.
 *
 * Deliberately NOT a subclass of the name-only
 * `OCA\OpenRegister\Db\RegisterMapper` stub: the lookup reaches the mapper
 * duck-typed, with `method_exists()`, so a double that only carries the one
 * method is the honest shape of what that code depends on.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Support;

/**
 * Answers with whichever candidate slugs the test says have a register row.
 */
final class FakeRegisterMapper {

	/**
	 * The slugs this double was asked about, as given.
	 *
	 * @var list<string>
	 */
	public array $asked = [];

	/**
	 * Constructor.
	 *
	 * @param list<string> $present The slugs this pretend instance carries.
	 *                              Empty means the register is absent.
	 */
	public function __construct(
		private readonly array $present = [],
	) {

	}//end __construct()

	/**
	 * Resolve a bounded set of register slugs to their primary-key ids.
	 *
	 * Mirrors the real mapper: keys are LOWER-CASED slugs, every requested slug
	 * is present as a key, and a slug with no row maps to an empty list.
	 *
	 * @param array<int, string> $slugs Register slugs to resolve.
	 *
	 * @return array<string, array<int, string>> Lower-cased slug => matching ids.
	 */
	public function findIdsBySlugs(array $slugs): array {
		$this->asked = array_values($slugs);

		$map = [];
		foreach ($slugs as $index => $slug) {
			$key = strtolower($slug);
			$map[$key] = [];
			if (in_array($slug, $this->present, true) === true) {
				$map[$key] = [(string)(100 + $index)];
			}
		}

		return $map;
	}//end findIdsBySlugs()

}//end class
