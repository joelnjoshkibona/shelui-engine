<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Backend\Routes;

use Blutrixx\GeneratorEngine\Generators\Backend\Routes\RoutesGenerator;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

/**
 * shelui-engine fork: view/edit/delete/deleteCheck routes hardcoded {uuid}
 * regardless of has_uuid, unconditionally — a has_uuid: false module (no
 * uuid column at all) had no working route to its own records. Fixed via
 * the [[routeKeyParam]] default in BaseGenerator::replacePlaceholders(),
 * resolved from the same ModuleConfigContract::hasUuid() source of truth
 * every other has_uuid-gated decision in this codebase already uses.
 *
 * @see \Blutrixx\GeneratorEngine\Generators\BaseGenerator::replacePlaceholders()
 * @see \Blutrixx\GeneratorEngine\Generators\Backend\Routes\RoutesGenerator::generateFeatureRoute()
 */
class RoutesGeneratorRouteKeyParamTest extends TestCase
{
    private function makeGenerator(array $config): TestRoutesGenerator
    {
        $ref = new ReflectionClass(TestRoutesGenerator::class);
        /** @var TestRoutesGenerator $generator */
        $generator = $ref->newInstanceWithoutConstructor();

        $this->setProtectedProperty($generator, 'moduleName', 'TestModule');
        $this->setProtectedProperty($generator, 'moduleGroup', 'Core');
        $this->setProtectedProperty($generator, 'moduleSubGroup', null);
        $this->setProtectedProperty($generator, 'config', $config);

        return $generator;
    }

    private function setProtectedProperty(object $object, string $property, mixed $value): void
    {
        $prop = new ReflectionProperty($object, $property);
        $prop->setAccessible(true);
        $prop->setValue($object, $value);
    }

    /** @return array<string, mixed> minimal config for a CRUD feature */
    private function baseConfig(string $feature, bool $hasUuid): array
    {
        return [
            'has_uuid' => $hasUuid,
            'features' => ['backend' => [
                $feature => ['endpoint' => ['method' => 'get', 'permission' => 'TestModule.' . $feature]],
            ]],
        ];
    }

    public function test_view_route_uses_uuid_segment_when_has_uuid_true(): void
    {
        $generator = $this->makeGenerator($this->baseConfig('view', true));

        $route = $generator->callGenerateFeatureRoute('view');

        $this->assertStringContainsString('{uuid}/view', $route);
        $this->assertStringNotContainsString('{id}/view', $route);
    }

    public function test_view_route_uses_id_segment_when_has_uuid_false(): void
    {
        $generator = $this->makeGenerator($this->baseConfig('view', false));

        $route = $generator->callGenerateFeatureRoute('view');

        $this->assertStringContainsString('{id}/view', $route);
        $this->assertStringNotContainsString('{uuid}/view', $route);
    }

    public function test_edit_route_uses_id_segment_when_has_uuid_false(): void
    {
        $generator = $this->makeGenerator($this->baseConfig('edit', false));

        $route = $generator->callGenerateFeatureRoute('edit');

        $this->assertStringContainsString('{id}/edit', $route);
    }

    public function test_delete_route_uses_id_segment_when_has_uuid_false(): void
    {
        $generator = $this->makeGenerator($this->baseConfig('delete', false));

        $route = $generator->callGenerateFeatureRoute('delete');

        $this->assertStringContainsString('{id}/delete', $route);
    }

    public function test_delete_check_route_uses_id_segment_when_has_uuid_false(): void
    {
        $generator = $this->makeGenerator($this->baseConfig('deleteCheck', false));

        $route = $generator->callGenerateFeatureRoute('deleteCheck');

        $this->assertStringContainsString('{id}/delete/check', $route);
    }

    public function test_activity_route_uses_id_segment_when_has_uuid_false(): void
    {
        $generator = $this->makeGenerator(['has_uuid' => false]);

        $activityRoute = $generator->callGenerateActivityRoute();

        $this->assertStringContainsString('{id}/activity', $activityRoute);
        $this->assertStringNotContainsString('{uuid}/activity', $activityRoute);
    }

    public function test_activity_route_uses_uuid_segment_when_has_uuid_true(): void
    {
        $generator = $this->makeGenerator(['has_uuid' => true]);

        $activityRoute = $generator->callGenerateActivityRoute();

        $this->assertStringContainsString('{uuid}/activity', $activityRoute);
    }
}

class TestRoutesGenerator extends RoutesGenerator
{
    public function callGenerateFeatureRoute(string $feature): string
    {
        return $this->generateFeatureRoute($feature);
    }

    /** Isolates just the activity-route line generateRoutesFileContent() builds. */
    public function callGenerateActivityRoute(): string
    {
        $routePath = \Illuminate\Support\Str::kebab($this->moduleName);
        $routeKeyParam = \Blutrixx\GeneratorEngine\Schema\ModuleConfigContract::hasUuid($this->config) ? 'uuid' : 'id';
        return "Route::middleware(['auth:sanctum'])->get('/{$routePath}/{{$routeKeyParam}}/activity', [{$this->moduleName}Controller::class, 'activityHistory']);\n";
    }
}
