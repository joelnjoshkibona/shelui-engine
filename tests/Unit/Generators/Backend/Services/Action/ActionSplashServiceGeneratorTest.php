<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Backend\Services\Action;

use Blutrixx\GeneratorEngine\Generators\Backend\Services\Action\ActionSplashServiceGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * The splash service's own file/class name must derive from the same base
 * RoutesGenerator/ControllerGenerator resolve for the same action — see
 * plans/038: this generator used to ignore serviceName entirely.
 */
class ActionSplashServiceGeneratorTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-action-splash-service-test-' . uniqid();
        mkdir($this->tmpRoot, 0755, true);
        PathManager::setProjectRoot($this->tmpRoot);
    }

    protected function tearDown(): void
    {
        PathManager::resetProjectRoot();
        $this->removeDirectory($this->tmpRoot);

        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function baseConfig(array $action, string $actionKey): array
    {
        return [
            'module_name' => 'Widgets',
            'module_type' => 'Core',
            'table_name' => 'widgets',
            'id_type' => 'bigint',
            'columns' => [],
            'features' => [],
            'actions' => [$actionKey => $action],
        ];
    }

    private function modulePath(): string
    {
        return $this->tmpRoot . '/BACKEND/app/Project/Modules/Core/Widgets';
    }

    public function test_default_splash_with_no_service_name_uses_the_action_name(): void
    {
        $action = ['name' => 'ping', 'splash' => true, 'operations' => []];
        $generator = new ActionSplashServiceGenerator('Widgets', 'Core', $this->baseConfig($action, 'ping'), 'ping', $action);
        $generator->setForce(true);

        $this->assertTrue($generator->generate());

        $path = $this->modulePath() . '/Services/WidgetsPingSplashService.php';
        $this->assertFileExists($path);
        $this->assertStringContainsString('class WidgetsPingSplashService', file_get_contents($path));
    }

    public function test_service_name_override_is_used_for_the_file_and_class_name(): void
    {
        $action = ['name' => 'report', 'serviceName' => 'WidgetsYearReportService', 'splash' => true, 'operations' => []];
        $generator = new ActionSplashServiceGenerator('Widgets', 'Core', $this->baseConfig($action, 'report'), 'report', $action);
        $generator->setForce(true);

        $this->assertTrue($generator->generate());

        $wrongPath = $this->modulePath() . '/Services/WidgetsReportSplashService.php';
        $rightPath = $this->modulePath() . '/Services/WidgetsYearReportSplashService.php';

        $this->assertFileDoesNotExist($wrongPath);
        $this->assertFileExists($rightPath);
        $this->assertStringContainsString('class WidgetsYearReportSplashService', file_get_contents($rightPath));
    }

    public function test_action_route_stays_based_on_the_actions_own_name(): void
    {
        $action = ['name' => 'report', 'serviceName' => 'WidgetsYearReportService', 'splash' => true, 'operations' => []];
        $generator = new ActionSplashServiceGenerator('Widgets', 'Core', $this->baseConfig($action, 'report'), 'report', $action);
        $generator->setForce(true);
        $generator->generate();

        $content = file_get_contents($this->modulePath() . '/Services/WidgetsYearReportSplashService.php');

        $this->assertStringContainsString('/widgets/{uuid}/report/splash', $content);
    }

    public function test_action_label_falls_back_to_the_actions_own_name_not_the_service_name(): void
    {
        $action = ['name' => 'report', 'serviceName' => 'WidgetsYearReportService', 'splash' => true, 'operations' => []];
        $generator = new ActionSplashServiceGenerator('Widgets', 'Core', $this->baseConfig($action, 'report'), 'report', $action);
        $generator->setForce(true);
        $generator->generate();

        $content = file_get_contents($this->modulePath() . '/Services/WidgetsYearReportSplashService.php');

        $this->assertStringContainsString('Splash data for the "Report" action.', $content);
        $this->assertStringNotContainsString('Splash data for the "YearReport" action.', $content);
    }

    /**
     * shelui-engine fork: [[routeKeyParam]] (from BaseGenerator's shared
     * defaults) replaced the hardcoded 'uuid' in this stub -- a has_uuid:
     * false module's splash service now takes/passes $id, matching
     * RoutesGenerator's own {id} splash route segment for the same module.
     */
    public function test_splash_service_uses_id_parameter_when_has_uuid_false(): void
    {
        $config = $this->baseConfig(
            ['name' => 'report', 'splash' => true, 'operations' => []],
            'report'
        );
        $config['has_uuid'] = false;
        $generator = new ActionSplashServiceGenerator('Widgets', 'Core', $config, 'report', $config['actions']['report']);
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $content = file_get_contents($this->modulePath() . '/Services/WidgetsReportSplashService.php');

        $this->assertStringContainsString('public static function execute(string $id, array $data = [])', $content);
        $this->assertStringContainsString('return self::process($id, $data);', $content);
        $this->assertStringContainsString('public static function process(string $id, array $data = []): array', $content);
        $this->assertStringContainsString('/widgets/{id}/report/splash', $content);
        $this->assertStringNotContainsString('uuid', $content);
    }

    public function test_write_once_a_hand_edit_survives_a_regenerate(): void
    {
        $action = ['name' => 'report', 'serviceName' => 'WidgetsYearReportService', 'splash' => true, 'operations' => []];
        $generator = new ActionSplashServiceGenerator('Widgets', 'Core', $this->baseConfig($action, 'report'), 'report', $action);
        $generator->setForce(true);
        $generator->generate();

        $path = $this->modulePath() . '/Services/WidgetsYearReportSplashService.php';
        file_put_contents($path, "<?php\n// hand-edited\n");

        $generator2 = new ActionSplashServiceGenerator('Widgets', 'Core', $this->baseConfig($action, 'report'), 'report', $action);
        $generator2->setForce(true);
        $generator2->generate();

        $this->assertSame("<?php\n// hand-edited\n", file_get_contents($path));
    }
}
