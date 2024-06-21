<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\ContractController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\GuarantorController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|


*/
Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});


Route::post('/auth/register', [UserController::class, 'createUser']);

Route::post('/auth/login', [UserController::class, 'loginUser']);

Route::group([
    "middleware" => ["auth:sanctum"]
], function(){

    Route::get("logout", [UserController::class, 'logoutUser']);

    Route::get("profile", [UserController::class, 'profile']);

    Route::post('customers', [CustomerController::class, 'addCustomer']);

    Route::get('customers', [CustomerController::class, 'index']);

    Route::get('customers/{id}', [CustomerController::class, 'findCustomer']);

    //Route::get('customers/{id}/edit', [CustomerController::class, 'editCustomer']);

    Route::put('customers/{id}/edit', [CustomerController::class, 'updateCustomer']);

    Route::get('customers/search/{nic}', [CustomerController::class, 'searchCustomer']);

    Route::put('customers/{id}/editContracts', [CustomerController::class, 'updateCustomerContracts']);

    //Payments

    Route::get('auth/payments/{contract_no}', [PaymentController::class, 'findPaymentByContract']);

    Route::post('payments', [PaymentController::class, 'addPayment']);

    //Gurantors

    Route::get('guarantors/{contractId}', [GuarantorController::class, 'searchByContract']);

    Route::post('guarantors', [GuarantorController::class, 'addGuarantor']);

    //Contracts

    Route::get('contracts', [ContractController::class, 'index']);

    Route::post("contract", [ContractController::class, 'addContract']);

    Route::post('contracts/add', [ContractController::class, 'addContract']);

    Route::get('contracts/{id}', [ContractController::class, 'editContract']);

    Route::put('contracts/{id}/edit', [ContractController::class, 'updateContract']);

    Route::get('contracts/search/{contract_no}', [ContractController::class, 'findContract']);
});

