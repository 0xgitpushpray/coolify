<?php

use App\Exceptions\DeploymentException;
use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationPreview;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeComposeApplicationWithEnvironment(string $environment): Application
{
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    $projectEnvironment = Environment::factory()->create(['project_id' => Project::factory()->create(['team_id' => $team->id])->id]);

    return Application::factory()->create([
        'environment_id' => $projectEnvironment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'build_pack' => 'dockercompose',
        'compose_parsing_version' => '5',
        'docker_compose_raw' => "services:\n  app:\n    image: nginx:alpine\n    environment:\n{$environment}",
    ]);
}

function setComposeApplicationVariable(Application $application, string $key, string $value, bool $isPreview = false): void
{
    EnvironmentVariable::query()
        ->where('resourceable_type', Application::class)
        ->where('resourceable_id', $application->id)
        ->where('key', $key)
        ->where('is_preview', $isPreview)
        ->firstOrFail()
        ->update(['value' => $value]);
}

/** @return list<string> */
function validateComposeRequiredVariables(Application $application, int $pullRequestId = 0): array
{
    $job = (new ReflectionClass(ApplicationDeploymentJob::class))->newInstanceWithoutConstructor();
    $reflection = new ReflectionClass(ApplicationDeploymentJob::class);

    $logEntries = [];
    $queue = Mockery::mock(ApplicationDeploymentQueue::class);
    $queue->shouldReceive('addLogEntry')->andReturnUsing(function (string $message) use (&$logEntries) {
        $logEntries[] = $message;

        return null;
    });

    $properties = [
        'application' => $application->fresh(),
        'application_deployment_queue' => $queue,
        'pull_request_id' => $pullRequestId,
    ];
    if ($pullRequestId !== 0) {
        $properties['preview'] = ApplicationPreview::create([
            'application_id' => $application->id,
            'pull_request_id' => $pullRequestId,
            'pull_request_html_url' => 'https://example.com/pr/'.$pullRequestId,
            'fqdn' => 'https://preview.example.com',
        ]);
    }
    foreach ($properties as $property => $value) {
        $reflection->getProperty($property)->setValue($job, $value);
    }

    $reflection->getMethod('validateRequiredComposeEnvironmentVariables')->invoke($job);

    return $logEntries;
}

it('creates a required variable without a value for a Compose required reference', function (string $reference) {
    $application = makeComposeApplicationWithEnvironment("      POSTGRES_PASSWORD: {$reference}");

    $application->parse();

    $variable = $application->environment_variables()->where('key', 'POSTGRES_PASSWORD')->sole();
    expect($variable->is_required)->toBeTruthy()
        ->and($variable->value)->toBeNull();
})->with([
    'colon question mark' => '${POSTGRES_PASSWORD:?set POSTGRES_PASSWORD}',
    'question mark' => '${POSTGRES_PASSWORD?set POSTGRES_PASSWORD}',
    'message with a hyphen' => '${POSTGRES_PASSWORD:?run the bootstrap - see DEPLOY.md}',
]);

it('keeps a value set for a required variable when the compose file is parsed again', function () {
    $application = makeComposeApplicationWithEnvironment('      POSTGRES_PASSWORD: ${POSTGRES_PASSWORD:?set POSTGRES_PASSWORD}');
    $application->parse();
    setComposeApplicationVariable($application, 'POSTGRES_PASSWORD', 'real-password');

    $application->fresh()->parse();

    expect($application->environment_variables()->where('key', 'POSTGRES_PASSWORD')->sole()->value)->toBe('real-password');
});

it('stores the text after :- as the default value of an optional variable', function () {
    $application = makeComposeApplicationWithEnvironment('      PORT: ${PORT:-3000}');

    $application->parse();

    $variable = $application->environment_variables()->where('key', 'PORT')->sole();
    expect($variable->is_required)->toBeFalsy()
        ->and($variable->value)->toBe('3000');
});

it('fails the deployment and names each required variable without a value', function () {
    $application = makeComposeApplicationWithEnvironment(<<<'YAML'
      POSTGRES_PASSWORD: ${POSTGRES_PASSWORD:?set POSTGRES_PASSWORD}
      ADMIN_EMAIL: ${ADMIN_EMAIL?set ADMIN_EMAIL}
YAML);
    $application->parse();

    expect(fn () => validateComposeRequiredVariables($application))
        ->toThrow(DeploymentException::class, 'Required environment variables are not set: POSTGRES_PASSWORD, ADMIN_EMAIL.');
});

it('deploys when every required variable has a value', function () {
    $application = makeComposeApplicationWithEnvironment('      POSTGRES_PASSWORD: ${POSTGRES_PASSWORD:?set POSTGRES_PASSWORD}');
    $application->parse();
    setComposeApplicationVariable($application, 'POSTGRES_PASSWORD', 'real-password');

    expect(validateComposeRequiredVariables($application))->toBe([]);
});

it('fails a preview deployment when the preview copy of a required variable has no value', function () {
    $application = makeComposeApplicationWithEnvironment('      POSTGRES_PASSWORD: ${POSTGRES_PASSWORD:?set POSTGRES_PASSWORD}');
    $application->parse();
    setComposeApplicationVariable($application, 'POSTGRES_PASSWORD', 'real-password');

    expect(fn () => validateComposeRequiredVariables($application, pullRequestId: 7))
        ->toThrow(DeploymentException::class, 'Required environment variables are not set: POSTGRES_PASSWORD.');
});

it('does not fail the deployment for a variable the compose file no longer requires', function () {
    $application = makeComposeApplicationWithEnvironment('      POSTGRES_PASSWORD: ${POSTGRES_PASSWORD:?set POSTGRES_PASSWORD}');
    $application->parse();
    $application->update(['docker_compose_raw' => "services:\n  app:\n    image: nginx:alpine\n"]);

    expect(validateComposeRequiredVariables($application))->toBe([]);
});

it('warns when a required variable still holds the error message from the compose file', function () {
    $application = makeComposeApplicationWithEnvironment('      POSTGRES_PASSWORD: ${POSTGRES_PASSWORD:?set POSTGRES_PASSWORD}');
    $application->parse();
    setComposeApplicationVariable($application, 'POSTGRES_PASSWORD', 'set POSTGRES_PASSWORD');

    expect(validateComposeRequiredVariables($application))->toBe([
        'Warning: POSTGRES_PASSWORD is required by the compose file, but its value is the error message from the compose file. Set the real value in the Environment Variables tab.',
    ]);
    expect($application->environment_variables()->where('key', 'POSTGRES_PASSWORD')->sole()->value)->toBe('set POSTGRES_PASSWORD');
});
