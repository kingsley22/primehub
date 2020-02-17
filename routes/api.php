<?php
use Illuminate\Support\Facades\Cache;

use Illuminate\Http\Request;

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

Route::middleware('auth:api')->get('/user', function (Request $request) {
    return $request->user();
});

Route::get('/wallet_status', "HostsController@walletStatus");
Route::get('/hosts/{mode}', "HostsController@index");
Route::get('/map', "HostsController@map");
Route::get('/versions', "HostsController@versions");
Route::get('/countries', "HostsController@countries");
Route::get('/continents', "HostsController@continents");
Route::get('/network', "HostsController@network");
Route::get('/host/{id}', "HostsController@host");
Route::get('/scprime/ticker', function () {
    if (Cache::has('cmcticker')) {
        return Cache::get('cmcticker');
    } else {
        $cmc = file_get_contents('https://api.coingecko.com/api/v3/coins/siaprime-coin');
        $cmc = json_decode($cmc, true);
        $cmc['market_data']['current_price']['btc'] = number_format( $cmc['market_data']['current_price']['btc'], 10 );

        Cache::put('cmcticker', $cmc, 10);

        return $cmc;
    }
});

Route::get('/scprime/release', function () {
    if (!Cache::has('scprimerelease')) {
        try {
            $client = new \GuzzleHttp\Client();
            $res = $client->request('GET', 'https://gitlab.com/api/v4/projects/10135403/releases');
            $response = json_decode($res->getBody(), true);
            Cache::put('scprimerelease', $response[0], 24*60);
        } catch(Exception $e) {
        }
    }

    return Cache::get('scprimerelease');
});

Route::get('/settings/recommended', "HostsController@recommendedSettings");
