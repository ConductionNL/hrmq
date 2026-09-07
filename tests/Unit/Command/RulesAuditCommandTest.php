<?php

/**
 * Unit tests for RulesAuditCommand's exit-code contract.
 *
 * `RuleEngine::hasMandatory()` exists to answer "must a guard block here" and
 * had no production caller at all: its only two call sites in the whole
 * repository were assertions in NlRetroChecksTest and NlRosterChecksTest. The
 * engine was advisory, its docblock claimed a `RuleComplianceGuard` that has
 * never existed, and the audit printed a wall of text that exited 0 whatever it
 * found. A mandatory breach and a clean run were indistinguishable to any
 * script.
 *
 * These tests pin the contract that makes the audit gateable: non-zero when a
 * mandatory violation is found, zero otherwise. They fail on the old command,
 * which returned a literal 0.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-005
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Command;

use OCA\Humaniq\Command\RulesAuditCommand;
use OCA\Humaniq\Service\RuleAuditService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Tests for RulesAuditCommand.
 *
 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-005
 */
class RulesAuditCommandTest extends TestCase {

	/**
	 * A report shaped like RuleAuditService::audit() returns, with the
	 * severity counts the exit code turns on.
	 *
	 * @param int $mandatory Mandatory-severity violation count.
	 * @param int $conditional Conditional-severity violation count.
	 * @param int $recommended Recommended-severity violation count.
	 *
	 * @return array<string, mixed> The report.
	 */
	private function report(int $mandatory, int $conditional = 0, int $recommended = 0): array {
		return [
			'catalogueVersion' => '2026-07',
			'corpusTotal' => 40,
			'machineCheckable' => 30,
			'enforceableRules' => 20,
			'coveragePct' => 66.7,
			'objectsChecked' => 10,
			'objectsCompliant' => 9,
			'objectsWithViolations' => 1,
			'violationsBySeverity' => [
				'mandatory' => $mandatory,
				'conditional' => $conditional,
				'recommended' => $recommended,
			],
			'types' => [],
			'topViolatedRules' => [],
		];
	}

	/**
	 * Run the command over a canned report.
	 *
	 * @param array<string, mixed> $report The report the service returns.
	 *
	 * @return array{0: int, 1: string} The exit code and the printed output.
	 */
	private function runAudit(array $report): array {
		$service = $this->createMock(RuleAuditService::class);
		$service->method('audit')->willReturn($report);

		$command = new RulesAuditCommand($service);
		$output = new BufferedOutput();
		$code = $command->run(new ArrayInput([]), $output);

		return [$code, $output->fetch()];
	}

	/**
	 * A clean run stays 0, exactly as before.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-005
	 */
	public function testACleanAuditExitsZero(): void {
		[$code] = $this->runAudit($this->report(mandatory: 0));
		$this->assertSame(0, $code, 'no mandatory violation must leave the exit code at 0');
	}

	/**
	 * One mandatory violation is enough to fail the gate.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-005
	 */
	public function testASingleMandatoryViolationExitsNonZero(): void {
		[$code, $out] = $this->runAudit($this->report(mandatory: 1));
		$this->assertSame(1, $code, 'a mandatory violation must fail the gate');
		$this->assertStringContainsString(
			'1 mandatory violation(s)',
			$out,
			'the run must say why it failed, not just fail'
		);
	}

	/**
	 * Advisory severities do not fail the gate.
	 *
	 * Failing on conditional or recommended would make the gate unusable, and
	 * an unusable gate gets switched off. Only mandatory blocks.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-005
	 */
	public function testConditionalAndRecommendedViolationsDoNotFailTheGate(): void {
		[$code] = $this->runAudit($this->report(mandatory: 0, conditional: 7, recommended: 12));
		$this->assertSame(
			0,
			$code,
			'only mandatory blocks: an advisory-only run must stay gateable'
		);
	}

	/**
	 * A missing severity key is treated as zero rather than fataling.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-005
	 */
	public function testAnAbsentSeverityKeyIsNotAViolation(): void {
		$report = $this->report(mandatory: 0);
		$report['violationsBySeverity'] = [];
		[$code] = $this->runAudit($report);
		$this->assertSame(0, $code, 'an absent count must read as zero, not crash the audit');
	}

	/**
	 * The report is still printed in full when the gate fails.
	 *
	 * A gate that fails without saying what it found is a gate people disable.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-005
	 */
	public function testTheReportIsStillPrintedWhenTheGateFails(): void {
		[, $out] = $this->runAudit($this->report(mandatory: 3));
		$this->assertStringContainsString('Humaniq rule-compliance audit', $out);
		$this->assertStringContainsString('objects checked', $out);
		$this->assertStringContainsString('3 mandatory', $out);
	}

}//end class
