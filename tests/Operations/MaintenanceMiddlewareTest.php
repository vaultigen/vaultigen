<?php

use App\Http\Middleware\BlockWritesDuringMaintenance;
use App\Services\Operations\MaintenanceWindow;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

it('allows safe and inactive requests but blocks writes during maintenance', function (): void {
    $middleware = app(BlockWritesDuringMaintenance::class);

    expect($middleware->handle(Request::create('/x', 'POST'), fn () => response('ok'))->getContent())->toBe('ok');
    expect($middleware->handle(Request::create('/x', 'GET'), fn () => response('ok'))->getContent())->toBe('ok');

    app(MaintenanceWindow::class)->begin('backup', null, 'test');

    try {
        $middleware->handle(Request::create('/x', 'POST'), fn () => response('ok'));
        $this->fail('Expected a 503 while the maintenance window is open.');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(503);
    }
});

it('applies the middleware on a live route', function (): void {
    $this->post(route('invitation.store'))->assertStatus(302);

    app(MaintenanceWindow::class)->begin('backup', null, 'test');
    $this->post(route('invitation.store'))->assertStatus(503);
});
