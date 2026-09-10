<?php

/**
 * Humaniq RegisterSlugPinTest
 *
 * No code under lib/ pins a superseded register slug.
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

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The case that has never once been caught.
 *
 * A consumer pinned to a superseded register slug on a MIGRATED instance does
 * not raise. `openregister_registers` has no row with that slug, so the read
 * matches nothing, which is byte for byte what a healthy empty register
 * returns. No exception, no 404, no log line. This defect has no behaviour to
 * watch: it is a feature that quietly stops happening.
 *
 * So the guard is static, and it is repo-wide rather than diff-scoped. Diff
 * scope is right for debt a PR could reasonably be asked to carry; it is wrong
 * here, because every one of these references was written BEFORE the rename and
 * will therefore never appear in a diff. A diff-scoped version of this test
 * passes on a repository full of the defect.
 *
 * ## Why humaniq has one at all, and why it needs a shape the others lack
 *
 * A fleet sweep of all 21 apps on 2026-09-10 found ZERO superseded slugs under
 * the five shapes openregister's guard knows, and six under a sixth — every one
 * of them in this repository, and none of them visible to any of the five. The
 * six were the same private helper copy-pasted across six classes:
 *
 *     private function register(): string {
 *         $register = $this->appConfig->getValueString(APP_ID, 'register', 'humaniq');
 *         return $register === '' ? SUPERSEDED : $register;
 *     }
 *
 * A TERNARY FALLBACK in register position. openregister#3579 named three of the
 * six; the other three were invisible for the reason that issue is about — the
 * list and the sweep were built from the same starting point. Only 7 of the 21
 * repos shipped a guard like this at the time, and humaniq was not one of them,
 * which is why the width of the patterns was the smaller half of the problem.
 *
 * ## What it still does NOT catch
 *
 * It reads lines, not data flow. A slug arriving from app config, from a
 * manifest, or through more than one assignment is invisible to it, as is a
 * `match` arm built at run time. That is why
 * {@see \OCA\Humaniq\Tests\Unit\Support\RegisterSlugLookupTest} exists beside
 * it: this guard stops the literal being TYPED, and that one stops the resolved
 * slug being IGNORED.
 *
 * It also does not read the CANONICAL slug as a defect, and that is a real
 * hole rather than an oversight. `'humaniq'` written as a hard default is the
 * same defect pointing the other way — on an UNMIGRATED instance it reads zero
 * rows just as surely — but it is indistinguishable from a correct literal on a
 * line-by-line read. Only the resolver can tell those apart.
 */
class RegisterSlugPinTest extends TestCase {

	/**
	 * Superseded register slug => the canonical slug replacing it.
	 *
	 * Only the register this app reads, and it agrees with this app's own
	 * {@see \OCA\Humaniq\Repair\MigrateRegisterSlug::SLUG_MAP}. openregister
	 * owns the full fleet map in `lib/Support/RegisterSlugAliases.php`, which is
	 * not published to consumers, and copying all ten here would put a second
	 * copy of that truth in a repository that does not own it.
	 *
	 * @var array<string, string>
	 */
	private const SUPERSEDED = ['hrmq' => 'humaniq'];

	/**
	 * Files allowed to name a superseded slug, and why.
	 *
	 * Each must be a file that exists IN ORDER TO name the old slug, not a
	 * deferral. `MigrateRegisterSlug` IS the rename, and the app-id migrations
	 * name the old APP ID, whose source of truth is `IAppManager` rather than
	 * `openregister_registers`. Those are a different question: the app id and
	 * the register slug are moved by separate repair steps and either can run
	 * first, so one never predicts the other.
	 *
	 * @var array<string, string>
	 */
	private const ALLOWED = [
		'lib/Repair/MigrateRegisterSlug.php'          => 'the rename itself; it must name what it renames from',
		'lib/Repair/MigrateRegisterSlugDecisions.php' => 'the pure predicates behind the rename',
		'lib/Repair/MigrateSchemaSlug.php'            => 'schema slugs, moved by a sibling step',
		'lib/Repair/MigrateSchemaApplicationId.php'   => 'app ids, not register slugs',
		'lib/Repair/MigrateAppConfigKeys.php'         => 'app ids, not register slugs',
		'lib/Repair/MigrateUserPreferences.php'       => 'app ids, not register slugs',
		'lib/Support/FleetAppId.php'                  => 'the app-id alias map; a different question with a different source of truth',
	];

	/**
	 * Source patterns that put a string literal in REGISTER position.
	 *
	 * Deliberately narrow. A slug is only a defect where it identifies a
	 * register; the same word as an app id, a provider name or a log message is
	 * not this defect, and a guard that flagged those would be turned off.
	 *
	 * The first five are openregister's own set. The three that follow were
	 * measured across the fleet on 2026-09-10 before being adopted:
	 *
	 *   - POSITIONAL: `find`/`findAll`/`saveObject` with the register as the
	 *     fourth argument and no label anywhere on the line. Zero true findings
	 *     fleet-wide and zero captures here; carried because it is the shape
	 *     that hid decidiq's authorization pin, not because it earns its keep on
	 *     volume. It is anchored to the three ObjectService methods that take a
	 *     register in that position rather than to any call with a string in its
	 *     fourth argument, which would flag most of the codebase.
	 *   - NULL-COALESCING: a `??` default in register position. Zero findings
	 *     fleet-wide; this is the shape openregister's resolver docblock names
	 *     as reinstating the defect.
	 *   - TERNARY: a `? 'literal' :` fallback in register position. SIX findings,
	 *     all here, and the reason this file exists.
	 *
	 * @var list<string>
	 */
	private const REGISTER_POSITION = [
		'/setRegister\(\s*(?:register:\s*)?\'([a-zA-Z0-9_-]+)\'/',
		'/\bregister:\s*\'([a-zA-Z0-9_-]+)\'/',
		'/\'register\'\s*=>\s*\'([a-zA-Z0-9_-]+)\'/',
		'/\bconst\s+[A-Z0-9_]*REGISTER[A-Z0-9_]*\s*=\s*\'([a-zA-Z0-9_-]+)\'/',
		'/\$[a-zA-Z0-9_]*(?:[Rr]egister|[Ss]lug)[a-zA-Z0-9_]*\s*=\s*\'([a-zA-Z0-9_-]+)\'/',
		'/->(?:find|findAll|saveObject)\(\s*[^,()]+,\s*[^,()\[\]]*(?:\[[^\]]*\])?[^,()]*,\s*[^,()]+,\s*\'([a-zA-Z0-9_-]+)\'/',
		'/\bregister:\s*\(?[^,()]*\?\?\s*\'([a-zA-Z0-9_-]+)\'/',
		'/\'register\'\s*=>\s*\(?[^,()]*\?\?\s*\'([a-zA-Z0-9_-]+)\'/',
		'/\$[a-zA-Z0-9_]*(?:[Rr]egister|[Ss]lug)[a-zA-Z0-9_]*\s*=\s*[^;]*\?\?\s*\'([a-zA-Z0-9_-]+)\'/',
		'/\?\s*\'([a-zA-Z0-9_-]+)\'\s*:\s*\$[a-zA-Z0-9_]*(?:[Rr]egister|[Ss]lug)/',
		'/\$[a-zA-Z0-9_]*(?:[Rr]egister|[Ss]lug)[a-zA-Z0-9_]*\s*=\s*[^;]*\?\s*\'([a-zA-Z0-9_-]+)\'\s*:/',
	];

	/**
	 * No file under lib/ names a superseded register slug in register position.
	 *
	 * @return void
	 */
	public function testNoSourceFilePinsASupersededRegisterSlug(): void {
		$findings = [];
		foreach ($this->sourceFiles() as $relative => $absolute) {
			if (isset(self::ALLOWED[$relative]) === true) {
				continue;
			}

			// NOT FILE_SKIP_EMPTY_LINES. Skipping blank lines renumbers every
			// line after the first one, so `$index + 1` stops being the line
			// number and becomes the count of non-blank lines. Measured on
			// openregister's reconciler: a pin on line 590 was reported as line
			// 528, because 62 blank lines preceded it. A guard that names the
			// wrong line is a guard whose next reader concludes it is broken.
			$lines = file($absolute, FILE_IGNORE_NEW_LINES);
			if ($lines === false) {
				continue;
			}

			foreach ($lines as $index => $line) {
				if ($this->isCommentLine($line) === true) {
					continue;
				}

				foreach (self::REGISTER_POSITION as $pattern) {
					if (preg_match($pattern, $line, $matches) !== 1) {
						continue;
					}

					$slug = strtolower($matches[1]);
					if (isset(self::SUPERSEDED[$slug]) === false) {
						continue;
					}

					// Keyed by file:line, not appended. Two of the eleven
					// patterns describe the same ternary from opposite ends and
					// both hit the real thing, so an appending guard reported
					// each of the six pins twice — a doubled count is a reader
					// wondering which half is wrong.
					$findings[$relative . ':' . ($index + 1)] = sprintf(
						'%s:%d pins the superseded register slug \'%s\'. Resolve \'%s\' through '
						. 'RegisterSlugLookup (OpenRegister\'s RegisterSlugResolverInterface) instead, and '
						. 'branch on the null, because reading with a slug this instance does not carry '
						. 'returns zero rows, not an error.',
						$relative,
						($index + 1),
						$slug,
						self::SUPERSEDED[$slug]
					);
				}
			}
		}

		$this->assertSame([], array_values($findings), "Superseded register slugs are pinned:\n" . implode("\n", $findings));
	}//end testNoSourceFilePinsASupersededRegisterSlug()

	/**
	 * The guard actually looks at something.
	 *
	 * A file walker that silently finds no files is the classic hollow green:
	 * the assertion above would pass on an empty list forever. This pins the
	 * walker to a floor well below the real count, so a broken path fails here
	 * rather than passing there.
	 *
	 * @return void
	 */
	public function testTheGuardScansTheSourceTree(): void {
		$files = $this->sourceFiles();

		$this->assertGreaterThan(200, count($files), 'The walker must see lib/, or the guard above cannot fail.');
		$this->assertArrayHasKey(
			'lib/Service/RosterCheckService.php',
			$files,
			'One of the six files this guard was written for; the walker must reach it.'
		);
	}//end testTheGuardScansTheSourceTree()

	/**
	 * The patterns match a pinned slug when one is present.
	 *
	 * Watched failing is not enough on its own once the tree is clean: from then
	 * on the guard passes whether or not its regexes still work. This feeds each
	 * register-position form a known-bad line and requires a match, so a regex
	 * that stops matching reddens immediately instead of going quiet.
	 *
	 * @return void
	 */
	public function testEachRegisterPositionPatternStillMatches(): void {
		$samples = [
			'/setRegister\(\s*(?:register:\s*)?\'([a-zA-Z0-9_-]+)\'/' => "\$objectService->setRegister('hrmq');",
			'/\bregister:\s*\'([a-zA-Z0-9_-]+)\'/'                    => "\$svc->find(id: \$id, register: 'hrmq', schema: 'PayrollRun');",
			'/\'register\'\s*=>\s*\'([a-zA-Z0-9_-]+)\'/'              => "'filters' => ['register' => 'hrmq', 'schema' => 'Employee'],",
			'/\bconst\s+[A-Z0-9_]*REGISTER[A-Z0-9_]*\s*=\s*\'([a-zA-Z0-9_-]+)\'/' => "\tprivate const PAYROLL_REGISTER = 'hrmq';",
			'/\$[a-zA-Z0-9_]*(?:[Rr]egister|[Ss]lug)[a-zA-Z0-9_]*\s*=\s*\'([a-zA-Z0-9_-]+)\'/' => "\t\t\$registerSlug = 'hrmq';",
			'/->(?:find|findAll|saveObject)\(\s*[^,()]+,\s*[^,()\[\]]*(?:\[[^\]]*\])?[^,()]*,\s*[^,()]+,\s*\'([a-zA-Z0-9_-]+)\'/' => "\t\t\$e = \$this->objectService->find(\$runId, [], false, 'hrmq', 'PayrollRun');",
			'/\bregister:\s*\(?[^,()]*\?\?\s*\'([a-zA-Z0-9_-]+)\'/' => "\t\t\$saved = \$or->saveObject(object: \$o, register: (\$data['register'] ?? 'hrmq'));",
			'/\'register\'\s*=>\s*\(?[^,()]*\?\?\s*\'([a-zA-Z0-9_-]+)\'/' => "\t\t\$f = ['register' => (\$data['register'] ?? 'hrmq')];",
			'/\$[a-zA-Z0-9_]*(?:[Rr]egister|[Ss]lug)[a-zA-Z0-9_]*\s*=\s*[^;]*\?\?\s*\'([a-zA-Z0-9_-]+)\'/' => "\t\t\$register = \$data['register'] ?? 'hrmq';",
			'/\?\s*\'([a-zA-Z0-9_-]+)\'\s*:\s*\$[a-zA-Z0-9_]*(?:[Rr]egister|[Ss]lug)/' => "\t\treturn \$register === '' ? 'hrmq' : \$register;",
			'/\$[a-zA-Z0-9_]*(?:[Rr]egister|[Ss]lug)[a-zA-Z0-9_]*\s*=\s*[^;]*\?\s*\'([a-zA-Z0-9_-]+)\'\s*:/' => "\t\t\$registerSlug = \$configured === '' ? 'hrmq' : \$configured;",
		];

		foreach (self::REGISTER_POSITION as $pattern) {
			$this->assertArrayHasKey($pattern, $samples, 'Every register-position pattern needs a known-bad sample.');
			$this->assertSame(
				1,
				preg_match($pattern, $samples[$pattern], $matches),
				'Pattern must match its known-bad sample: ' . $pattern
			);
			$this->assertArrayHasKey(
				strtolower($matches[1]),
				self::SUPERSEDED,
				'The sample must capture a slug this guard calls superseded: ' . $pattern
			);
		}
	}//end testEachRegisterPositionPatternStillMatches()

	/**
	 * A comment quoting the defect is not the defect.
	 *
	 * The fix for the six pins documents, in each file's docblock, the exact
	 * line it replaced — which is the most useful thing that docblock can say
	 * and is also a verbatim copy of what the ternary pattern looks for. On the
	 * first run the guard reported all six, plus a seventh in the support class
	 * that explains the whole defect: seven findings, zero of them code. That is
	 * the failure mode openregister#3579 warns about in as many words — a guard
	 * that cries wolf gets an allow-list entry per finding, and an allow-list is
	 * where a real pin goes to be forgotten.
	 *
	 * So the walker skips lines that BEGIN as a comment. A trailing comment
	 * after real code still starts with the code, so it is still read; only a
	 * line whose first non-whitespace is a comment marker is skipped, which also
	 * means commented-out code is not scanned. That is correct: commented-out
	 * code does not read a register.
	 *
	 * @return void
	 */
	public function testACommentQuotingTheDefectIsNotAFinding(): void {
		$this->assertTrue($this->isCommentLine("\t * This used to end `return \$register === '' ? 'hrmq' : \$register;` under a"));
		$this->assertTrue($this->isCommentLine("\t// \$register = \$data['register'] ?? 'hrmq';"));
		$this->assertTrue($this->isCommentLine("\t/* register: 'hrmq' */"));
		$this->assertTrue($this->isCommentLine("\t/** register: 'hrmq' */"));

		$this->assertFalse($this->isCommentLine("\t\treturn \$register === '' ? 'hrmq' : \$register;"));
		$this->assertFalse($this->isCommentLine("\t\t\$registerSlug = 'hrmq'; // the old one"));
	}//end testACommentQuotingTheDefectIsNotAFinding()

	/**
	 * Whether a source line begins as a comment.
	 *
	 * @param string $line The raw source line.
	 *
	 * @return bool True when the first non-whitespace is a comment marker.
	 */
	private function isCommentLine(string $line): bool {
		$trimmed = ltrim($line);

		return (str_starts_with($trimmed, '*') === true
			|| str_starts_with($trimmed, '//') === true
			|| str_starts_with($trimmed, '/*') === true
			|| str_starts_with($trimmed, '#') === true);
	}//end isCommentLine()

	/**
	 * Every PHP file under lib/, keyed by repository-relative path.
	 *
	 * @return array<string, string> Relative path => absolute path.
	 */
	private function sourceFiles(): array {
		$root = dirname(__DIR__, 3);
		$lib = $root . '/lib';

		$files = [];
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($lib, RecursiveDirectoryIterator::SKIP_DOTS)
		);
		foreach ($iterator as $file) {
			if (($file instanceof SplFileInfo) === false || $file->isFile() === false) {
				continue;
			}

			if ($file->getExtension() !== 'php') {
				continue;
			}

			$path = $file->getPathname();
			$files[ltrim(str_replace($root, '', $path), '/')] = $path;
		}

		return $files;
	}//end sourceFiles()
}//end class
