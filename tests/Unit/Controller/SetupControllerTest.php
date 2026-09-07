<?php

namespace Unit\Controller;

use OCA\Humaniq\Controller\SetupController;
use OCA\Humaniq\Service\DemoDataService;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * ADR-042 / ADR-111 setup contract.
 *
 * The assertions here are about what the wizard can OBSERVE. A step the status
 * document never mentions resolves to `done: false` forever, and an optional
 * step that can never be marked done keeps the wizard open over every page —
 * so "the step is reported" and "a decision closes it" are the contract, not
 * incidental detail.
 */
class SetupControllerTest extends TestCase {
	private IAppConfig $appConfig;
	private LoggerInterface $logger;
	private DemoDataService $demoData;
	private SetupController $controller;

	protected function setUp(): void {
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->demoData = $this->createMock(DemoDataService::class);

		$this->controller = new SetupController(
			$this->createMock(IRequest::class),
			$this->appConfig,
			$this->logger,
			$this->demoData
		);
	}

	public function testStatusReportsTheDemoDataStep(): void {
		$this->appConfig->method('getValueString')->willReturn('');

		$data = $this->controller->status()->getData();

		// Absence is the defect this guards: a step the wizard is never told
		// about cannot be offered and cannot be completed.
		$this->assertArrayHasKey('demo-data', $data['steps']);
		$this->assertFalse($data['steps']['demo-data']['done']);
		// This app declares no REQUIRED step, so setup must never gate the app.
		$this->assertTrue($data['completed']);
		$this->assertSame(1, $data['version']);
	}

	public function testStatusReportsTheStepDoneOnceDecided(): void {
		$this->appConfig->method('getValueString')->willReturn('skipped');

		$data = $this->controller->status()->getData();

		$this->assertTrue($data['steps']['demo-data']['done']);
	}

	public function testSkippingIsAnAnswerAndIsRecorded(): void {
		// Declining must be persisted, otherwise the wizard re-offers the import
		// on every visit and "no thanks" is impossible to express.
		$this->appConfig->expects($this->once())
			->method('setValueString')
			->with('humaniq', 'demo_data_decided', 'skipped');

		$response = $this->controller->runAction('skip-demo-data');

		$this->assertTrue($response->getData()['success']);
	}

	/**
	 * THE DEFECT THIS WHOLE CHANGE EXISTS FOR. The manifest declared no setup
	 * block at all, so `CnAppRoot` had nothing to render and none of the 31
	 * shipped register descriptors could ever be offered. The backend was here
	 * the whole time; the wizard just never opened.
	 *
	 * The choice step declares `optionsSource: datasets` and no options, so a
	 * status document without `datasets` renders cards with nothing on them.
	 */
	public function testStatusPublishesTheCardsTheChoiceStepRendersFrom(): void {
		$this->appConfig->method('getValueString')->willReturn('');
		$this->demoData->method('listChoices')->willReturn([
			['id' => 'none', 'label' => 'None, I will set this up myself', 'objectCount' => 0],
			['id' => 'humaniq-demo', 'label' => 'Example data', 'objectCount' => 42],
		]);

		$data = $this->controller->status()->getData();

		$this->assertArrayHasKey('datasets', $data);
		$this->assertSame(['none', 'humaniq-demo'], array_column($data['datasets'], 'id'));
		// Declining has to be offered, or the step cannot be answered with a no
		// and the wizard reopens over every page forever.
		$this->assertContains('none', array_column($data['datasets'], 'id'));
	}

	/**
	 * Every step the manifest declares must appear, or it is `done: false`
	 * forever and the wizard never closes.
	 */
	public function testStatusReportsEveryStepTheManifestDeclares(): void {
		$this->appConfig->method('getValueString')->willReturn('');
		$this->demoData->method('listChoices')->willReturn([]);

		$steps = $this->controller->status()->getData()['steps'];

		foreach (['welcome', 'demo-data', 'load-demo-data', 'done'] as $id) {
			$this->assertArrayHasKey($id, $steps, $id . ' is declared in the manifest and must be reported');
		}
	}

	/**
	 * "None" is an ANSWER, so the load step has nothing left to do and must
	 * close. If it stayed outstanding the wizard would reopen forever on an
	 * instance that declined.
	 */
	public function testChoosingNoneClosesTheLoadStepWithoutImporting(): void {
		$this->appConfig->method('getValueString')
			->willReturnCallback(static fn (string $app, string $key): string => ($key === 'demo_dataset' ? 'none' : ''));
		$this->demoData->method('listChoices')->willReturn([]);
		$this->demoData->expects($this->never())->method('install');

		$steps = $this->controller->status()->getData()['steps'];

		$this->assertTrue($steps['demo-data']['done']);
		$this->assertTrue($steps['load-demo-data']['done']);
	}

	/**
	 * The load action honours the recorded answer rather than assuming one.
	 */
	public function testLoadingAfterDecliningImportsNothingAndSaysSo(): void {
		$this->appConfig->method('getValueString')
			->willReturnCallback(static fn (string $app, string $key): string => ($key === 'demo_dataset' ? 'none' : ''));
		$this->demoData->expects($this->never())->method('install');

		$res = $this->controller->runAction('load-demo-data');

		$this->assertTrue($res->getData()['success']);
	}

	/**
	 * An unanswered choice is refused rather than guessed at. Importing data
	 * nobody asked for is worse than an error that names the missing step.
	 */
	public function testLoadingWithNoChoiceRecordedIsRefused(): void {
		$this->appConfig->method('getValueString')->willReturn('');
		$this->demoData->expects($this->never())->method('install');

		$res = $this->controller->runAction('load-demo-data');

		$this->assertFalse($res->getData()['success']);
		$this->assertSame(400, $res->getStatus());
	}

	public function testUnknownActionIs404(): void {
		$response = $this->controller->runAction('not-an-action');

		$this->assertSame(404, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
	}

	public function testInstallReportsHowMuchLanded(): void {
		$this->demoData->method('install')
			->willReturn(['objects' => 30, 'registers' => 1, 'schemas' => 4]);

		$this->appConfig->expects($this->once())
			->method('setValueString')
			->with('humaniq', 'demo_data_decided', 'installed');

		$data = $this->controller->runAction('install-demo-data')->getData();

		$this->assertTrue($data['success']);
		// A success message that names no count cannot be told apart from an
		// import that wrote nothing — the defect this programme already shipped.
		$this->assertStringContainsString('30', $data['message']);
	}

	public function testAFailedInstallIsReportedAndLeavesTheStepUNDECIDED(): void {
		$this->demoData->method('install')
			->willThrowException(new RuntimeException('OpenRegister is not installed.'));

		// 🔴 THE POINT OF THIS TEST. Recording the decision here would close the
		// step for an operator who asked for demo data and received none: the
		// wizard would never offer it again, and nothing would have been
		// imported.
		$this->appConfig->expects($this->never())->method('setValueString');
		$this->logger->expects($this->once())->method('error');

		$response = $this->controller->runAction('install-demo-data');

		$this->assertSame(500, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
		$this->assertStringContainsString('OpenRegister is not installed.', $response->getData()['message']);
	}
}
