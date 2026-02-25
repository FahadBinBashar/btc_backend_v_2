<?php

use App\Http\Controllers\C1Controller;
use App\Http\Controllers\Rev12ApiTestController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});
Route::get('/rev12/tester', function () {
    return view('rev12-tester');
});

Route::get('/get-token', [C1Controller::class, 'getToken']);
Route::get('/subscriber', [C1Controller::class, 'subscriberRetrieve']);
Route::post('/c1/subscriber-update', [C1Controller::class, 'c1SubscriberUpdate']);
Route::post('/c1/account-update', [C1Controller::class, 'c1AccountUpdate']);
Route::post('/c1/address-update', [C1Controller::class, 'c1AddressUpdate']);
Route::post('/c1/persona-update', [C1Controller::class, 'c1PersonaUpdate']);

Route::prefix('rev12')->group(function () {
    Route::get('/security/token', [Rev12ApiTestController::class, 'securityToken']);

    Route::post('/billing/subscriber-retrieve', [Rev12ApiTestController::class, 'subscriberRetrieve']);
    Route::post('/billing/subscriber-resume', [Rev12ApiTestController::class, 'subscriberResume']);
    Route::post('/billing/subscriber-suspend', [Rev12ApiTestController::class, 'subscriberSuspend']);
    Route::post('/billing/subscriber-update', [Rev12ApiTestController::class, 'subscriberUpdate']);
    Route::post('/billing/account-update', [Rev12ApiTestController::class, 'accountUpdate']);
    Route::post('/billing/address-update', [Rev12ApiTestController::class, 'addressUpdate']);
    Route::post('/billing/persona-update', [Rev12ApiTestController::class, 'personaUpdate']);
    Route::post('/billing/update-rating-status', [Rev12ApiTestController::class, 'updateRatingStatus']);

    Route::post('/bocra/check-msisdn', [Rev12ApiTestController::class, 'bocraCheckMsisdn']);
    Route::post('/bocra/check-document', [Rev12ApiTestController::class, 'bocraCheckDocument']);
    Route::post('/bocra/register', [Rev12ApiTestController::class, 'bocraRegister']);
    Route::match(['post', 'patch'], '/bocra/update-subscriber', [Rev12ApiTestController::class, 'bocraUpdateSubscriber']);
    Route::match(['post', 'patch'], '/bocra/update-address-docs', [Rev12ApiTestController::class, 'bocraUpdateAddressDocuments']);

    Route::post('/smega/check', [Rev12ApiTestController::class, 'smegaCheck']);
    Route::post('/smega/register', [Rev12ApiTestController::class, 'smegaRegister']);

    Route::post('/logging/transaction', [Rev12ApiTestController::class, 'logTransaction']);
});
