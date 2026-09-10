<?php

/**
 * FakeSlugResolver — a test double for OpenRegister's register-slug resolver.
 *
 * Implements the published `RegisterSlugResolverInterface` rather than being
 * a `createMock()` of it, so the compiler checks the shape: a contract that
 * changes signature breaks this file loudly instead of leaving a mock happily
 * answering a method the real interface no longer has.
 *
 * The interface itself comes from tests/stubs/OpenRegisterSlugResolverStub.php
 * in a standalone run, and from the real OpenRegister app in a full checkout.
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

use OCA\OpenRegister\Contract\RegisterSlugResolution;
use OCA\OpenRegister\Contract\RegisterSlugResolverInterface;

/**
 * Answers with whichever candidate slugs the test says exist on the instance.
 */
final class FakeSlugResolver implements RegisterSlugResolverInterface {

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
	 * Which slug this instance's copy of a register answers to.
	 *
	 * @param string $canonical The canonical (current) register slug.
	 * @param list<string> $candidates Explicit candidates, newest first.
	 *
	 * @return RegisterSlugResolution The resolution.
	 */
	public function resolve(string $canonical, array $candidates=[]): RegisterSlugResolution {
		$probe = ($candidates === [] ? [$canonical] : $candidates);
		$matched = array_values(array_intersect($probe, $this->present));

		$state = RegisterSlugResolution::RESOLVED;
		if ($matched === []) {
			$state = RegisterSlugResolution::ABSENT;
		} elseif (count($matched) > 1) {
			$state = RegisterSlugResolution::AMBIGUOUS;
		}

		return new RegisterSlugResolution(
			$canonical,
			($matched[0] ?? null),
			$state,
			$probe,
			$matched
		);
	}//end resolve()

	/**
	 * The slug to read with, or null when the register is not here.
	 *
	 * @param string $canonical The canonical (current) register slug.
	 * @param list<string> $candidates Explicit candidates, newest first.
	 *
	 * @return string|null The slug, or null.
	 */
	public function slugOrNull(string $canonical, array $candidates=[]): ?string {
		return $this->resolve($canonical, $candidates)->slug;
	}//end slugOrNull()

}//end class
