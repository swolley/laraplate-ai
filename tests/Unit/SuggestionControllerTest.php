<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Modules\AI\Http\Controllers\SuggestionController;
use Modules\AI\Http\Requests\GenerateSuggestionRequest;
use Modules\AI\Models\ContextualSuggestion;
use Modules\AI\Services\ContextualSuggestionService;
use Modules\Core\Models\User;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

it('listSuggestions returns unauthorized when no user', function (): void {
    Auth::shouldReceive('user')->andReturn(null);

    $service = Mockery::mock(ContextualSuggestionService::class);
    $service->shouldNotReceive('getPendingSuggestions');

    $request = new Request;
    $controller = new SuggestionController($service);
    $response = $controller->listSuggestions($request);

    expect($response->getStatusCode())->toBe(Response::HTTP_UNAUTHORIZED);
});

it('listSuggestions returns suggestions for user', function (): void {
    Auth::shouldReceive('user')->andReturn($this->user);

    $suggestion = ContextualSuggestion::query()->create([
        'user_id' => $this->user->id,
        'suggestion' => 'Try this',
        'context' => [],
    ]);

    $service = Mockery::mock(ContextualSuggestionService::class);
    $service->shouldReceive('getPendingSuggestions')
        ->once()
        ->with($this->user)
        ->andReturn(ContextualSuggestion::query()->where('id', $suggestion->id)->get());

    $request = new Request;
    $controller = new SuggestionController($service);
    $response = $controller->listSuggestions($request);

    $data = $response->getData(true);
    expect($response->getStatusCode())->toBe(200)
        ->and($data['data'])->toHaveCount(1);
});

it('generateSuggestion returns unauthorized when no user', function (): void {
    Auth::shouldReceive('user')->andReturn(null);

    $service = Mockery::mock(ContextualSuggestionService::class);
    $service->shouldNotReceive('generateSuggestion');

    $request = Mockery::mock(GenerateSuggestionRequest::class);

    $controller = new SuggestionController($service);
    $response = $controller->generateSuggestion($request);

    expect($response->getStatusCode())->toBe(Response::HTTP_UNAUTHORIZED);
});

it('generateSuggestion returns suggestion', function (): void {
    Auth::shouldReceive('user')->andReturn($this->user);

    $suggestion = ContextualSuggestion::query()->create([
        'user_id' => $this->user->id,
        'suggestion' => 'Generated',
        'context' => ['page' => 'home'],
    ]);

    $service = Mockery::mock(ContextualSuggestionService::class);
    $service->shouldReceive('generateSuggestion')
        ->once()
        ->with($this->user, ['page' => 'home'])
        ->andReturn($suggestion);

    $request = Mockery::mock(GenerateSuggestionRequest::class);
    $request->shouldReceive('validated')->andReturn(['context' => ['page' => 'home']]);

    $controller = new SuggestionController($service);
    $response = $controller->generateSuggestion($request);

    $data = $response->getData(true);
    expect($response->getStatusCode())->toBe(Response::HTTP_CREATED)
        ->and($data['data']['suggestion'])->toBe('Generated');
});

it('generateSuggestion returns null when no suggestion generated', function (): void {
    Auth::shouldReceive('user')->andReturn($this->user);

    $service = Mockery::mock(ContextualSuggestionService::class);
    $service->shouldReceive('generateSuggestion')
        ->once()
        ->with($this->user, [])
        ->andReturn(null);

    $request = Mockery::mock(GenerateSuggestionRequest::class);
    $request->shouldReceive('validated')->andReturn(['context' => []]);

    $controller = new SuggestionController($service);
    $response = $controller->generateSuggestion($request);

    $data = $response->getData(true);
    expect($response->getStatusCode())->toBe(200)
        ->and($data['data'])->toBeNull();
});

it('dismissSuggestion returns unauthorized when no user', function (): void {
    Auth::shouldReceive('user')->andReturn(null);

    $suggestion = ContextualSuggestion::query()->create([
        'user_id' => $this->user->id,
        'suggestion' => 'Test',
        'context' => [],
    ]);

    $service = Mockery::mock(ContextualSuggestionService::class);
    $service->shouldNotReceive('dismissSuggestion');

    $request = new Request;
    $controller = new SuggestionController($service);
    $response = $controller->dismissSuggestion($request, $suggestion);

    expect($response->getStatusCode())->toBe(Response::HTTP_UNAUTHORIZED);
});

it('dismissSuggestion returns forbidden for other user suggestion', function (): void {
    $otherUser = User::factory()->create();
    Auth::shouldReceive('user')->andReturn($otherUser);

    $suggestion = ContextualSuggestion::query()->create([
        'user_id' => $this->user->id,
        'suggestion' => 'Test',
        'context' => [],
    ]);

    $service = Mockery::mock(ContextualSuggestionService::class);
    $service->shouldNotReceive('dismissSuggestion');

    $request = new Request;
    $controller = new SuggestionController($service);
    $response = $controller->dismissSuggestion($request, $suggestion);

    expect($response->getStatusCode())->toBe(Response::HTTP_FORBIDDEN);
});

it('dismissSuggestion dismisses own suggestion', function (): void {
    Auth::shouldReceive('user')->andReturn($this->user);

    $suggestion = ContextualSuggestion::query()->create([
        'user_id' => $this->user->id,
        'suggestion' => 'Test',
        'context' => [],
    ]);

    $service = Mockery::mock(ContextualSuggestionService::class);
    $service->shouldReceive('dismissSuggestion')->once()->with($suggestion);

    $request = new Request;
    $controller = new SuggestionController($service);
    $response = $controller->dismissSuggestion($request, $suggestion);

    expect($response->getStatusCode())->toBe(200);
});
